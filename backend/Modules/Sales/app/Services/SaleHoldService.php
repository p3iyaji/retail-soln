<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Modules\Customers\Models\Customer;
use Modules\Customers\Services\CustomerService;
use Modules\Organization\Models\Store;
use Modules\Sales\Enums\SaleStatus;
use Modules\Sales\Models\Sale;
use RuntimeException;

class SaleHoldService
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly SaleLineCalculator $lineCalculator,
    ) {}

    /**
     * @param  array{
     *   organizationId: int,
     *   storeId: int,
     *   userId: int,
     *   uuid: string,
     *   customerId?: ?int,
     *   notes?: ?string,
     *   items: list<array{variantId: int, qty: string, unitPrice: string, discount: string}>,
     * }  $input
     */
    public function hold(array $input): Sale
    {
        if ($input['items'] === []) {
            throw new RuntimeException('Held sale requires at least one item.');
        }

        $store = Store::query()
            ->where('organization_id', $input['organizationId'])
            ->whereKey($input['storeId'])
            ->first();

        if ($store === null) {
            throw new RuntimeException('Store not found.');
        }

        $customer = $this->resolveCustomer($input['organizationId'], $input['customerId'] ?? null);
        $computed = $this->lineCalculator->compute($store, $input['items']);

        return DB::transaction(function () use ($input, $store, $customer, $computed) {
            $sale = Sale::query()->create([
                'organization_id' => $input['organizationId'],
                'store_id' => $store->id,
                'uuid' => $input['uuid'],
                'customer_id' => $customer->id,
                'cashier_id' => $input['userId'],
                'subtotal' => $computed['subtotal'],
                'discount_total' => $computed['discount_total'],
                'tax_total' => $computed['tax_total'],
                'total' => $computed['total'],
                'status' => SaleStatus::Held,
                'notes' => $input['notes'] ?? null,
            ]);

            foreach ($computed['lines'] as $line) {
                $sale->items()->create([
                    'organization_id' => $input['organizationId'],
                    'product_variant_id' => $line['variant_id'],
                    'qty' => $line['qty'],
                    'unit_price' => $line['unit_price'],
                    'discount' => $line['discount'],
                    'tax_amount' => $line['tax_amount'],
                    'line_total' => $line['line_total'],
                ]);
            }

            return $sale->load(['items.productVariant', 'customer', 'store']);
        });
    }

    private function resolveCustomer(int $organizationId, ?int $customerId): Customer
    {
        if ($customerId === null) {
            return $this->customers->ensureWalkIn($organizationId);
        }

        $customer = Customer::query()
            ->where('organization_id', $organizationId)
            ->whereKey($customerId)
            ->first();

        if ($customer === null) {
            throw new RuntimeException('Customer not found.');
        }

        return $customer;
    }
}
