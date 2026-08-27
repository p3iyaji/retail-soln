<?php

namespace Modules\Purchasing\Services;

use Modules\Purchasing\Models\PurchaseReturn;
use Modules\Purchasing\Models\SupplierInvoice;
use Modules\Purchasing\Models\SupplierPayment;

class SupplierOutstandingService
{
    /**
     * Computed outstanding: Σ invoice totals − Σ payments − Σ return credits (org + supplier scoped).
     * Never invent a mutable balance table. Never use float Eloquent sum().
     * May be negative when return credits exceed net AP (supplier credit).
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

        $creditTotal = '0.0000';
        foreach (
            PurchaseReturn::query()
                ->where('organization_id', $organizationId)
                ->where('supplier_id', $supplierId)
                ->pluck('credit_amount') as $amount
        ) {
            $creditTotal = bcadd($creditTotal, $this->normalizeDecimal($amount), 4);
        }

        return bcsub(bcsub($invoiceTotal, $paymentTotal, 4), $creditTotal, 4);
    }

    private function normalizeDecimal(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.0000';
        }

        return bcadd((string) $value, '0', 4);
    }
}
