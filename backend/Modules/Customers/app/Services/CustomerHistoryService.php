<?php

namespace Modules\Customers\Services;

use Modules\Customers\Models\Customer;
use Modules\Sales\Enums\SaleStatus;
use Modules\Sales\Models\InstallmentPlan;
use Modules\Sales\Models\Invoice;
use Modules\Sales\Models\Sale;
use Modules\Sales\Models\SalePayment;
use Modules\Sales\Models\SaleReturn;

class CustomerHistoryService
{
    /**
     * @return array{
     *   customerId: int,
     *   sales: list<array<string, mixed>>,
     *   invoices: list<array<string, mixed>>,
     *   payments: list<array<string, mixed>>,
     *   returns: list<array<string, mixed>>,
     *   installmentPlans: list<array<string, mixed>>,
     * }
     */
    public function forCustomer(Customer $customer): array
    {
        $organizationId = $customer->organization_id;

        $sales = Sale::query()
            ->where('organization_id', $organizationId)
            ->where('customer_id', $customer->id)
            ->where('status', SaleStatus::Completed)
            ->orderByDesc('id')
            ->limit(25)
            ->get(['id', 'store_id', 'total', 'status', 'created_at']);

        $saleIds = $sales->pluck('id')->all();

        $invoices = Invoice::query()
            ->where('organization_id', $organizationId)
            ->where(function ($query) use ($customer, $saleIds): void {
                if ($saleIds !== []) {
                    $query->whereIn('sale_id', $saleIds);
                }
                $query->orWhereHas('proformaInvoice', fn ($q) => $q->where('customer_id', $customer->id));
            })
            ->orderByDesc('id')
            ->limit(25)
            ->get(['id', 'sale_id', 'invoice_number', 'status', 'total', 'issued_at']);

        $payments = SalePayment::query()
            ->where('organization_id', $organizationId)
            ->whereHas('sale', fn ($q) => $q->where('customer_id', $customer->id))
            ->orderByDesc('id')
            ->limit(25)
            ->get(['id', 'sale_id', 'method', 'amount', 'created_at']);

        $returns = SaleReturn::query()
            ->where('organization_id', $organizationId)
            ->whereHas('sale', fn ($q) => $q->where('customer_id', $customer->id))
            ->orderByDesc('id')
            ->limit(25)
            ->get(['id', 'sale_id', 'total', 'reason', 'created_at']);

        $installmentPlans = InstallmentPlan::query()
            ->where('organization_id', $organizationId)
            ->where('customer_id', $customer->id)
            ->orderByDesc('id')
            ->limit(25)
            ->get(['id', 'sale_id', 'principal', 'status', 'created_at']);

        return [
            'customerId' => $customer->id,
            'sales' => $sales->map(fn (Sale $sale) => [
                'id' => $sale->id,
                'storeId' => $sale->store_id,
                'total' => $sale->total,
                'status' => $sale->status?->value ?? $sale->status,
                'createdAt' => $sale->created_at?->toIso8601String(),
            ])->values()->all(),
            'invoices' => $invoices->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'saleId' => $invoice->sale_id,
                'invoiceNumber' => $invoice->invoice_number,
                'status' => $invoice->status,
                'total' => $invoice->total,
                'issuedAt' => $invoice->issued_at?->toIso8601String(),
            ])->values()->all(),
            'payments' => $payments->map(fn (SalePayment $payment) => [
                'id' => $payment->id,
                'saleId' => $payment->sale_id,
                'method' => $payment->method,
                'amount' => $payment->amount,
                'createdAt' => $payment->created_at?->toIso8601String(),
            ])->values()->all(),
            'returns' => $returns->map(fn (SaleReturn $return) => [
                'id' => $return->id,
                'saleId' => $return->sale_id,
                'total' => $return->total,
                'reason' => $return->reason?->value ?? $return->reason,
                'createdAt' => $return->created_at?->toIso8601String(),
            ])->values()->all(),
            'installmentPlans' => $installmentPlans->map(fn (InstallmentPlan $plan) => [
                'id' => $plan->id,
                'saleId' => $plan->sale_id,
                'principal' => $plan->principal,
                'status' => $plan->status?->value ?? $plan->status,
                'createdAt' => $plan->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
