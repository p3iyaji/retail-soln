<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\CustomerPortal\Models\CustomerAccount;
use Modules\CustomerPortal\Models\PortalOrder;
use Modules\CustomerPortal\Support\PortalOrderStatuses;
use Modules\Customers\Enums\CustomerType;
use Modules\Customers\Models\Customer;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Modules\Sales\Models\Sale;
use Modules\Sync\Models\InventoryOversellException;
use Tests\TestCase;

class ReportingDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Store $store;

    private Store $otherStore;

    private ProductVariant $lowStockVariant;

    private ProductVariant $stockedVariant;

    private User $manager;

    private User $orgAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionMatrixSeeder::class);

        $this->organization = app(OrganizationService::class)->current();
        $this->organization->forceFill(['timezone' => 'UTC', 'default_currency' => 'NGN'])->save();

        $noTax = ['rate' => '0.0000', 'inclusive' => false, 'name' => 'None'];
        $this->store = Store::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Dashboard Store',
            'tax_config' => $noTax,
        ]);
        $this->otherStore = Store::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Other Store',
            'tax_config' => $noTax,
        ]);

        $this->lowStockVariant = $this->makeVariant('RPT-LOW', '10.0000');
        $this->stockedVariant = $this->makeVariant('RPT-OK', '10.0000');

        // Available (3) is at/below the factory reorder level (5) => one low-stock row for this store.
        $this->seedStock($this->store, $this->lowStockVariant, '3.0000');
        $this->seedStock($this->store, $this->stockedVariant, '500.0000');
        $this->seedStock($this->otherStore, $this->stockedVariant, '500.0000');

        $this->manager = User::factory()->storeManager()->create();
        $this->manager->stores()->attach($this->store->id, ['is_default' => true]);

        $this->orgAdmin = User::factory()->orgAdmin()->create();
    }

    private function makeVariant(string $sku, string $price): ProductVariant
    {
        $product = Product::factory()->create([
            'organization_id' => $this->organization->id,
            'sku' => $sku,
        ]);

        return ProductVariant::query()->create([
            'organization_id' => $this->organization->id,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => $sku.'-V',
            'price' => $price,
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function seedStock(Store $store, ProductVariant $variant, string $qty): void
    {
        app(InventoryLedgerService::class)->post(new PostMovementData(
            organizationId: $this->organization->id,
            locationType: LocationType::Store,
            locationId: $store->id,
            productVariantId: $variant->id,
            type: MovementType::Adjustment,
            quantity: $qty,
            userId: $this->orgAdmin?->id ?? User::factory()->inventoryManager()->create()->id,
            notes: 'Dashboard fixture',
        ));
    }

    /**
     * @return array<string, string>
     */
    private function headers(?Store $store = null): array
    {
        return ['X-Store-Id' => (string) ($store ?? $this->store)->id];
    }

    private function openSession(User $user, Store $store, string $terminal): int
    {
        Sanctum::actingAs($user, ['staff']);

        return (int) $this->postJson('/api/v1/pos/sessions/open', [
            'terminalName' => $terminal,
            'openingFloat' => '0.0000',
        ], $this->headers($store))->assertCreated()->json('data.id');
    }

    private function completeSale(
        User $user,
        Store $store,
        int $sessionId,
        string $unitPrice,
        string $idempotencyKey,
    ): int {
        Sanctum::actingAs($user, ['staff']);

        return (int) $this->postJson('/api/v1/pos/sales', [
            'uuid' => (string) Str::uuid(),
            'sessionId' => $sessionId,
            'items' => [[
                'variantId' => $this->stockedVariant->id,
                'qty' => '1.0000',
                'unitPrice' => $unitPrice,
                'discount' => '0.0000',
            ]],
            'payments' => [['method' => 'cash', 'amount' => $unitPrice, 'provider' => 'manual']],
        ], array_merge($this->headers($store), ['Idempotency-Key' => $idempotencyKey]))
            ->assertCreated()
            ->json('data.id');
    }

    private function makePortalOrder(Store $store, string $status, string $paymentStatus = 'paid'): PortalOrder
    {
        $customer = Customer::query()->create([
            'organization_id' => $this->organization->id,
            'type' => CustomerType::Registered,
            'name' => 'Portal Shopper '.Str::random(6),
            'account_number' => 'CUST-'.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);

        $account = CustomerAccount::query()->create([
            'organization_id' => $this->organization->id,
            'customer_id' => $customer->id,
            'email' => Str::lower(Str::random(10)).'@example.com',
            'password' => 'password',
            'email_verified_at' => now(),
        ]);

        return PortalOrder::query()->create([
            'organization_id' => $this->organization->id,
            'customer_account_id' => $account->id,
            'customer_id' => $customer->id,
            'uuid' => (string) Str::uuid(),
            'order_number' => 'PO-'.Str::upper(Str::random(8)),
            'status' => $status,
            'payment_status' => $paymentStatus,
            'fulfilment_mode' => 'store_pickup',
            'store_id' => $store->id,
            'subtotal' => '10.0000',
            'discount_total' => '0.0000',
            'tax_total' => '0.0000',
            'delivery_fee' => '0.0000',
            'total' => '10.0000',
            'paid_at' => $paymentStatus === 'paid' ? now() : null,
        ]);
    }

    public function test_dashboard_returns_store_scoped_kpis(): void
    {
        $sessionId = $this->openSession($this->manager, $this->store, 'RPT-1');
        $this->completeSale($this->manager, $this->store, $sessionId, '25.0000', 'rpt-sale-1');
        $this->completeSale($this->manager, $this->store, $sessionId, '10.5000', 'rpt-sale-2');

        $staleSaleId = $this->completeSale($this->manager, $this->store, $sessionId, '99.0000', 'rpt-sale-stale');
        Sale::query()->whereKey($staleSaleId)->update(['created_at' => now()->subDays(2)]);

        $otherSessionId = $this->openSession($this->orgAdmin, $this->otherStore, 'RPT-OTHER');
        $this->completeSale($this->orgAdmin, $this->otherStore, $otherSessionId, '77.0000', 'rpt-sale-other');

        $this->makePortalOrder($this->store, PortalOrderStatuses::CONFIRMED);
        $this->makePortalOrder($this->store, PortalOrderStatuses::READY_FOR_PICKUP);
        $this->makePortalOrder($this->store, PortalOrderStatuses::DELIVERED);
        $this->makePortalOrder($this->store, PortalOrderStatuses::CONFIRMED, paymentStatus: 'awaiting_payment');
        $this->makePortalOrder($this->otherStore, PortalOrderStatuses::CONFIRMED);

        InventoryOversellException::query()->create([
            'organization_id' => $this->organization->id,
            'store_id' => $this->store->id,
            'sale_id' => $staleSaleId,
            'product_variant_id' => $this->stockedVariant->id,
            'qty_deficit' => '2.0000',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($this->manager, ['staff']);
        $this->postJson('/api/v1/pos/sales/hold', [
            'uuid' => (string) Str::uuid(),
            'customerId' => null,
            'items' => [[
                'variantId' => $this->stockedVariant->id,
                'qty' => '1.0000',
                'unitPrice' => '10.0000',
                'discount' => '0.0000',
            ]],
        ], $this->headers())->assertCreated();

        $this->getJson('/api/v1/reports/dashboard', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.store.id', $this->store->id)
            ->assertJsonPath('data.store.name', 'Dashboard Store')
            ->assertJsonPath('data.timezone', 'UTC')
            ->assertJsonPath('data.currency', 'NGN')
            ->assertJsonPath('data.date', now('UTC')->toDateString())
            ->assertJsonPath('data.todaySalesTotal', '35.5000')
            ->assertJsonPath('data.todaySalesCount', 2)
            ->assertJsonPath('data.pendingOrdersCount', 2)
            ->assertJsonPath('data.lowStockCount', 1)
            ->assertJsonPath('data.openRegistersCount', 1)
            ->assertJsonPath('data.needsAttention.pendingOversellApprovals', 1)
            ->assertJsonPath('data.needsAttention.readyForPickupOrders', 1)
            ->assertJsonPath('data.needsAttention.heldSalesCount', 1);
    }

    public function test_dashboard_returns_zeroes_when_store_has_no_activity(): void
    {
        Sanctum::actingAs($this->orgAdmin, ['staff']);

        $this->getJson('/api/v1/reports/dashboard', $this->headers($this->otherStore))
            ->assertOk()
            ->assertJsonPath('data.todaySalesTotal', '0.0000')
            ->assertJsonPath('data.todaySalesCount', 0)
            ->assertJsonPath('data.pendingOrdersCount', 0)
            ->assertJsonPath('data.lowStockCount', 0)
            ->assertJsonPath('data.openRegistersCount', 0)
            ->assertJsonPath('data.needsAttention.pendingOversellApprovals', 0)
            ->assertJsonPath('data.needsAttention.readyForPickupOrders', 0)
            ->assertJsonPath('data.needsAttention.heldSalesCount', 0);
    }

    public function test_pending_orders_include_processing_and_out_for_delivery(): void
    {
        $this->makePortalOrder($this->store, PortalOrderStatuses::PROCESSING);
        $this->makePortalOrder($this->store, PortalOrderStatuses::OUT_FOR_DELIVERY);

        Sanctum::actingAs($this->manager, ['staff']);

        $this->getJson('/api/v1/reports/dashboard', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.pendingOrdersCount', 2)
            ->assertJsonPath('data.needsAttention.readyForPickupOrders', 0);
    }

    public function test_held_sales_count_is_null_without_sales_view(): void
    {
        Sanctum::actingAs($this->manager, ['staff']);
        $this->postJson('/api/v1/pos/sales/hold', [
            'uuid' => (string) Str::uuid(),
            'customerId' => null,
            'items' => [[
                'variantId' => $this->stockedVariant->id,
                'qty' => '1.0000',
                'unitPrice' => '10.0000',
                'discount' => '0.0000',
            ]],
        ], $this->headers())->assertCreated();

        $inventoryManager = User::factory()->inventoryManager()->create();
        $inventoryManager->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($inventoryManager, ['staff']);

        $this->getJson('/api/v1/reports/dashboard', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.needsAttention.heldSalesCount', null);
    }

    public function test_sales_are_bucketed_by_organization_calendar_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-15 01:00:00', 'UTC'));

        $sessionId = $this->openSession($this->manager, $this->store, 'RPT-TZ');
        $saleId = $this->completeSale($this->manager, $this->store, $sessionId, '40.0000', 'rpt-sale-tz');

        // 2026-03-14 20:00 UTC is still 2026-03-15 in UTC+14, but yesterday in UTC.
        Sale::query()->whereKey($saleId)->update(['created_at' => '2026-03-14 20:00:00']);

        Sanctum::actingAs($this->manager, ['staff']);
        $this->getJson('/api/v1/reports/dashboard', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.timezone', 'UTC')
            ->assertJsonPath('data.date', '2026-03-15')
            ->assertJsonPath('data.todaySalesTotal', '0.0000');

        $this->organization->forceFill(['timezone' => 'Pacific/Kiritimati'])->save();

        $this->getJson('/api/v1/reports/dashboard', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.timezone', 'Pacific/Kiritimati')
            ->assertJsonPath('data.date', '2026-03-15')
            ->assertJsonPath('data.todaySalesTotal', '40.0000')
            ->assertJsonPath('data.todaySalesCount', 1);
    }

    public function test_low_stock_count_is_null_without_inventory_view(): void
    {
        $accountant = User::factory()->create(['role' => 'accountant']);
        $accountant->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($accountant, ['staff']);

        $this->getJson('/api/v1/reports/dashboard', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.lowStockCount', null)
            ->assertJsonPath('data.needsAttention.pendingOversellApprovals', null)
            ->assertJsonPath('data.needsAttention.heldSalesCount', 0);
    }

    public function test_dashboard_requires_reports_view_permission(): void
    {
        $cashier = User::factory()->cashier()->create();
        $cashier->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($cashier, ['staff']);

        $this->getJson('/api/v1/reports/dashboard', $this->headers())->assertForbidden();
    }

    public function test_dashboard_requires_store_header(): void
    {
        Sanctum::actingAs($this->manager, ['staff']);

        $this->getJson('/api/v1/reports/dashboard')->assertStatus(422);
        $this->getJson('/api/v1/reports/dashboard', ['X-Store-Id' => 'abc'])->assertStatus(422);
    }

    public function test_dashboard_rejects_store_outside_user_access(): void
    {
        Sanctum::actingAs($this->manager, ['staff']);

        $this->getJson('/api/v1/reports/dashboard', $this->headers($this->otherStore))
            ->assertForbidden();
    }

    public function test_dashboard_rejects_unknown_store_in_org(): void
    {
        Sanctum::actingAs($this->orgAdmin, ['staff']);

        $this->getJson('/api/v1/reports/dashboard', ['X-Store-Id' => '999999'])
            ->assertStatus(422);
    }

    public function test_dashboard_rejects_guests_and_portal_tokens(): void
    {
        $this->getJson('/api/v1/reports/dashboard', $this->headers())->assertUnauthorized();

        $order = $this->makePortalOrder($this->store, PortalOrderStatuses::CONFIRMED);
        $account = CustomerAccount::query()->findOrFail($order->customer_account_id);

        Sanctum::actingAs($account, ['portal']);
        $this->getJson('/api/v1/reports/dashboard', $this->headers())->assertUnauthorized();
    }
}
