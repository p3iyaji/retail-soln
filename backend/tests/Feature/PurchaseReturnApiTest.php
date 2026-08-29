<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Audit\Models\AuditLog;
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
use Modules\Purchasing\Models\GoodsReceiptItem;
use Modules\Purchasing\Models\PurchaseOrderItem;
use Modules\Purchasing\Models\PurchaseReturn;
use Modules\Purchasing\Models\Supplier;
use Tests\Concerns\PostsIdempotentPurchasing;
use Tests\TestCase;

class PurchaseReturnApiTest extends TestCase
{
    use PostsIdempotentPurchasing, RefreshDatabase;

    private int $orgId;

    private Store $store;

    private Supplier $supplier;

    private ProductVariant $variant;

    private InventoryLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionMatrixSeeder::class);
        $this->ledger = app(InventoryLedgerService::class);
        $this->orgId = app(OrganizationService::class)->current()->id;
        $this->store = Store::factory()->create([
            'organization_id' => $this->orgId,
            'name' => 'Return Store',
        ]);
        $this->supplier = Supplier::query()->create([
            'organization_id' => $this->orgId,
            'name' => 'Acme Returns',
            'code' => 'ACMERET',
            'is_active' => true,
        ]);

        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'sku' => 'RET-P1',
        ]);

        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->orgId,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'RET-V1',
            'price' => '10.0000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    /**
     * @return array{poId: int, itemIds: list<int>, grnId: int, grnItemId: int}
     */
    private function createReceivedGrn(string $qty = '10.0000', string $unitCost = '2.5000'): array
    {
        $creator = User::factory()->procurementOfficer()->create();
        Sanctum::actingAs($creator, ['staff']);

        $poId = $this->postJson('/api/v1/purchase-orders', [
            'storeId' => $this->store->id,
            'supplierId' => $this->supplier->id,
            'notes' => 'For returns',
            'items' => [
                [
                    'productVariantId' => $this->variant->id,
                    'qty' => $qty,
                    'unitCost' => $unitCost,
                ],
            ],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/purchase-orders/{$poId}/submit")->assertOk();

        Sanctum::actingAs(User::factory()->storeManager()->create(), ['staff']);
        $this->postJson("/api/v1/purchase-orders/{$poId}/approve")->assertOk();

        $itemIds = PurchaseOrderItem::query()
            ->where('purchase_order_id', $poId)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);
        $grnId = $this->postGoodsReceipt([
            'purchaseOrderId' => $poId,
            'items' => [
                ['purchaseOrderItemId' => $itemIds[0], 'qty' => $qty],
            ],
        ])->assertCreated()->json('data.id');

        $grnItemId = (int) GoodsReceiptItem::query()
            ->where('goods_receipt_id', $grnId)
            ->value('id');

        return [
            'poId' => $poId,
            'itemIds' => $itemIds,
            'grnId' => $grnId,
            'grnItemId' => $grnItemId,
        ];
    }

    public function test_happy_path_posts_negative_purchase_return_and_credit(): void
    {
        $ids = $this->createReceivedGrn('10.0000', '2.5000');
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        $response = $this->postPurchaseReturn([
            'goodsReceiptId' => $ids['grnId'],
            'items' => [
                ['goodsReceiptItemId' => $ids['grnItemId'], 'qty' => '4.0000'],
            ],
        ], ['Idempotency-Key' => 'ret-happy-1']);

        $response->assertCreated()
            ->assertJsonPath('data.goodsReceiptId', $ids['grnId'])
            ->assertJsonPath('data.purchaseOrderId', $ids['poId'])
            ->assertJsonPath('data.supplierId', $this->supplier->id)
            ->assertJsonPath('data.creditAmount', '10.0000')
            ->assertJsonPath('data.items.0.qty', '4.0000')
            ->assertJsonPath('meta.outstanding', '-10.0000');

        $this->assertSame(
            '6.0000',
            $this->ledger->balanceFor(
                $this->orgId,
                LocationType::Store->value,
                $this->store->id,
                $this->variant->id,
            )?->available
        );

        $movement = InventoryMovement::query()
            ->where('type', MovementType::PurchaseReturn->value)
            ->where('reference_type', 'purchase_return')
            ->where('reference_id', $response->json('data.id'))
            ->first();

        $this->assertNotNull($movement);
        $this->assertSame('-4.0000', (string) $movement->quantity);
        $this->assertNotSame(MovementType::Return->value, $movement->type);
    }

    public function test_outstanding_drops_by_credit_after_invoice(): void
    {
        $ids = $this->createReceivedGrn('10.0000', '2.5000');
        Sanctum::actingAs(User::factory()->create(['role' => 'accountant']), ['staff']);

        $this->postSupplierInvoice([
            'goodsReceiptId' => $ids['grnId'],
        ])->assertCreated()
            ->assertJsonPath('meta.outstanding', '25.0000');

        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);
        $this->postPurchaseReturn([
            'goodsReceiptId' => $ids['grnId'],
            'items' => [
                ['goodsReceiptItemId' => $ids['grnItemId'], 'qty' => '2.0000'],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.creditAmount', '5.0000')
            ->assertJsonPath('meta.outstanding', '20.0000');

        Sanctum::actingAs(User::factory()->procurementOfficer()->create(), ['staff']);
        $this->getJson("/api/v1/suppliers/{$this->supplier->id}")
            ->assertOk()
            ->assertJsonPath('data.outstandingBalance', '20.0000');
    }

    public function test_uninvoiced_grn_return_still_applies_credit_and_stock(): void
    {
        $ids = $this->createReceivedGrn('8.0000', '1.2500');
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        $this->postPurchaseReturn([
            'goodsReceiptId' => $ids['grnId'],
            'items' => [
                ['goodsReceiptItemId' => $ids['grnItemId'], 'qty' => '3.0000'],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.creditAmount', '3.7500')
            ->assertJsonPath('meta.outstanding', '-3.7500');

        $this->assertSame(
            '5.0000',
            $this->ledger->balanceFor(
                $this->orgId,
                LocationType::Store->value,
                $this->store->id,
                $this->variant->id,
            )?->available
        );
    }

    public function test_cap_exceeded_returns_422_without_partial_commit(): void
    {
        $ids = $this->createReceivedGrn('5.0000', '1.0000');
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        $this->postPurchaseReturn([
            'goodsReceiptId' => $ids['grnId'],
            'items' => [
                ['goodsReceiptItemId' => $ids['grnItemId'], 'qty' => '3.0000'],
            ],
        ])->assertCreated();

        $this->postPurchaseReturn([
            'goodsReceiptId' => $ids['grnId'],
            'items' => [
                ['goodsReceiptItemId' => $ids['grnItemId'], 'qty' => '3.0000'],
            ],
        ])->assertStatus(422);

        $this->assertSame(1, PurchaseReturn::query()->count());
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

    public function test_insufficient_stock_returns_422_without_rows(): void
    {
        $ids = $this->createReceivedGrn('10.0000', '1.0000');

        // Drain stock below return qty (sold/adjusted out) while GRN still returnable.
        $this->ledger->post(new PostMovementData(
            organizationId: $this->orgId,
            locationType: LocationType::Store,
            locationId: $this->store->id,
            productVariantId: $this->variant->id,
            type: MovementType::Adjustment,
            quantity: '-8.0000',
            unitCost: '1.0000',
            notes: 'Drain for stock test',
        ));

        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);
        $this->postPurchaseReturn([
            'goodsReceiptId' => $ids['grnId'],
            'items' => [
                ['goodsReceiptItemId' => $ids['grnItemId'], 'qty' => '5.0000'],
            ],
        ])->assertStatus(422);

        $this->assertSame(0, PurchaseReturn::query()->count());
        $this->assertSame(
            0,
            InventoryMovement::query()->where('type', MovementType::PurchaseReturn->value)->count()
        );
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

    public function test_idempotency_replay_and_conflict(): void
    {
        $ids = $this->createReceivedGrn('10.0000', '2.0000');
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);
        $headers = ['Idempotency-Key' => 'ret-replay-001'];
        $payload = [
            'goodsReceiptId' => $ids['grnId'],
            'notes' => 'Damaged',
            'items' => [
                ['goodsReceiptItemId' => $ids['grnItemId'], 'qty' => '2.0000'],
            ],
        ];

        $first = $this->postPurchaseReturn($payload, $headers)->assertCreated();
        $second = $this->postPurchaseReturn($payload, $headers)->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, PurchaseReturn::query()->count());
        $this->assertSame(
            1,
            InventoryMovement::query()->where('type', MovementType::PurchaseReturn->value)->count()
        );

        $this->postPurchaseReturn([
            'goodsReceiptId' => $ids['grnId'],
            'items' => [
                ['goodsReceiptItemId' => $ids['grnItemId'], 'qty' => '1.0000'],
            ],
        ], $headers)->assertStatus(409);
    }

    public function test_without_receive_permission_returns_403(): void
    {
        $ids = $this->createReceivedGrn();
        Sanctum::actingAs(User::factory()->cashier()->create(), ['staff']);

        $this->postPurchaseReturn([
            'goodsReceiptId' => $ids['grnId'],
            'items' => [
                ['goodsReceiptItemId' => $ids['grnItemId'], 'qty' => '1.0000'],
            ],
        ])->assertForbidden();

        $this->getJson('/api/v1/purchase-returns')->assertForbidden();
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->postPurchaseReturn([
            'goodsReceiptId' => 1,
            'items' => [
                ['goodsReceiptItemId' => 1, 'qty' => '1.0000'],
            ],
        ])->assertUnauthorized();
    }

    public function test_create_writes_audit_log(): void
    {
        $ids = $this->createReceivedGrn();
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        $returnId = $this->postPurchaseReturn([
            'goodsReceiptId' => $ids['grnId'],
            'items' => [
                ['goodsReceiptItemId' => $ids['grnItemId'], 'qty' => '1.0000'],
            ],
        ])->assertCreated()->json('data.id');

        $this->assertTrue(
            AuditLog::query()
                ->where('auditable_type', PurchaseReturn::class)
                ->where('auditable_id', $returnId)
                ->where('action', 'created')
                ->exists()
        );
    }

    public function test_list_and_show_purchase_returns(): void
    {
        $first = $this->createReceivedGrn('10.0000', '2.5000');
        $second = $this->createReceivedGrn('4.0000', '1.0000');
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        $returnId = $this->postPurchaseReturn([
            'goodsReceiptId' => $first['grnId'],
            'items' => [
                ['goodsReceiptItemId' => $first['grnItemId'], 'qty' => '1.5000'],
            ],
        ])->assertCreated()->json('data.id');

        $otherReturnId = $this->postPurchaseReturn([
            'goodsReceiptId' => $second['grnId'],
            'items' => [
                ['goodsReceiptItemId' => $second['grnItemId'], 'qty' => '1.0000'],
            ],
        ])->assertCreated()->json('data.id');

        $this->assertNotSame($first['poId'], $second['poId']);
        $this->assertNotSame($returnId, $otherReturnId);

        $filtered = $this->getJson('/api/v1/purchase-returns?purchaseOrderId='.$first['poId']);
        $filtered->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $returnId);
        $this->assertSame([$returnId], collect($filtered->json('data'))->pluck('id')->all());

        $this->getJson("/api/v1/purchase-returns/{$returnId}")
            ->assertOk()
            ->assertJsonPath('data.creditAmount', '3.7500')
            ->assertJsonPath('meta.outstanding', '-4.7500');
    }

    public function test_multi_line_same_variant_aggregate_stock_insufficient(): void
    {
        $creator = User::factory()->procurementOfficer()->create();
        Sanctum::actingAs($creator, ['staff']);

        $poId = $this->postJson('/api/v1/purchase-orders', [
            'storeId' => $this->store->id,
            'supplierId' => $this->supplier->id,
            'items' => [
                [
                    'productVariantId' => $this->variant->id,
                    'qty' => '5.0000',
                    'unitCost' => '1.0000',
                ],
                [
                    'productVariantId' => $this->variant->id,
                    'qty' => '5.0000',
                    'unitCost' => '1.0000',
                ],
            ],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/purchase-orders/{$poId}/submit")->assertOk();
        Sanctum::actingAs(User::factory()->storeManager()->create(), ['staff']);
        $this->postJson("/api/v1/purchase-orders/{$poId}/approve")->assertOk();

        $itemIds = PurchaseOrderItem::query()
            ->where('purchase_order_id', $poId)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);
        $grnId = $this->postGoodsReceipt([
            'purchaseOrderId' => $poId,
            'items' => [
                ['purchaseOrderItemId' => $itemIds[0], 'qty' => '5.0000'],
                ['purchaseOrderItemId' => $itemIds[1], 'qty' => '5.0000'],
            ],
        ])->assertCreated()->json('data.id');

        $grnItemIds = GoodsReceiptItem::query()
            ->where('goods_receipt_id', $grnId)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertCount(2, $grnItemIds);
        $this->assertSame(
            '10.0000',
            $this->ledger->balanceFor(
                $this->orgId,
                LocationType::Store->value,
                $this->store->id,
                $this->variant->id,
            )?->available
        );

        // Leave 6 available: each line could return 4 alone, but 4+4 exceeds available.
        $this->ledger->post(new PostMovementData(
            organizationId: $this->orgId,
            locationType: LocationType::Store,
            locationId: $this->store->id,
            productVariantId: $this->variant->id,
            type: MovementType::Adjustment,
            quantity: '-4.0000',
            unitCost: '1.0000',
            notes: 'Drain for aggregate stock test',
        ));

        $this->postPurchaseReturn([
            'goodsReceiptId' => $grnId,
            'items' => [
                ['goodsReceiptItemId' => $grnItemIds[0], 'qty' => '4.0000'],
                ['goodsReceiptItemId' => $grnItemIds[1], 'qty' => '4.0000'],
            ],
        ])->assertStatus(422);

        $this->assertSame(0, PurchaseReturn::query()->count());
        $this->assertSame(
            0,
            InventoryMovement::query()->where('type', MovementType::PurchaseReturn->value)->count()
        );
        $this->assertSame(
            '6.0000',
            $this->ledger->balanceFor(
                $this->orgId,
                LocationType::Store->value,
                $this->store->id,
                $this->variant->id,
            )?->available
        );
    }

    public function test_po_show_includes_returnable_grn_lines(): void
    {
        $ids = $this->createReceivedGrn('6.0000', '1.0000');
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        $this->postPurchaseReturn([
            'goodsReceiptId' => $ids['grnId'],
            'items' => [
                ['goodsReceiptItemId' => $ids['grnItemId'], 'qty' => '2.0000'],
            ],
        ])->assertCreated();

        $this->getJson("/api/v1/purchase-orders/{$ids['poId']}")
            ->assertOk()
            ->assertJsonPath('data.goodsReceipts.0.id', $ids['grnId'])
            ->assertJsonPath('data.goodsReceipts.0.items.0.qty', '6.0000')
            ->assertJsonPath('data.goodsReceipts.0.items.0.qtyReturned', '2.0000')
            ->assertJsonPath('data.goodsReceipts.0.items.0.qtyReturnable', '4.0000');
    }

    public function test_idempotency_key_too_long_returns_422(): void
    {
        $ids = $this->createReceivedGrn();
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        $this->postPurchaseReturn([
            'goodsReceiptId' => $ids['grnId'],
            'items' => [
                ['goodsReceiptItemId' => $ids['grnItemId'], 'qty' => '1.0000'],
            ],
        ], ['Idempotency-Key' => str_repeat('k', 65)])
            ->assertStatus(422);
    }

    public function test_missing_idempotency_key_returns_422_without_writing(): void
    {
        $ids = $this->createReceivedGrn();
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        $this->postJson('/api/v1/purchase-returns', [
            'goodsReceiptId' => $ids['grnId'],
            'items' => [
                ['goodsReceiptItemId' => $ids['grnItemId'], 'qty' => '1.0000'],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Idempotency-Key header is required.');

        $this->assertSame(0, PurchaseReturn::query()->count());
    }
}
