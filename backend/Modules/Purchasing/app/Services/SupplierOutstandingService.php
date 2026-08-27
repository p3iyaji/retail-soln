<?php

namespace Modules\Purchasing\Services;

use Modules\Purchasing\Models\SupplierInvoice;
use Modules\Purchasing\Models\SupplierPayment;

class SupplierOutstandingService
{
    /**
     * Computed outstanding: Σ invoice totals − Σ payments (org + supplier scoped).
     * Never invent a mutable balance table. Never use float Eloquent sum().
     */
    public function outstandingForSupplier(int $organizationId, int $supplierId): string
    {
        $invoiceTotal = '0.0000';
        foreach (
            SupplierInvoice::query()
                ->where('organization_id', $organizationId)
                ->where('supplier_id', $supplierId)
                ->pluck('total_amount') as $amount
        ) {
            $invoiceTotal = bcadd($invoiceTotal, $this->normalizeDecimal($amount), 4);
        }

        $paymentTotal = '0.0000';
        foreach (
            SupplierPayment::query()
                ->where('organization_id', $organizationId)
                ->where('supplier_id', $supplierId)
                ->pluck('amount') as $amount
        ) {
            $paymentTotal = bcadd($paymentTotal, $this->normalizeDecimal($amount), 4);
        }

        $outstanding = bcsub($invoiceTotal, $paymentTotal, 4);

        return bccomp($outstanding, '0', 4) < 0 ? '0.0000' : $outstanding;
    }

    private function normalizeDecimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.0000';
        }

        return bcadd((string) $value, '0', 4);
    }
}
