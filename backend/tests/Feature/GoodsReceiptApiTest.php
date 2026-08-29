<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Audit\Models\AuditLog;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Modules\Purchasing\Models\GoodsReceipt;
use Modules\Purchasing\Models\PurchaseOrderItem;
use Modules\Purchasing\Models\Supplier;
use Tests\TestCase;

class GoodsReceiptApiTest extends TestCase
{
    use RefreshDatabase;

    private int $orgId;

    private Store $store;

    private Supplier $supplier;

    private ProductVariant $variant;

    private ProductVariant $variantB;

    private InventoryLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionMatrixSeeder::class);
        $this->ledger = app(InventoryLedgerService::class);
        $this->orgId = app(OrganizationService::class)->current()->id;
        $this->store = Store::factory()->create([
            'organization_id' => $this->orgId,
            'name' => 'Receive Store',
        ]);
        $this->supplier = Supplier::query()->create([
            'organization_id' => $this->orgId,
            'name' => 'Acme Supply',
            'code' => 'ACME',
            'is_active' => true,
        ]);

        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'sku' => 'GRN-P1',
        ]);

        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->orgId,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'GRN-V1',
            'price' => '10.0000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $productB = Product::factory()->create([
            'organization_id' => $this->orgId,
            'sku' => 'GRN-P2',
        ]);

        $this->variantB = ProductVariant::query()->create([
            'organization_id' => $this->orgId,
            'product_id' => $productB->id,
            'name' => 'Alt',
            'sku' => 'GRN-V2',
            'price' => '12.0000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    /**
     * @param  list<array{productVariantId: int, qty: string, unitCost: string}>|null  $items
     * @return array{poId: int, itemIds: list<int>}
     */
    private function createApprovedPo(?array $items = null): array
    {
        $items ??= [
            [
                'productVariantId' => $this->variant->id,
                'qty' => '10.0000',
                'unitCost' => '2.5000',
            ],
        ];

        $creator = User::factory()->procurementOfficer()->create();
        Sanctum::actingAs($creator, ['staff']);

        $poId = $this->postJson('/api/v1/purchase-orders', [
            'storeId' => $this->store->id,
            'supplierId' => $this->supplier->id,
            'notes' => 'For receive',
            'items' => $items,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/purchase-orders/{$poId}/submit")->assertOk();

        Sanctum::actingAs(User::factory()->storeManager()->create(), ['staff']);
        $this->postJson("/api/v1/purchase-orders/{$poId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $itemIds = PurchaseOrderItem::query()
            ->where('purchase_order_id', $poId)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return ['poId' => $poId, 'itemIds' => $itemIds];
    }

    public function test_partial_receive_posts_purchase_movements_and_sets_partial(): void
    {
        $ids = $this->createApprovedPo();
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        $response = $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $ids['poId'],
            'items' => [
                ['purchaseOrderItemId' => $ids['itemIds'][0], 'qty' => '4.0000'],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.purchaseOrderId', $ids['poId'])
            ->assertJsonPath('data.items.0.qty', '4.0000')
            ->assertJsonPath('data.purchaseOrder.status', 'partial')
            ->assertJsonPath('data.purchaseOrder.items.0.qtyReceived', '4.0000')
            ->assertJsonPath('data.purchaseOrder.items.0.qtyRemaining', '6.0000');

        $balance = $this->ledger->balanceFor(
            $this->orgId,
            LocationType::Store->value,
            $this->store->id,
            $this->variant->id,
        );
        $this->assertSame('4.0000', $balance?->available);

        $this->assertSame(
            1,
            InventoryMovement::query()
                ->where('type', MovementType::Purchase->value)
                ->where('reference_type', 'goods_receipt')
                ->where('reference_id', $response->json('data.id'))
                ->count()
        );
    }

    public function test_subsequent_receive_completes_po_to_received(): void
    {
        $ids = $this->createApprovedPo();
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $ids['poId'],
            'items' => [
                ['purchaseOrderItemId' => $ids['itemIds'][0], 'qty' => '4.0000'],
            ],
        ])->assertCreated()->assertJsonPath('data.purchaseOrder.status', 'partial');

        $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $ids['poId'],
            'items' => [
                ['purchaseOrderItemId' => $ids['itemIds'][0], 'qty' => '6.0000'],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.purchaseOrder.status', 'received')
            ->assertJsonPath('data.purchaseOrder.items.0.qtyReceived', '10.0000')
            ->assertJsonPath('data.purchaseOrder.items.0.qtyRemaining', '0.0000');

        $balance = $this->ledger->balanceFor(
            $this->orgId,
            LocationType::Store->value,
            $this->store->id,
            $this->variant->id,
        );
        $this->assertSame('10.0000', $balance?->available);
    }

    public function test_multi_line_receive_one_line_then_other_completes(): void
    {
        $ids = $this->createApprovedPo([
            [
                'productVariantId' => $this->variant->id,
                'qty' => '5.0000',
                'unitCost' => '1.0000',
            ],
            [
                'productVariantId' => $this->variantB->id,
                'qty' => '3.0000',
                'unitCost' => '2.0000',
            ],
        ]);
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $ids['poId'],
            'items' => [
                ['purchaseOrderItemId' => $ids['itemIds'][0], 'qty' => '5.0000'],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.purchaseOrder.status', 'partial');

        $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $ids['poId'],
            'items' => [
                ['purchaseOrderItemId' => $ids['itemIds'][1], 'qty' => '3.0000'],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.purchaseOrder.status', 'received');

        $this->assertSame(
            '5.0000',
            $this->ledger->balanceFor(
                $this->orgId,
                LocationType::Store->value,
                $this->store->id,
                $this->variant->id,
            )?->available
        );
        $this->assertSame(
            '3.0000',
            $this->ledger->balanceFor(
                $this->orgId,
                LocationType::Store->value,
                $this->store->id,
                $this->variantB->id,
            )?->available
        );
    }

    public function test_complete_receive_in_one_grn_sets_received(): void
    {
        $ids = $this->createApprovedPo([
            [
                'productVariantId' => $this->variant->id,
                'qty' => '5.0000',
                'unitCost' => '2.5000',
            ],
        ]);
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $ids['poId'],
            'items' => [
                ['purchaseOrderItemId' => $ids['itemIds'][0], 'qty' => '5.0000'],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('data.purchaseOrder.status', 'received');
    }

    public function test_over_receive_returns_422_and_does_not_change_stock(): void
    {
        $ids = $this->createApprovedPo([
            [
                'productVariantId' => $this->variant->id,
                'qty' => '5.0000',
                'unitCost' => '2.5000',
            ],
        ]);
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $ids['poId'],
            'items' => [
                ['purchaseOrderItemId' => $ids['itemIds'][0], 'qty' => '6.0000'],
            ],
        ])
            ->assertStatus(422);

        $this->assertNull($this->ledger->balanceFor(
            $this->orgId,
            LocationType::Store->value,
            $this->store->id,
            $this->variant->id,
        ));
        $this->assertSame(0, GoodsReceipt::query()->count());
        $this->assertSame(0, InventoryMovement::query()->where('type', MovementType::Purchase->value)->count());
    }

    public function test_wrong_status_returns_422(): void
    {
        $creator = User::factory()->procurementOfficer()->create();
        Sanctum::actingAs($creator, ['staff']);

        $poId = $this->postJson('/api/v1/purchase-orders', [
            'storeId' => $this->store->id,
            'supplierId' => $this->supplier->id,
            'items' => [
                [
                    'productVariantId' => $this->variant->id,
                    'qty' => '2.0000',
                    'unitCost' => '1.0000',
                ],
            ],
        ])->assertCreated()->json('data.id');

        $itemId = (int) PurchaseOrderItem::query()->where('purchase_order_id', $poId)->value('id');

        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $poId,
            'items' => [
                ['purchaseOrderItemId' => $itemId, 'qty' => '1.0000'],
            ],
        ])->assertStatus(422);

        Sanctum::actingAs($creator, ['staff']);
        $this->postJson("/api/v1/purchase-orders/{$poId}/submit")->assertOk();

        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);
        $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $poId,
            'items' => [
                ['purchaseOrderItemId' => $itemId, 'qty' => '1.0000'],
            ],
        ])->assertStatus(422);
    }

    public function test_rejected_po_returns_422(): void
    {
        $creator = User::factory()->procurementOfficer()->create();
        Sanctum::actingAs($creator, ['staff']);

        $poId = $this->postJson('/api/v1/purchase-orders', [
            'storeId' => $this->store->id,
            'supplierId' => $this->supplier->id,
            'items' => [
                [
                    'productVariantId' => $this->variant->id,
                    'qty' => '2.0000',
                    'unitCost' => '1.0000',
                ],
            ],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/purchase-orders/{$poId}/submit")->assertOk();

        Sanctum::actingAs(User::factory()->storeManager()->create(), ['staff']);
        $this->postJson("/api/v1/purchase-orders/{$poId}/reject", ['reason' => 'No'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $itemId = (int) PurchaseOrderItem::query()->where('purchase_order_id', $poId)->value('id');

        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);
        $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $poId,
            'items' => [
                ['purchaseOrderItemId' => $itemId, 'qty' => '1.0000'],
            ],
        ])->assertStatus(422);
    }

    public function test_cross_po_item_id_returns_422_without_stock_change(): void
    {
        $poA = $this->createApprovedPo([
            [
                'productVariantId' => $this->variant->id,
                'qty' => '5.0000',
                'unitCost' => '1.0000',
            ],
        ]);
        $poB = $this->createApprovedPo([
            [
                'productVariantId' => $this->variantB->id,
                'qty' => '5.0000',
                'unitCost' => '1.0000',
            ],
        ]);

        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $poA['poId'],
            'items' => [
                ['purchaseOrderItemId' => $poB['itemIds'][0], 'qty' => '1.0000'],
            ],
        ])->assertStatus(422);

        $this->assertSame(0, GoodsReceipt::query()->count());
        $this->assertNull($this->ledger->balanceFor(
            $this->orgId,
            LocationType::Store->value,
            $this->store->id,
            $this->variant->id,
        ));
        $this->assertNull($this->ledger->balanceFor(
            $this->orgId,
            LocationType::Store->value,
            $this->store->id,
            $this->variantB->id,
        ));
    }

    public function test_inventory_manager_can_list_and_show_purchase_orders(): void
    {
        $ids = $this->createApprovedPo();
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        $this->getJson('/api/v1/purchase-orders')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->getJson("/api/v1/purchase-orders/{$ids['poId']}")
            ->assertOk()
            ->assertJsonPath('data.id', $ids['poId'])
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_grn_create_writes_audit_log(): void
    {
        $ids = $this->createApprovedPo();
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        $grnId = $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $ids['poId'],
            'items' => [
                ['purchaseOrderItemId' => $ids['itemIds'][0], 'qty' => '2.0000'],
            ],
        ])->assertCreated()->json('data.id');

        $this->assertTrue(
            AuditLog::query()
                ->where('auditable_type', GoodsReceipt::class)
                ->where('auditable_id', $grnId)
                ->where('action', 'created')
                ->exists()
        );
    }

    public function test_without_receive_permission_returns_403(): void
    {
        $ids = $this->createApprovedPo();
        Sanctum::actingAs(User::factory()->cashier()->create(), ['staff']);

        $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $ids['poId'],
            'items' => [
                ['purchaseOrderItemId' => $ids['itemIds'][0], 'qty' => '1.0000'],
            ],
        ])->assertForbidden();
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => 1,
            'items' => [
                ['purchaseOrderItemId' => 1, 'qty' => '1.0000'],
            ],
        ])->assertUnauthorized();
    }

    public function test_idempotency_key_replay_returns_same_payload_without_double_stock(): void
    {
        $ids = $this->createApprovedPo();
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        $payload = [
            'purchaseOrderId' => $ids['poId'],
            'notes' => 'Dock A',
            'items' => [
                ['purchaseOrderItemId' => $ids['itemIds'][0], 'qty' => '3.0000'],
            ],
        ];
        $headers = ['Idempotency-Key' => 'grn-test-key-001'];

        $first = $this->postJson('/api/v1/goods-receipts', $payload, $headers)->assertCreated();
        $grnId = $first->json('data.id');

        $second = $this->postJson('/api/v1/goods-receipts', $payload, $headers)->assertOk();
        $this->assertSame($grnId, $second->json('data.id'));
        $this->assertSame($first->json('data'), $second->json('data'));

        $this->assertSame(1, GoodsReceipt::query()->count());
        $this->assertSame(
            1,
            InventoryMovement::query()
                ->where('type', MovementType::Purchase->value)
                ->where('reference_type', 'goods_receipt')
                ->count()
        );

        $balance = $this->ledger->balanceFor(
            $this->orgId,
            LocationType::Store->value,
            $this->store->id,
            $this->variant->id,
        );
        $this->assertSame('3.0000', $balance?->available);
    }

    public function test_idempotency_key_too_long_returns_422(): void
    {
        $ids = $this->createApprovedPo();
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $ids['poId'],
            'items' => [
                ['purchaseOrderItemId' => $ids['itemIds'][0], 'qty' => '1.0000'],
            ],
        ], ['Idempotency-Key' => str_repeat('k', 65)])
            ->assertStatus(422);
    }

    public function test_idempotency_key_conflict_different_body_returns_409(): void
    {
        $ids = $this->createApprovedPo();
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        $headers = ['Idempotency-Key' => 'grn-conflict-key'];

        $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $ids['poId'],
            'items' => [
                ['purchaseOrderItemId' => $ids['itemIds'][0], 'qty' => '2.0000'],
            ],
        ], $headers)->assertCreated();

        $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $ids['poId'],
            'items' => [
                ['purchaseOrderItemId' => $ids['itemIds'][0], 'qty' => '3.0000'],
            ],
        ], $headers)->assertStatus(409);

        $this->assertSame(1, GoodsReceipt::query()->count());
        $this->assertSame(
            '2.0000',
            $this->ledger->balanceFor(
                $this->orgId,
                LocationType::Store->value,
                $this->store->id,
                $this->variant->id,
            )?->available
        );
    }

    public function test_zero_qty_returns_422(): void
    {
        $ids = $this->createApprovedPo();
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $ids['poId'],
            'items' => [
                ['purchaseOrderItemId' => $ids['itemIds'][0], 'qty' => '0'],
            ],
        ])->assertStatus(422);
    }
}
