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

class StockTransferApiTest extends TestCase
{
    use RefreshDatabase;

    private int $orgId;

    private Store $source;

    private Store $destination;

    private ProductVariant $variant;

    private InventoryLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionMatrixSeeder::class);
        $this->ledger = app(InventoryLedgerService::class);
        $this->orgId = app(OrganizationService::class)->current()->id;
        $this->source = Store::factory()->create(['organization_id' => $this->orgId, 'name' => 'Source']);
        $this->destination = Store::factory()->create(['organization_id' => $this->orgId, 'name' => 'Destination']);

        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'sku' => 'XFER-P1',
        ]);

        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->orgId,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'XFER-V1',
            'price' => '10.0000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->ledger->post(new PostMovementData(
            organizationId: $this->orgId,
            locationType: LocationType::Store,
            locationId: $this->source->id,
            productVariantId: $this->variant->id,
            type: MovementType::Purchase,
            quantity: '20.0000',
        ));
    }

    public function test_initiate_and_receive_transfer_tracks_in_transit_and_movements(): void
    {
        $user = User::factory()->inventoryManager()->create();
        Sanctum::actingAs($user);

        $create = $this->postJson('/api/v1/inventory/transfers', [
            'fromStoreId' => $this->source->id,
            'toStoreId' => $this->destination->id,
            'notes' => 'Restock dest',
            'items' => [
                [
                    'productVariantId' => $this->variant->id,
                    'quantity' => '7.0000',
                ],
            ],
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.status', 'in_transit')
            ->assertJsonPath('data.items.0.quantity', '7.0000');

        $transferId = $create->json('data.id');

        $sourceBalance = $this->ledger->balanceFor(
            $this->orgId,
            LocationType::Store->value,
            $this->source->id,
            $this->variant->id,
        );
        $this->assertSame('13.0000', $sourceBalance?->available);
        $this->assertSame('7.0000', $sourceBalance?->in_transit);

        $this->assertSame(
            2,
            InventoryMovement::query()->where('type', MovementType::TransferOut->value)->where('reference_id', $transferId)->count()
        );

        $receive = $this->postJson("/api/v1/inventory/transfers/{$transferId}/receive");

        $receive->assertOk()
            ->assertJsonPath('data.status', 'received')
            ->assertJsonPath('data.receivedBy', $user->id);

        $sourceAfter = $this->ledger->balanceFor(
            $this->orgId,
            LocationType::Store->value,
            $this->source->id,
            $this->variant->id,
        );
        $destAfter = $this->ledger->balanceFor(
            $this->orgId,
            LocationType::Store->value,
            $this->destination->id,
            $this->variant->id,
        );

        $this->assertSame('13.0000', $sourceAfter?->available);
        $this->assertSame('0.0000', $sourceAfter?->in_transit);
        $this->assertSame('7.0000', $destAfter?->available);

        $this->assertTrue(
            InventoryMovement::query()
                ->where('type', MovementType::TransferIn->value)
                ->where('reference_id', $transferId)
                ->exists()
        );
    }

    public function test_cannot_transfer_more_than_available(): void
    {
        Sanctum::actingAs(User::factory()->inventoryManager()->create());

        $this->postJson('/api/v1/inventory/transfers', [
            'fromStoreId' => $this->source->id,
            'toStoreId' => $this->destination->id,
            'items' => [
                [
                    'productVariantId' => $this->variant->id,
                    'quantity' => '50.0000',
                ],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Insufficient available stock at source location.');
    }

    public function test_cashier_cannot_transfer(): void
    {
        Sanctum::actingAs(User::factory()->cashier()->create());

        $this->postJson('/api/v1/inventory/transfers', [
            'fromStoreId' => $this->source->id,
            'toStoreId' => $this->destination->id,
            'items' => [
                [
                    'productVariantId' => $this->variant->id,
                    'quantity' => '1.0000',
                ],
            ],
        ])->assertForbidden();
    }
}
