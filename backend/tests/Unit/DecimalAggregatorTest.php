<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\DecimalAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\Customers\Services\CustomerService;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Sales\Enums\SaleStatus;
use Modules\Sales\Models\Sale;
use Tests\TestCase;

class DecimalAggregatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_count_and_sum_on_sqlite_matches_bcmath_totals(): void
    {
        $this->seed(PermissionMatrixSeeder::class);
        $orgId = app(OrganizationService::class)->current()->id;
        $store = Store::factory()->create(['organization_id' => $orgId]);
        $cashier = User::factory()->cashier()->create();
        $customerId = app(CustomerService::class)->ensureWalkIn($orgId)->id;

        Sale::query()->create([
            'organization_id' => $orgId,
            'store_id' => $store->id,
            'uuid' => fake()->uuid(),
            'customer_id' => $customerId,
            'cashier_id' => $cashier->id,
            'subtotal' => '10.0000',
            'discount_total' => '0.0000',
            'tax_total' => '0.0000',
            'total' => '10.0000',
            'status' => SaleStatus::Completed,
        ]);

        Sale::query()->create([
            'organization_id' => $orgId,
            'store_id' => $store->id,
            'uuid' => fake()->uuid(),
            'customer_id' => $customerId,
            'cashier_id' => $cashier->id,
            'subtotal' => '5.5000',
            'discount_total' => '0.0000',
            'tax_total' => '0.0000',
            'total' => '5.5000',
            'status' => SaleStatus::Completed,
        ]);

        $query = Sale::query()
            ->where('organization_id', $orgId)
            ->where('store_id', $store->id)
            ->where('status', SaleStatus::Completed);

        $result = DecimalAggregator::countAndSum($query, 'total');

        $this->assertSame(2, $result['count']);
        $this->assertSame('15.5000', $result['sum']);
    }
}
