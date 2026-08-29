<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Tests\TestCase;

class LowStockAlertsApiTest extends TestCase
{
    use RefreshDatabase;

    private int $orgId;

    private Store $store;

    private Product $product;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionMatrixSeeder::class);
        $this->orgId = app(OrganizationService::class)->current()->id;
        $this->store = Store::factory()->create(['organization_id' => $this->orgId]);

        $this->product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'name' => 'Tee',
            'sku' => 'TEE',
            'reorder_level' => '5.0000',
        ]);

        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->orgId,
            'product_id' => $this->product->id,
            'name' => 'Red M',
            'sku' => 'TEE-RED-M',
            'price' => '10.0000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    public function test_lists_alert_when_available_at_or_below_reorder_level(): void
    {
        $this->actAsInventoryManager();
        $this->postStock('2.0000');

        $response = $this->getJson('/api/v1/inventory/alerts/low-stock?storeId='.$this->store->id);

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.sku', 'TEE-RED-M')
            ->assertJsonPath('data.0.productName', 'Tee')
            ->assertJsonPath('data.0.available', '2.0000')
            ->assertJsonPath('data.0.reorderLevel', '5.0000')
            ->assertJsonPath('data.0.severity', 'warning')
            ->assertJsonPath('data.0.locationType', 'store')
            ->assertJsonPath('data.0.locationId', $this->store->id)
            ->assertJsonPath('data.0.productVariantId', $this->variant->id);
    }

    public function test_lists_alert_when_available_equals_reorder_level(): void
    {
        $this->actAsInventoryManager();
        $this->postStock('5.0000');

        $this->getJson('/api/v1/inventory/alerts/low-stock?storeId='.$this->store->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.available', '5.0000')
            ->assertJsonPath('data.0.reorderLevel', '5.0000')
            ->assertJsonPath('data.0.severity', 'warning');
    }

    public function test_excludes_balance_above_reorder_level(): void
    {
        $this->actAsInventoryManager();
        $this->postStock('6.0000');

        $this->getJson('/api/v1/inventory/alerts/low-stock?storeId='.$this->store->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_excludes_products_without_reorder_level(): void
    {
        $this->actAsInventoryManager();
        $this->product->update(['reorder_level' => null]);
        $this->postStock('1.0000');

        $this->getJson('/api/v1/inventory/alerts/low-stock?storeId='.$this->store->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_zero_available_is_critical(): void
    {
        $this->actAsInventoryManager();
        $this->postStock('1.0000');
        $this->adjustStock('-1.0000');

        $this->getJson('/api/v1/inventory/alerts/low-stock?storeId='.$this->store->id)
            ->assertOk()
            ->assertJsonPath('data.0.severity', 'critical')
            ->assertJsonPath('data.0.available', '0.0000');
    }

    public function test_negative_available_is_critical(): void
    {
        $this->actAsInventoryManager();
        $this->postStock('1.0000');
        $this->adjustStock('-3.0000');

        $this->getJson('/api/v1/inventory/alerts/low-stock?storeId='.$this->store->id)
            ->assertOk()
            ->assertJsonPath('data.0.severity', 'critical')
            ->assertJsonPath('data.0.available', '-2.0000');
    }

    public function test_rejects_combined_store_and_warehouse_filters(): void
    {
        $this->actAsInventoryManager();

        $this->getJson('/api/v1/inventory/alerts/low-stock?storeId=1&warehouseId=1')
            ->assertUnprocessable();
    }

    public function test_forbidden_without_inventory_view(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'accountant']), ['staff']);

        $this->getJson('/api/v1/inventory/alerts/low-stock?storeId='.$this->store->id)
            ->assertForbidden();
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/v1/inventory/alerts/low-stock?storeId='.$this->store->id)
            ->assertUnauthorized();
    }

    public function test_check_low_stock_command_reports_rows(): void
    {
        $this->postStock('1.0000');

        $this->artisan('inventory:check-low-stock')
            ->assertSuccessful()
            ->expectsOutputToContain('Total: 1')
            ->expectsOutputToContain('TEE-RED-M');
    }

    public function test_low_stock_command_is_scheduled(): void
    {
        $this->artisan('schedule:list')
            ->assertSuccessful()
            ->expectsOutputToContain('inventory:check-low-stock');
    }

    private function actAsInventoryManager(): User
    {
        $user = User::factory()->inventoryManager()->create();
        $user->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($user, ['staff']);

        return $user;
    }

    private function postStock(string $quantity): void
    {
        app(InventoryLedgerService::class)->post(new PostMovementData(
            organizationId: $this->orgId,
            locationType: LocationType::Store,
            locationId: $this->store->id,
            productVariantId: $this->variant->id,
            type: MovementType::Purchase,
            quantity: $quantity,
        ));
    }

    private function adjustStock(string $quantity): void
    {
        app(InventoryLedgerService::class)->post(new PostMovementData(
            organizationId: $this->orgId,
            locationType: LocationType::Store,
            locationId: $this->store->id,
            productVariantId: $this->variant->id,
            type: MovementType::Adjustment,
            quantity: $quantity,
        ));
    }
}
