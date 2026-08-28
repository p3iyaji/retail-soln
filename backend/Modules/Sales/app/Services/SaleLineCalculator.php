<?php

namespace Modules\Sales\Services;

use Modules\Organization\Models\Store;
use Modules\Pricing\Services\TaxService;
use Modules\Products\Models\ProductVariant;
use RuntimeException;

class SaleLineCalculator
{
    public function __construct(private readonly TaxService $tax) {}

    /**
     * @param  list<array{variantId: int, qty: string, unitPrice: string, discount: string}>  $items
     * @return array{
     *   lines: list<array{
     *     variant_id: int,
     *     qty: string,
     *     unit_price: string,
     *     discount: string,
     *     tax_amount: string,
     *     line_total: string,
     *     net: string,
     *   }>,
     *   qtyByVariant: array<int, string>,
     *   subtotal: string,
     *   discount_total: string,
     *   tax_total: string,
     *   total: string,
     * }
     */
    public function compute(Store $store, array $items): array
    {
        $lines = [];
        $qtyByVariant = [];
        $subtotal = '0.0000';
        $discountTotal = '0.0000';
        $taxTotal = '0.0000';
        $total = '0.0000';

        foreach ($items as $item) {
            $variant = ProductVariant::query()
                ->where('organization_id', $store->organization_id)
                ->whereKey($item['variantId'])
                ->first();

            if ($variant === null) {
                throw new RuntimeException('Variant not found.');
            }

            if (! $variant->is_active) {
                throw new RuntimeException('Variant is inactive.');
            }

            $qty = bcadd((string) $item['qty'], '0', 4);
            $unitPrice = bcadd((string) $item['unitPrice'], '0', 4);
            $discount = bcadd((string) $item['discount'], '0', 4);

            if (bccomp($qty, '0', 4) <= 0) {
                throw new RuntimeException('Quantity must be greater than zero.');
            }

            $lineNet = bcsub(bcmul($qty, $unitPrice, 8), $discount, 8);
            if (bccomp($lineNet, '0', 4) < 0) {
                $lineNet = '0.0000';
            }

            $taxResult = $this->tax->apply($store, $lineNet);

            $lines[] = [
                'variant_id' => $variant->id,
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'discount' => $discount,
                'tax_amount' => $taxResult['amount'],
                'line_total' => $taxResult['gross'],
                'net' => $taxResult['net'],
            ];

            $qtyByVariant[$variant->id] = bcadd($qtyByVariant[$variant->id] ?? '0.0000', $qty, 4);
            $subtotal = bcadd($subtotal, $taxResult['net'], 4);
            $discountTotal = bcadd($discountTotal, $discount, 4);
            $taxTotal = bcadd($taxTotal, $taxResult['amount'], 4);
            $total = bcadd($total, $taxResult['gross'], 4);
        }

        return [
            'lines' => $lines,
            'qtyByVariant' => $qtyByVariant,
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'tax_total' => $taxTotal,
            'total' => $total,
        ];
    }
}
