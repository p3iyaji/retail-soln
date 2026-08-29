<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Modules\Organization\Models\Store;
use Modules\Pricing\Services\PricingService;
use Modules\Sales\Enums\ProformaConvertTarget;
use Modules\Sales\Enums\ProformaInvoiceStatus;
use Modules\Sales\Models\Invoice;
use Modules\Sales\Models\ProformaInvoice;
use Modules\Sales\Models\Sale;
use RuntimeException;

class ProformaConversionService
{
    public function __construct(
        private readonly ProformaInvoiceService $proformas,
        private readonly SaleCompletionService $sales,
        private readonly SaleLineCalculator $lineCalculator,
        private readonly PricingService $pricing,
    ) {}

    /**
     * @param  array{
     *   target: string,
     *   uuid?: string,
     *   sessionId?: int,
     *   terminalId?: ?int,
     *   payments?: list<array{method: string, amount: string, provider: string, reference?: ?string}>,
     *   installmentPlan?: ?array{
     *     mode: string,
     *     installments: list<array{sequence: int, amount: string, dueAt: string}>,
     *   },
     *   idempotencyKey: string,
     * }  $input
     * @return array{proforma: ProformaInvoice, sale: ?Sale, invoice: ?Invoice}
     */
    public function convert(ProformaInvoice $proforma, int $userId, array $input, bool $honorPrices): array
    {
        $target = ProformaConvertTarget::tryFrom($input['target'] ?? '');
        if ($target === null) {
            throw new RuntimeException('Invalid conversion target.');
        }

        $this->proformas->refreshExpiry($proforma);

        if ($proforma->status === ProformaInvoiceStatus::Converted) {
            throw new RuntimeException('This proforma invoice has already been converted.');
        }

        if (! $proforma->status->isConvertible()) {
            throw new RuntimeException('Only issued or accepted proforma invoices can be converted.');
        }

        $proforma->loadMissing(['items.productVariant', 'store', 'customer']);

        if ($proforma->items->isEmpty()) {
            throw new RuntimeException('Proforma invoice requires at least one line item.');
        }

        $saleItems = $this->buildSaleItems($proforma, $honorPrices);

        return DB::transaction(function () use ($proforma, $userId, $input, $target, $saleItems) {
            $locked = ProformaInvoice::query()->whereKey($proforma->id)->lockForUpdate()->firstOrFail();
            $this->proformas->refreshExpiry($locked);

            if ($locked->status === ProformaInvoiceStatus::Converted) {
                throw new RuntimeException('This proforma invoice has already been converted.');
            }

            if (! $locked->status->isConvertible()) {
                throw new RuntimeException('Only issued or accepted proforma invoices can be converted.');
            }

            $sale = null;
            $invoice = null;

            if ($target->createsSale()) {
                if (($input['uuid'] ?? '') === '') {
                    throw new RuntimeException('Sale conversion requires a client uuid.');
                }

                if (! isset($input['sessionId'])) {
                    throw new RuntimeException('Sale conversion requires an open cashier session.');
                }

                if (($input['payments'] ?? []) === []) {
                    throw new RuntimeException('Sale conversion requires at least one payment.');
                }

                $result = $this->sales->complete([
                    'organizationId' => $locked->organization_id,
                    'storeId' => $locked->store_id,
                    'userId' => $userId,
                    'uuid' => $input['uuid'],
                    'customerId' => $locked->customer_id,
                    'sessionId' => (int) $input['sessionId'],
                    'terminalId' => $input['terminalId'] ?? null,
                    'idempotencyKey' => $input['idempotencyKey'],
                    'items' => $saleItems,
                    'payments' => $input['payments'],
                    'installmentPlan' => $input['installmentPlan'] ?? null,
                ]);

                $sale = $result['sale'];
                $sale->loadMissing('invoice');
                $invoice = $sale->invoice;
            } else {
                $invoice = $this->createStandaloneInvoice($locked, $saleItems);
            }

            $locked->update([
                'status' => ProformaInvoiceStatus::Converted,
                'converted_sale_id' => $sale?->id,
                'converted_invoice_id' => $invoice?->id,
            ]);

            return [
                'proforma' => $locked->fresh([
                    'customer', 'store', 'items.productVariant', 'creator', 'issuer',
                    'convertedSale.invoice', 'convertedInvoice',
                ]),
                'sale' => $sale,
                'invoice' => $invoice,
            ];
        });
    }

    /**
     * @return list<array{variantId: int, qty: string, unitPrice: string, discount: string}>
     */
    private function buildSaleItems(ProformaInvoice $proforma, bool $honorPrices): array
    {
        $items = [];

        foreach ($proforma->items as $line) {
            if ($honorPrices) {
                $items[] = [
                    'variantId' => $line->product_variant_id,
                    'qty' => bcadd((string) $line->qty, '0', 4),
                    'unitPrice' => bcadd((string) $line->unit_price, '0', 4),
                    'discount' => bcadd((string) $line->discount, '0', 4),
                ];

                continue;
            }

            $resolved = $this->pricing->resolve(
                $proforma->organization_id,
                $line->product_variant_id,
                $proforma->store_id,
                bcadd((string) $line->qty, '0', 4),
                $proforma->customer_id,
            );

            $items[] = [
                'variantId' => $line->product_variant_id,
                'qty' => bcadd((string) $line->qty, '0', 4),
                'unitPrice' => $resolved['listPrice'],
                'discount' => $resolved['discountAmount'],
            ];
        }

        return $items;
    }

    /**
     * @param  list<array{variantId: int, qty: string, unitPrice: string, discount: string}>  $saleItems
     */
    private function createStandaloneInvoice(ProformaInvoice $proforma, array $saleItems): Invoice
    {
        $store = Store::query()
            ->where('organization_id', $proforma->organization_id)
            ->whereKey($proforma->store_id)
            ->firstOrFail();

        $computed = $this->lineCalculator->compute($store, $saleItems);

        $invoice = Invoice::query()->create([
            'organization_id' => $proforma->organization_id,
            'sale_id' => null,
            'proforma_invoice_id' => $proforma->id,
            'invoice_number' => $this->nextStandaloneInvoiceNumber($proforma->organization_id, $proforma->store_id),
            'status' => 'issued',
            'subtotal' => $computed['subtotal'],
            'tax_total' => $computed['tax_total'],
            'total' => $computed['total'],
            'issued_at' => now(),
        ]);

        foreach ($computed['lines'] as $line) {
            $invoice->items()->create([
                'organization_id' => $proforma->organization_id,
                'product_variant_id' => $line['variant_id'],
                'qty' => $line['qty'],
                'unit_price' => $line['unit_price'],
                'discount' => $line['discount'],
                'tax_amount' => $line['tax_amount'],
                'line_total' => $line['line_total'],
            ]);
        }

        return $invoice->fresh(['items.productVariant']);
    }

    private function nextStandaloneInvoiceNumber(int $organizationId, int $storeId): string
    {
        $count = Invoice::query()
            ->where('organization_id', $organizationId)
            ->whereNull('sale_id')
            ->lockForUpdate()
            ->count();

        return sprintf('TI-%d-%04d', $storeId, $count + 1);
    }
}
