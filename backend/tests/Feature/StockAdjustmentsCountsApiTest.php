<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Tests\TestCase;

class StockAdjustmentsCountsApiTest extends TestCase
{
    use RefreshDatabase;

    private int $orgId;

    private Store $store;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionMatrixSeeder::class);
        $this->orgId = app(OrganizationService::class)->current()->id;
        $this->store = Store::factory()->create(['organization_id' => $this->orgId]);

        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'sku' => 'ADJ-P1',
        ]);

        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->orgId,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'ADJ-V1',
            'price' => '10.0000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    public function test_inventory_manager_can_post_adjustment_with_reason_and_user(): void
    {
        $user = User::factory()->inventoryManager()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/inventory/adjustments', [
            'storeId' => $this->store->id,
            'productVariantId' => $this->variant->id,
            'quantity' => '12.5000',
            'reason' => 'Found stock in back room',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.quantity', '12.5000')
            ->assertJsonPath('data.reason', 'Found stock in back room')
            ->assertJsonPath('data.userId', $user->id);

        $this->assertDatabaseHas('stock_adjustments', [
            'product_variant_id' => $this->variant->id,
            'reason' => 'Found stock in back room',
            'user_id' => $user->id,
        ]);

        $movement = InventoryMovement::query()->first();
        $this->assertNotNull($movement);
        $this->assertSame(MovementType::Adjustment, $movement->type);
        $this->assertSame('Found stock in back room', $movement->notes);
        $this->assertSame($user->id, $movement->user_id);
        $this->assertSame('stock_adjustment', $movement->reference_type);

        $balance = app(InventoryLedgerService::class)->balanceFor(
            $this->orgId,
            LocationType::Store->value,
            $this->store->id,
            $this->variant->id,
        );
        $this->assertSame('12.5000', $balance?->available);
    }

    public function test_stock_count_variance_posts_count_variance_movement(): void
    {
        $user = User::factory()->inventoryManager()->create();
        Sanctum::actingAs($user);

        app(InventoryLedgerService::class)->post(new PostMovementData(
            organizationId: $this->orgId,
            locationType: LocationType::Store,
            locationId: $this->store->id,
            productVariantId: $this->variant->id,
            type: MovementType::Purchase,
            quantity: '10.0000',
        ));

        $response = $this->postJson('/api/v1/inventory/counts', [
            'storeId' => $this->store->id,
            'notes' => 'Monthly count',
            'items' => [
                [
                    'productVariantId' => $this->variant->id,
                    'countedQuantity' => '8.0000',
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.items.0.systemQuantity', '10.0000')
            ->assertJsonPath('data.items.0.countedQuantity', '8.0000')
            ->assertJsonPath('data.items.0.variance', '-2.0000');

        $this->assertNotNull($response->json('data.items.0.inventoryMovementId'));

        $variance = InventoryMovement::query()
            ->where('type', MovementType::CountVariance->value)
            ->first();

        $this->assertNotNull($variance);
        $this->assertSame('-2.0000', $variance->quantity);
        $this->assertSame($user->id, $variance->user_id);
        $this->assertSame('stock_count', $variance->reference_type);

        $balance = app(InventoryLedgerService::class)->balanceFor(
            $this->orgId,
            LocationType::Store->value,
            $this->store->id,
            $this->variant->id,
        );
        $this->assertSame('8.0000', $balance?->available);
    }

    public function test_stock_count_with_zero_variance_does_not_create_movement(): void
    {
        Sanctum::actingAs(User::factory()->inventoryManager()->create());

        app(InventoryLedgerService::class)->post(new PostMovementData(
            organizationId: $this->orgId,
            locationType: LocationType::Store,
            locationId: $this->store->id,
            productVariantId: $this->variant->id,
            type: MovementType::Purchase,
            quantity: '5.0000',
        ));

        $this->postJson('/api/v1/inventory/counts', [
            'storeId' => $this->store->id,
            'items' => [
                [
                    'productVariantId' => $this->variant->id,
                    'countedQuantity' => '5.0000',
                ],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.items.0.variance', '0.0000')
            ->assertJsonPath('data.items.0.inventoryMovementId', null);

        $this->assertSame(1, InventoryMovement::query()->count());
    }

    public function test_cashier_cannot_post_adjustments(): void
    {
        Sanctum::actingAs(User::factory()->cashier()->create());

        $this->postJson('/api/v1/inventory/adjustments', [
            'storeId' => $this->store->id,
            'productVariantId' => $this->variant->id,
            'quantity' => '1.0000',
            'reason' => 'Nope',
        ])->assertForbidden();
    }
}
