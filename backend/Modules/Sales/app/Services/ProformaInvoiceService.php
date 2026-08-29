<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Modules\Customers\Models\Customer;
use Modules\Organization\Models\Store;
use Modules\Sales\Enums\ProformaInvoiceStatus;
use Modules\Sales\Jobs\SendProformaInvoice;
use Modules\Sales\Models\ProformaInvoice;
use RuntimeException;

class ProformaInvoiceService
{
    public function __construct(private readonly SaleLineCalculator $lineCalculator) {}

    /**
     * @param  array{
     *   storeId: int,
     *   customerId: int,
     *   notes?: ?string,
     *   validUntil?: ?string,
     *   items: list<array{variantId: int, qty: string, unitPrice: string, discount: string}>,
     * }  $input
     */
    public function create(int $organizationId, int $userId, array $input): ProformaInvoice
    {
        return DB::transaction(function () use ($organizationId, $userId, $input) {
            $store = $this->resolveStore($organizationId, $input['storeId']);
            $customer = $this->resolveCustomer($organizationId, $input['customerId']);
            $computed = $this->lineCalculator->compute($store, $input['items']);

            $proforma = ProformaInvoice::query()->create([
                'organization_id' => $organizationId,
                'store_id' => $store->id,
                'customer_id' => $customer->id,
                'number' => null,
                'status' => ProformaInvoiceStatus::Draft,
                'subtotal' => $computed['subtotal'],
                'discount_total' => $computed['discount_total'],
                'tax_total' => $computed['tax_total'],
                'total' => $computed['total'],
                'valid_until' => $input['validUntil'] ?? null,
                'notes' => $input['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $this->syncItems($proforma, $organizationId, $computed['lines']);

            return $proforma->fresh(['items.productVariant', 'customer', 'store', 'creator']);
        });
    }

    /**
     * @param  array{
     *   notes?: ?string,
     *   validUntil?: ?string,
     *   items: list<array{variantId: int, qty: string, unitPrice: string, discount: string}>,
     * }  $input
     */
    public function update(ProformaInvoice $proforma, array $input): ProformaInvoice
    {
        $this->assertEditable($proforma);

        return DB::transaction(function () use ($proforma, $input) {
            $store = $this->resolveStore($proforma->organization_id, $proforma->store_id);
            $computed = $this->lineCalculator->compute($store, $input['items']);

            $proforma->update([
                'subtotal' => $computed['subtotal'],
                'discount_total' => $computed['discount_total'],
                'tax_total' => $computed['tax_total'],
                'total' => $computed['total'],
                'valid_until' => $input['validUntil'] ?? $proforma->valid_until?->toDateString(),
                'notes' => array_key_exists('notes', $input) ? $input['notes'] : $proforma->notes,
            ]);

            $proforma->items()->delete();
            $this->syncItems($proforma, $proforma->organization_id, $computed['lines']);

            return $proforma->fresh(['items.productVariant', 'customer', 'store', 'creator', 'issuer']);
        });
    }

    public function issue(ProformaInvoice $proforma, int $userId): ProformaInvoice
    {
        $this->refreshExpiry($proforma);

        if ($proforma->status !== ProformaInvoiceStatus::Draft) {
            throw new RuntimeException('Only draft proforma invoices can be issued.');
        }

        if ($proforma->items()->count() === 0) {
            throw new RuntimeException('Proforma invoice requires at least one line item.');
        }

        return DB::transaction(function () use ($proforma, $userId) {
            $locked = ProformaInvoice::query()->whereKey($proforma->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ProformaInvoiceStatus::Draft) {
                throw new RuntimeException('Only draft proforma invoices can be issued.');
            }

            $number = $locked->number ?? $this->nextNumber($locked->organization_id, $locked->store_id);

            $locked->update([
                'number' => $number,
                'status' => ProformaInvoiceStatus::Issued,
                'issued_by' => $userId,
                'issued_at' => now(),
            ]);

            SendProformaInvoice::dispatch($locked->id);

            return $locked->fresh(['items.productVariant', 'customer', 'store', 'creator', 'issuer']);
        });
    }

    public function cancel(ProformaInvoice $proforma): ProformaInvoice
    {
        $this->refreshExpiry($proforma);

        if (! $proforma->status->isCancellable()) {
            throw new RuntimeException('This proforma invoice cannot be cancelled.');
        }

        $proforma->update(['status' => ProformaInvoiceStatus::Cancelled]);

        return $proforma->fresh(['items.productVariant', 'customer', 'store', 'creator', 'issuer']);
    }

    public function refreshExpiry(ProformaInvoice $proforma): void
    {
        if (
            $proforma->valid_until !== null
            && $proforma->valid_until->isPast()
            && in_array($proforma->status, [ProformaInvoiceStatus::Draft, ProformaInvoiceStatus::Issued, ProformaInvoiceStatus::Accepted], true)
        ) {
            $proforma->update(['status' => ProformaInvoiceStatus::Expired]);
            $proforma->refresh();
        }
    }

    private function nextNumber(int $organizationId, int $storeId): string
    {
        $count = ProformaInvoice::query()
            ->where('organization_id', $organizationId)
            ->where('store_id', $storeId)
            ->whereNotNull('number')
            ->lockForUpdate()
            ->count();

        return sprintf('PF-%d-%04d', $storeId, $count + 1);
    }

    private function resolveStore(int $organizationId, int $storeId): Store
    {
        $store = Store::query()
            ->where('organization_id', $organizationId)
            ->whereKey($storeId)
            ->first();

        if ($store === null) {
            throw new RuntimeException('Store not found.');
        }

        return $store;
    }

    private function resolveCustomer(int $organizationId, int $customerId): Customer
    {
        $customer = Customer::query()
            ->where('organization_id', $organizationId)
            ->whereKey($customerId)
            ->first();

        if ($customer === null) {
            throw new RuntimeException('Customer not found.');
        }

        if ($customer->is_walk_in) {
            throw new RuntimeException('Proforma invoices require a registered customer.');
        }

        if (! $customer->is_active) {
            throw new RuntimeException('Customer is inactive.');
        }

        return $customer;
    }

    private function assertEditable(ProformaInvoice $proforma): void
    {
        $this->refreshExpiry($proforma);

        if (! $proforma->status->isEditable()) {
            throw new RuntimeException('This proforma invoice cannot be edited.');
        }
    }

    /**
     * @param  list<array{
     *   variant_id: int,
     *   qty: string,
     *   unit_price: string,
     *   discount: string,
     *   tax_amount: string,
     *   line_total: string,
     * }>  $lines
     */
    private function syncItems(ProformaInvoice $proforma, int $organizationId, array $lines): void
    {
        foreach ($lines as $line) {
            $proforma->items()->create([
                'organization_id' => $organizationId,
                'product_variant_id' => $line['variant_id'],
                'qty' => $line['qty'],
                'unit_price' => $line['unit_price'],
                'discount' => $line['discount'],
                'tax_amount' => $line['tax_amount'],
                'line_total' => $line['line_total'],
            ]);
        }
    }
}
