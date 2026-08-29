<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Audit\Models\AuditLog;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Modules\Purchasing\Models\PurchaseOrderItem;
use Modules\Purchasing\Models\Supplier;
use Modules\Purchasing\Models\SupplierInvoice;
use Modules\Purchasing\Models\SupplierPayment;
use Tests\TestCase;

class SupplierInvoicePaymentApiTest extends TestCase
{
    use RefreshDatabase;

    private int $orgId;

    private Store $store;

    private Supplier $supplier;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionMatrixSeeder::class);
        $this->orgId = app(OrganizationService::class)->current()->id;
        $this->store = Store::factory()->create([
            'organization_id' => $this->orgId,
            'name' => 'AP Store',
        ]);
        $this->supplier = Supplier::query()->create([
            'organization_id' => $this->orgId,
            'name' => 'Acme AP',
            'code' => 'ACMEAP',
            'is_active' => true,
        ]);

        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'sku' => 'AP-P1',
        ]);

        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->orgId,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'AP-V1',
            'price' => '10.0000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    /**
     * @return array{poId: int, itemIds: list<int>, grnId: int}
     */
    private function createReceivedGrn(string $qty = '10.0000', string $unitCost = '2.5000'): array
    {
        $creator = User::factory()->procurementOfficer()->create();
        Sanctum::actingAs($creator, ['staff']);

        $poId = $this->postJson('/api/v1/purchase-orders', [
            'storeId' => $this->store->id,
            'supplierId' => $this->supplier->id,
            'notes' => 'For AP',
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
        $grnId = $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $poId,
            'items' => [
                ['purchaseOrderItemId' => $itemIds[0], 'qty' => $qty],
            ],
        ])->assertCreated()->json('data.id');

        return ['poId' => $poId, 'itemIds' => $itemIds, 'grnId' => $grnId];
    }

    public function test_create_invoice_matched_to_grn_increases_outstanding_with_decimal(): void
    {
        $ids = $this->createReceivedGrn('10.0000', '2.5000');
        Sanctum::actingAs(User::factory()->create(['role' => 'accountant']), ['staff']);

        $response = $this->postJson('/api/v1/supplier-invoices', [
            'goodsReceiptId' => $ids['grnId'],
        ], ['Idempotency-Key' => 'inv-1']);

        $response->assertCreated()
            ->assertJsonPath('data.goodsReceiptId', $ids['grnId'])
            ->assertJsonPath('data.supplierId', $this->supplier->id)
            ->assertJsonPath('data.purchaseOrderId', $ids['poId'])
            ->assertJsonPath('data.totalAmount', '25.0000')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.amountRemaining', '25.0000')
            ->assertJsonPath('meta.outstanding', '25.0000');

        $this->assertDatabaseHas('supplier_invoices', [
            'goods_receipt_id' => $ids['grnId'],
            'total_amount' => '25.0000',
        ]);

        $this->getJson("/api/v1/suppliers/{$this->supplier->id}")
            ->assertOk()
            ->assertJsonPath('data.outstandingBalance', '25.0000');
    }

    public function test_invoice_supplier_matches_grn_po_supplier(): void
    {
        $ids = $this->createReceivedGrn();
        Sanctum::actingAs(User::factory()->create(['role' => 'accountant']), ['staff']);

        $this->postJson('/api/v1/supplier-invoices', [
            'goodsReceiptId' => $ids['grnId'],
        ])->assertCreated()
            ->assertJsonPath('data.supplierId', $this->supplier->id);
    }

    public function test_create_invoice_rejects_missing_grn(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'accountant']), ['staff']);

        $this->postJson('/api/v1/supplier-invoices', [
            'goodsReceiptId' => 999999,
        ])->assertStatus(422);
    }

    public function test_create_invoice_rejects_zero_total(): void
    {
        $ids = $this->createReceivedGrn('10.0000', '2.5000');
        Sanctum::actingAs(User::factory()->create(['role' => 'accountant']), ['staff']);

        $this->postJson('/api/v1/supplier-invoices', [
            'goodsReceiptId' => $ids['grnId'],
            'totalAmount' => '0.0000',
        ])->assertStatus(422);

        $this->assertSame(0, SupplierInvoice::query()->count());
    }

    public function test_create_invoice_rejects_oversized_decimal(): void
    {
        $ids = $this->createReceivedGrn();
        Sanctum::actingAs(User::factory()->create(['role' => 'accountant']), ['staff']);

        $this->postJson('/api/v1/supplier-invoices', [
            'goodsReceiptId' => $ids['grnId'],
            'totalAmount' => '123456789012.0000',
        ])->assertStatus(422);
    }

    public function test_partial_then_full_payment_updates_remaining_and_status(): void
    {
        $ids = $this->createReceivedGrn('10.0000', '2.5000');
        Sanctum::actingAs(User::factory()->create(['role' => 'accountant']), ['staff']);

        $invoiceId = $this->postJson('/api/v1/supplier-invoices', [
            'goodsReceiptId' => $ids['grnId'],
        ])->assertCreated()->json('data.id');

        $partial = $this->postJson('/api/v1/supplier-payments', [
            'supplierInvoiceId' => $invoiceId,
            'amount' => '10.0000',
            'method' => 'transfer',
        ], ['Idempotency-Key' => 'pay-1']);

        $partial->assertCreated()
            ->assertJsonPath('data.amount', '10.0000')
            ->assertJsonPath('data.invoice.status', 'partial')
            ->assertJsonPath('data.invoice.amountRemaining', '15.0000')
            ->assertJsonPath('meta.outstanding', '15.0000');

        $this->assertSame(1, SupplierPayment::query()->count());

        $full = $this->postJson('/api/v1/supplier-payments', [
            'supplierInvoiceId' => $invoiceId,
            'amount' => '15.0000',
        ], ['Idempotency-Key' => 'pay-2']);

        $full->assertCreated()
            ->assertJsonPath('data.invoice.status', 'paid')
            ->assertJsonPath('data.invoice.amountRemaining', '0.0000')
            ->assertJsonPath('meta.outstanding', '0.0000');

        $this->assertSame(2, SupplierPayment::query()->count());
        $this->assertSame('paid', SupplierInvoice::query()->find($invoiceId)?->status->value);

        $this->getJson("/api/v1/suppliers/{$this->supplier->id}")
            ->assertOk()
            ->assertJsonPath('data.outstandingBalance', '0.0000');
    }

    public function test_overpay_rejected_with_422_and_no_payment_row(): void
    {
        $ids = $this->createReceivedGrn('4.0000', '2.5000');
        Sanctum::actingAs(User::factory()->create(['role' => 'accountant']), ['staff']);

        $invoiceId = $this->postJson('/api/v1/supplier-invoices', [
            'goodsReceiptId' => $ids['grnId'],
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/supplier-payments', [
            'supplierInvoiceId' => $invoiceId,
            'amount' => '10.0001',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Payment amount exceeds invoice remaining balance.');

        $this->assertSame(0, SupplierPayment::query()->count());
        $this->assertSame('open', SupplierInvoice::query()->find($invoiceId)?->status->value);
    }

    public function test_unauthorized_without_purchase_pay_gets_403(): void
    {
        $ids = $this->createReceivedGrn();
        Sanctum::actingAs(User::factory()->cashier()->create(), ['staff']);

        $this->postJson('/api/v1/supplier-invoices', [
            'goodsReceiptId' => $ids['grnId'],
        ])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'accountant']), ['staff']);
        $invoiceId = $this->postJson('/api/v1/supplier-invoices', [
            'goodsReceiptId' => $ids['grnId'],
        ])->assertCreated()->json('data.id');

        Sanctum::actingAs(User::factory()->cashier()->create(), ['staff']);
        $this->postJson('/api/v1/supplier-payments', [
            'supplierInvoiceId' => $invoiceId,
            'amount' => '1.0000',
        ])->assertForbidden();

        $this->getJson('/api/v1/supplier-invoices')->assertForbidden();
        $this->getJson('/api/v1/supplier-payments')->assertForbidden();
    }

    public function test_unauthenticated_gets_401(): void
    {
        $this->postJson('/api/v1/supplier-invoices', [
            'goodsReceiptId' => 1,
        ])->assertUnauthorized();

        $this->postJson('/api/v1/supplier-payments', [
            'supplierInvoiceId' => 1,
            'amount' => '1.0000',
        ])->assertUnauthorized();
    }

    public function test_balance_read_is_invoices_minus_payments_decimal(): void
    {
        $ids = $this->createReceivedGrn('10.0000', '1.2500');
        Sanctum::actingAs(User::factory()->procurementOfficer()->create(), ['staff']);

        $invoiceId = $this->postJson('/api/v1/supplier-invoices', [
            'goodsReceiptId' => $ids['grnId'],
            'totalAmount' => '12.5000',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/supplier-payments', [
            'supplierInvoiceId' => $invoiceId,
            'amount' => '3.2500',
        ])->assertCreated();

        $list = $this->getJson('/api/v1/supplier-invoices?supplierId='.$this->supplier->id);
        $list->assertOk()
            ->assertJsonPath('meta.outstanding', '9.2500')
            ->assertJsonPath('data.0.totalAmount', '12.5000')
            ->assertJsonPath('data.0.amountPaid', '3.2500')
            ->assertJsonPath('data.0.amountRemaining', '9.2500');
    }

    public function test_list_meta_includes_invoiceable_goods_receipts(): void
    {
        $ids = $this->createReceivedGrn('5.0000', '1.0000');
        Sanctum::actingAs(User::factory()->create(['role' => 'accountant']), ['staff']);

        $before = $this->getJson('/api/v1/supplier-invoices?supplierId='.$this->supplier->id);
        $before->assertOk();
        $grnIds = collect($before->json('meta.invoiceableGoodsReceipts'))->pluck('id')->all();
        $this->assertContains($ids['grnId'], $grnIds);

        $this->postJson('/api/v1/supplier-invoices', [
            'goodsReceiptId' => $ids['grnId'],
        ])->assertCreated();

        $after = $this->getJson('/api/v1/supplier-invoices?supplierId='.$this->supplier->id);
        $after->assertOk();
        $grnIdsAfter = collect($after->json('meta.invoiceableGoodsReceipts'))->pluck('id')->all();
        $this->assertNotContains($ids['grnId'], $grnIdsAfter);
    }

    public function test_index_rejects_invalid_status_enum(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'accountant']), ['staff']);

        $this->getJson('/api/v1/supplier-invoices?status=bogus')
            ->assertStatus(422);
    }

    public function test_invoice_and_payment_create_write_audit_logs(): void
    {
        $ids = $this->createReceivedGrn();
        Sanctum::actingAs(User::factory()->create(['role' => 'accountant']), ['staff']);

        $invoiceId = $this->postJson('/api/v1/supplier-invoices', [
            'goodsReceiptId' => $ids['grnId'],
        ])->assertCreated()->json('data.id');

        $this->assertTrue(
            AuditLog::query()
                ->where('action', 'created')
                ->where('auditable_type', SupplierInvoice::class)
                ->where('auditable_id', $invoiceId)
                ->exists()
        );

        $paymentId = $this->postJson('/api/v1/supplier-payments', [
            'supplierInvoiceId' => $invoiceId,
            'amount' => '1.0000',
        ])->assertCreated()->json('data.id');

        $this->assertTrue(
            AuditLog::query()
                ->where('action', 'created')
                ->where('auditable_type', SupplierPayment::class)
                ->where('auditable_id', $paymentId)
                ->exists()
        );
    }

    public function test_duplicate_invoice_for_same_grn_rejected(): void
    {
        $ids = $this->createReceivedGrn();
        Sanctum::actingAs(User::factory()->create(['role' => 'accountant']), ['staff']);

        $this->postJson('/api/v1/supplier-invoices', [
            'goodsReceiptId' => $ids['grnId'],
        ])->assertCreated();

        $this->postJson('/api/v1/supplier-invoices', [
            'goodsReceiptId' => $ids['grnId'],
        ])->assertStatus(422);
    }

    public function test_invoice_idempotency_replay_returns_200_without_duplicate(): void
    {
        $ids = $this->createReceivedGrn();
        Sanctum::actingAs(User::factory()->create(['role' => 'accountant']), ['staff']);
        $headers = ['Idempotency-Key' => 'inv-replay-001'];

        $first = $this->postJson('/api/v1/supplier-invoices', [
            'goodsReceiptId' => $ids['grnId'],
        ], $headers)->assertCreated();

        $second = $this->postJson('/api/v1/supplier-invoices', [
            'goodsReceiptId' => $ids['grnId'],
        ], $headers)->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, SupplierInvoice::query()->count());
    }

    public function test_invoice_idempotency_key_too_long_returns_422(): void
    {
        $ids = $this->createReceivedGrn();
        Sanctum::actingAs(User::factory()->create(['role' => 'accountant']), ['staff']);

        $this->postJson('/api/v1/supplier-invoices', [
            'goodsReceiptId' => $ids['grnId'],
        ], ['Idempotency-Key' => str_repeat('k', 65)])
            ->assertStatus(422);
    }

    public function test_invoice_idempotency_conflict_different_body_returns_409(): void
    {
        $first = $this->createReceivedGrn('4.0000', '1.0000');
        $second = $this->createReceivedGrn('6.0000', '1.0000');
        Sanctum::actingAs(User::factory()->create(['role' => 'accountant']), ['staff']);
        $headers = ['Idempotency-Key' => 'inv-conflict-key'];

        $this->postJson('/api/v1/supplier-invoices', [
            'goodsReceiptId' => $first['grnId'],
        ], $headers)->assertCreated();

        $this->postJson('/api/v1/supplier-invoices', [
            'goodsReceiptId' => $second['grnId'],
        ], $headers)->assertStatus(409);
    }

    public function test_payment_idempotency_replay_and_conflict(): void
    {
        $ids = $this->createReceivedGrn('10.0000', '2.0000');
        Sanctum::actingAs(User::factory()->create(['role' => 'accountant']), ['staff']);

        $invoiceId = $this->postJson('/api/v1/supplier-invoices', [
            'goodsReceiptId' => $ids['grnId'],
        ])->assertCreated()->json('data.id');

        $headers = ['Idempotency-Key' => 'pay-replay-001'];
        $first = $this->postJson('/api/v1/supplier-payments', [
            'supplierInvoiceId' => $invoiceId,
            'amount' => '5.0000',
        ], $headers)->assertCreated();

        $second = $this->postJson('/api/v1/supplier-payments', [
            'supplierInvoiceId' => $invoiceId,
            'amount' => '5.0000',
        ], $headers)->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, SupplierPayment::query()->count());

        $this->postJson('/api/v1/supplier-payments', [
            'supplierInvoiceId' => $invoiceId,
            'amount' => '1.0000',
        ], $headers)->assertStatus(409);

        $this->postJson('/api/v1/supplier-payments', [
            'supplierInvoiceId' => $invoiceId,
            'amount' => '1.0000',
        ], ['Idempotency-Key' => str_repeat('p', 65)])
            ->assertStatus(422);
    }
}
