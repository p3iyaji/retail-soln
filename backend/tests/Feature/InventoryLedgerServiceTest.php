<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\BalanceBucket;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Models\InventoryBalance;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Tests\TestCase;

class InventoryLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryLedgerService $ledger;

    private int $orgId;

    private Store $store;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionMatrixSeeder::class);
        $this->ledger = app(InventoryLedgerService::class);
        $this->orgId = app(OrganizationService::class)->current()->id;
        $this->store = Store::factory()->create(['organization_id' => $this->orgId]);

        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'sku' => 'LEDGER-P1',
        ]);

        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->orgId,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'LEDGER-V1',
            'price' => '10.0000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    public function test_post_creates_movement_and_materializes_balance(): void
    {
        $movement = $this->ledger->post(new PostMovementData(
            organizationId: $this->orgId,
            locationType: LocationType::Store,
            locationId: $this->store->id,
            productVariantId: $this->variant->id,
            type: MovementType::Purchase,
            quantity: '25.5000',
            unitCost: '3.2500',
            referenceType: 'goods_receipt',
            referenceId: 99,
            userId: User::factory()->inventoryManager()->create()->id,
            notes: 'Initial stock',
        ));

        $this->assertSame('purchase', $movement->type->value);
        $this->assertSame('25.5000', $movement->quantity);
        $this->assertSame('3.2500', $movement->unit_cost);
        $this->assertSame('goods_receipt', $movement->reference_type);
        $this->assertSame(99, $movement->reference_id);
        $this->assertSame('25.5000', $movement->balance_after);

        $balance = $this->ledger->balanceFor(
            $this->orgId,
            LocationType::Store->value,
            $this->store->id,
            $this->variant->id,
        );

        $this->assertNotNull($balance);
        $this->assertSame('25.5000', $balance->available);
    }

    public function test_negative_movement_decreases_available_balance(): void
    {
        $this->ledger->post(new PostMovementData(
            organizationId: $this->orgId,
            locationType: LocationType::Store,
            locationId: $this->store->id,
            productVariantId: $this->variant->id,
            type: MovementType::Purchase,
            quantity: '10.0000',
        ));

        $sale = $this->ledger->post(new PostMovementData(
            organizationId: $this->orgId,
            locationType: LocationType::Store,
            locationId: $this->store->id,
            productVariantId: $this->variant->id,
            type: MovementType::Sale,
            quantity: '-3.0000',
            referenceType: 'sale',
            referenceId: 1,
        ));

        $this->assertSame('7.0000', $sale->balance_after);

        $balance = $this->ledger->balanceFor(
            $this->orgId,
            LocationType::Store->value,
            $this->store->id,
            $this->variant->id,
        );

        $this->assertSame('7.0000', $balance?->available);
    }

    public function test_uuid_idempotency_returns_same_movement_without_double_posting(): void
    {
        $uuid = '11111111-1111-4111-8111-111111111111';

        $first = $this->ledger->post(new PostMovementData(
            organizationId: $this->orgId,
            locationType: LocationType::Store,
            locationId: $this->store->id,
            productVariantId: $this->variant->id,
            type: MovementType::Adjustment,
            quantity: '5.0000',
            uuid: $uuid,
        ));

        $second = $this->ledger->post(new PostMovementData(
            organizationId: $this->orgId,
            locationType: LocationType::Store,
            locationId: $this->store->id,
            productVariantId: $this->variant->id,
            type: MovementType::Adjustment,
            quantity: '5.0000',
            uuid: $uuid,
        ));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, InventoryMovement::query()->count());

        $balance = $this->ledger->balanceFor(
            $this->orgId,
            LocationType::Store->value,
            $this->store->id,
            $this->variant->id,
        );

        $this->assertSame('5.0000', $balance?->available);
    }

    public function test_direct_balance_quantity_mutation_is_prohibited(): void
    {
        $this->ledger->post(new PostMovementData(
            organizationId: $this->orgId,
            locationType: LocationType::Store,
            locationId: $this->store->id,
            productVariantId: $this->variant->id,
            type: MovementType::Adjustment,
            quantity: '1.0000',
        ));

        $balance = InventoryBalance::query()->firstOrFail();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('AD-2');

        $balance->available = '999.0000';
        $balance->save();
    }

    public function test_inventory_manager_can_list_balances_and_movements(): void
    {
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        $this->ledger->post(new PostMovementData(
            organizationId: $this->orgId,
            locationType: LocationType::Store,
            locationId: $this->store->id,
            productVariantId: $this->variant->id,
            type: MovementType::Purchase,
            quantity: '4.0000',
            bucket: BalanceBucket::Available,
        ));

        $this->getJson('/api/v1/inventory/balances?storeId='.$this->store->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.available', '4.0000')
            ->assertJsonPath('data.0.productVariantId', $this->variant->id);

        $this->getJson('/api/v1/inventory/movements?storeId='.$this->store->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.type', 'purchase')
            ->assertJsonPath('data.0.quantity', '4.0000');
    }

    public function test_user_without_inventory_view_cannot_list_balances(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'delivery_staff']), ['staff']);

        $this->getJson('/api/v1/inventory/balances')->assertForbidden();
    }
}
