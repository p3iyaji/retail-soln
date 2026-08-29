<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Audit\Models\AuditLog;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Modules\Purchasing\Enums\PurchaseOrderStatus;
use Modules\Purchasing\Models\PurchaseOrder;
use Modules\Purchasing\Models\Supplier;
use Modules\Settings\Models\Setting;
use Tests\TestCase;

class PurchaseOrderApiTest extends TestCase
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
            'name' => 'Main Store',
        ]);
        $this->supplier = Supplier::query()->create([
            'organization_id' => $this->orgId,
            'name' => 'Acme Supply',
            'code' => 'ACME',
            'is_active' => true,
        ]);

        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'sku' => 'PO-P1',
        ]);

        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->orgId,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'PO-V1',
            'price' => '10.0000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'storeId' => $this->store->id,
            'supplierId' => $this->supplier->id,
            'notes' => 'Restock',
            'items' => [
                [
                    'productVariantId' => $this->variant->id,
                    'qty' => '5.0000',
                    'unitCost' => '2.5000',
                ],
            ],
        ], $overrides);
    }

    public function test_create_draft_purchase_order_with_items_and_totals(): void
    {
        Sanctum::actingAs(User::factory()->procurementOfficer()->create(), ['staff']);

        $response = $this->postJson('/api/v1/purchase-orders', $this->validPayload());

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.totalAmount', '12.5000')
            ->assertJsonPath('data.items.0.qty', '5.0000')
            ->assertJsonPath('data.items.0.unitCost', '2.5000')
            ->assertJsonPath('data.items.0.lineTotal', '12.5000')
            ->assertJsonPath('data.storeId', $this->store->id)
            ->assertJsonPath('data.supplierId', $this->supplier->id);

        $this->assertMatchesRegularExpression('/^PO-\d{4}-\d{6}$/', $response->json('data.number'));
    }

    public function test_create_without_permission_returns_403(): void
    {
        Sanctum::actingAs(User::factory()->cashier()->create(), ['staff']);

        $this->postJson('/api/v1/purchase-orders', $this->validPayload())
            ->assertForbidden();
    }

    public function test_create_validation_errors_return_422(): void
    {
        Sanctum::actingAs(User::factory()->procurementOfficer()->create(), ['staff']);

        $this->postJson('/api/v1/purchase-orders', [
            'storeId' => $this->store->id,
            'supplierId' => $this->supplier->id,
            'items' => [],
        ])->assertStatus(422);

        $this->postJson('/api/v1/purchase-orders', $this->validPayload([
            'items' => [
                [
                    'productVariantId' => $this->variant->id,
                    'qty' => '0',
                    'unitCost' => '1.0000',
                ],
            ],
        ]))->assertStatus(422);

        $this->getJson('/api/v1/purchase-orders?status=not_a_real_status')
            ->assertStatus(422);
    }

    public function test_update_draft_and_rejected_but_not_pending(): void
    {
        $creator = User::factory()->procurementOfficer()->create();
        Sanctum::actingAs($creator, ['staff']);

        $id = $this->postJson('/api/v1/purchase-orders', $this->validPayload())
            ->assertCreated()
            ->json('data.id');

        $this->patchJson("/api/v1/purchase-orders/{$id}", [
            'notes' => 'Updated notes',
            'items' => [
                [
                    'productVariantId' => $this->variant->id,
                    'qty' => '3.0000',
                    'unitCost' => '4.0000',
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.notes', 'Updated notes')
            ->assertJsonPath('data.totalAmount', '12.0000');

        $this->postJson("/api/v1/purchase-orders/{$id}/submit")->assertOk();

        $this->patchJson("/api/v1/purchase-orders/{$id}", ['notes' => 'Nope'])
            ->assertStatus(422);

        $rejector = User::factory()->storeManager()->create();
        Sanctum::actingAs($rejector, ['staff']);
        $this->postJson("/api/v1/purchase-orders/{$id}/reject", ['reason' => 'Too costly'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejectionReason', 'Too costly')
            ->assertJsonPath('data.rejectedBy', $rejector->id)
            ->assertJsonPath('data.approvedBy', null);

        Sanctum::actingAs($creator, ['staff']);
        $this->patchJson("/api/v1/purchase-orders/{$id}", [
            'notes' => 'After reject',
            'items' => [
                [
                    'productVariantId' => $this->variant->id,
                    'qty' => '1.0000',
                    'unitCost' => '1.0000',
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.notes', 'After reject')
            ->assertJsonPath('data.totalAmount', '1.0000');
    }

    public function test_submit_approve_happy_path_and_audit(): void
    {
        $creator = User::factory()->procurementOfficer()->create();
        Sanctum::actingAs($creator, ['staff']);

        $id = $this->postJson('/api/v1/purchase-orders', $this->validPayload())
            ->assertCreated()
            ->json('data.id');

        $this->postJson("/api/v1/purchase-orders/{$id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_approval');

        $approver = User::factory()->storeManager()->create();
        Sanctum::actingAs($approver, ['staff']);

        $this->postJson("/api/v1/purchase-orders/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approvedBy', $approver->id)
            ->assertJsonPath('data.rejectedBy', null);

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $id,
            'status' => 'approved',
            'approved_by' => $approver->id,
        ]);

        $approveAudit = AuditLog::query()
            ->where('auditable_type', PurchaseOrder::class)
            ->where('auditable_id', $id)
            ->where('action', 'updated')
            ->get()
            ->first(fn (AuditLog $log) => ($log->after['status'] ?? null) === PurchaseOrderStatus::Approved->value
                || ($log->after['status'] ?? null) === 'approved');

        $this->assertNotNull($approveAudit);
        $this->assertSame('approved', $approveAudit->after['status'] ?? null);
    }

    public function test_reject_pending_purchase_order_and_audit(): void
    {
        Sanctum::actingAs(User::factory()->procurementOfficer()->create(), ['staff']);

        $id = $this->postJson('/api/v1/purchase-orders', $this->validPayload())
            ->json('data.id');
        $this->postJson("/api/v1/purchase-orders/{$id}/submit")->assertOk();

        $rejector = User::factory()->storeManager()->create();
        Sanctum::actingAs($rejector, ['staff']);

        $this->postJson("/api/v1/purchase-orders/{$id}/reject", ['reason' => 'Budget'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejectionReason', 'Budget')
            ->assertJsonPath('data.rejectedBy', $rejector->id)
            ->assertJsonPath('data.approvedBy', null);

        $rejectAudit = AuditLog::query()
            ->where('auditable_type', PurchaseOrder::class)
            ->where('auditable_id', $id)
            ->where('action', 'updated')
            ->get()
            ->first(fn (AuditLog $log) => ($log->after['status'] ?? null) === 'rejected');

        $this->assertNotNull($rejectAudit);
        $this->assertSame('rejected', $rejectAudit->after['status'] ?? null);
    }

    public function test_wrong_status_transitions_return_422(): void
    {
        Sanctum::actingAs(User::factory()->procurementOfficer()->create(), ['staff']);

        $id = $this->postJson('/api/v1/purchase-orders', $this->validPayload())
            ->json('data.id');

        Sanctum::actingAs(User::factory()->storeManager()->create(), ['staff']);
        $this->postJson("/api/v1/purchase-orders/{$id}/approve")->assertStatus(422);
        $this->postJson("/api/v1/purchase-orders/{$id}/reject")->assertStatus(422);

        Sanctum::actingAs(User::factory()->procurementOfficer()->create(), ['staff']);
        $this->postJson("/api/v1/purchase-orders/{$id}/submit")->assertOk();
        $this->postJson("/api/v1/purchase-orders/{$id}/submit")->assertStatus(422);
    }

    public function test_approve_without_permission_returns_403(): void
    {
        Sanctum::actingAs(User::factory()->procurementOfficer()->create(), ['staff']);
        $id = $this->postJson('/api/v1/purchase-orders', $this->validPayload())->json('data.id');
        $this->postJson("/api/v1/purchase-orders/{$id}/submit")->assertOk();

        Sanctum::actingAs(User::factory()->procurementOfficer()->create(), ['staff']);
        $this->postJson("/api/v1/purchase-orders/{$id}/approve")->assertForbidden();
        $this->postJson("/api/v1/purchase-orders/{$id}/reject")->assertForbidden();
    }

    public function test_update_submit_delete_without_create_permission_returns_403(): void
    {
        Sanctum::actingAs(User::factory()->procurementOfficer()->create(), ['staff']);
        $id = $this->postJson('/api/v1/purchase-orders', $this->validPayload())->json('data.id');

        Sanctum::actingAs(User::factory()->cashier()->create(), ['staff']);
        $this->patchJson("/api/v1/purchase-orders/{$id}", ['notes' => 'x'])->assertForbidden();
        $this->postJson("/api/v1/purchase-orders/{$id}/submit")->assertForbidden();
        $this->deleteJson("/api/v1/purchase-orders/{$id}")->assertForbidden();
    }

    public function test_dual_control_blocks_creator_self_approve(): void
    {
        Setting::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => null,
            'key' => 'purchase.dual_control',
            'value' => ['data' => true],
        ]);

        $creator = User::factory()->orgAdmin()->create();
        Sanctum::actingAs($creator, ['staff']);

        $id = $this->postJson('/api/v1/purchase-orders', $this->validPayload())->json('data.id');
        $this->postJson("/api/v1/purchase-orders/{$id}/submit")->assertOk();

        $this->postJson("/api/v1/purchase-orders/{$id}/approve")
            ->assertForbidden()
            ->assertJsonPath('message', 'Dual-control is enabled: the creator cannot approve or reject their own purchase order.');

        $this->postJson("/api/v1/purchase-orders/{$id}/reject")
            ->assertForbidden();

        Sanctum::actingAs(User::factory()->storeManager()->create(), ['staff']);
        $this->postJson("/api/v1/purchase-orders/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_dual_control_default_off_allows_creator_self_approve(): void
    {
        $creator = User::factory()->orgAdmin()->create();
        Sanctum::actingAs($creator, ['staff']);

        $id = $this->postJson('/api/v1/purchase-orders', $this->validPayload())->json('data.id');
        $this->postJson("/api/v1/purchase-orders/{$id}/submit")->assertOk();

        $this->postJson("/api/v1/purchase-orders/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.approvedBy', $creator->id);
    }

    public function test_list_includes_items_for_spa_edit_and_requires_permission(): void
    {
        $this->actAsProcurementOfficer();
        $id = $this->postJson('/api/v1/purchase-orders', $this->validPayload())->json('data.id');

        $this->getJson('/api/v1/purchase-orders')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.items.0.productVariantId', $this->variant->id)
            ->assertJsonPath('data.0.items.0.qty', '5.0000')
            ->assertJsonPath('data.0.items.0.unitCost', '2.5000');

        $this->getJson("/api/v1/purchase-orders/{$id}")->assertOk()->assertJsonPath('data.id', $id);

        Sanctum::actingAs(User::factory()->storeManager()->create(), ['staff']);
        $this->getJson('/api/v1/purchase-orders')->assertOk();

        Sanctum::actingAs(User::factory()->cashier()->create(), ['staff']);
        $this->getJson('/api/v1/purchase-orders')->assertForbidden();
    }

    public function test_unauthenticated_requests_return_401(): void
    {
        $this->getJson('/api/v1/purchase-orders')->assertUnauthorized();
        $this->postJson('/api/v1/purchase-orders', $this->validPayload())->assertUnauthorized();
    }

    public function test_cross_org_purchase_order_returns_404(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create(), ['staff']);

        $otherOrg = Organization::factory()->create(['name' => 'Other Org']);
        $otherStore = Store::factory()->create(['organization_id' => $otherOrg->id]);
        $otherSupplier = Supplier::query()->create([
            'organization_id' => $otherOrg->id,
            'name' => 'Foreign',
            'code' => 'FOR',
            'is_active' => true,
        ]);
        $foreignUser = User::factory()->procurementOfficer()->create();

        $foreignDraft = PurchaseOrder::query()->create([
            'organization_id' => $otherOrg->id,
            'store_id' => $otherStore->id,
            'supplier_id' => $otherSupplier->id,
            'number' => 'PO-2026-999999',
            'status' => 'draft',
            'total_amount' => '0.0000',
            'created_by' => $foreignUser->id,
        ]);

        $foreignPending = PurchaseOrder::query()->create([
            'organization_id' => $otherOrg->id,
            'store_id' => $otherStore->id,
            'supplier_id' => $otherSupplier->id,
            'number' => 'PO-2026-999998',
            'status' => 'pending_approval',
            'total_amount' => '0.0000',
            'created_by' => $foreignUser->id,
            'submitted_at' => now(),
        ]);

        $this->getJson("/api/v1/purchase-orders/{$foreignDraft->id}")->assertNotFound();
        $this->patchJson("/api/v1/purchase-orders/{$foreignDraft->id}", ['notes' => 'x'])->assertNotFound();
        $this->postJson("/api/v1/purchase-orders/{$foreignDraft->id}/submit")->assertNotFound();
        $this->deleteJson("/api/v1/purchase-orders/{$foreignDraft->id}")->assertNotFound();
        $this->postJson("/api/v1/purchase-orders/{$foreignPending->id}/approve")->assertNotFound();
        $this->postJson("/api/v1/purchase-orders/{$foreignPending->id}/reject")->assertNotFound();
    }

    public function test_filter_list_by_status(): void
    {
        $this->actAsProcurementOfficer();

        $draftId = $this->postJson('/api/v1/purchase-orders', $this->validPayload())->json('data.id');
        $pendingId = $this->postJson('/api/v1/purchase-orders', $this->validPayload([
            'notes' => 'Second',
        ]))->json('data.id');
        $this->postJson("/api/v1/purchase-orders/{$pendingId}/submit")->assertOk();

        $this->getJson('/api/v1/purchase-orders?status=draft')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $draftId);

        $this->getJson('/api/v1/purchase-orders?status=pending_approval')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $pendingId);
    }

    public function test_delete_draft_only(): void
    {
        Sanctum::actingAs(User::factory()->procurementOfficer()->create(), ['staff']);

        $id = $this->postJson('/api/v1/purchase-orders', $this->validPayload())->json('data.id');
        $this->deleteJson("/api/v1/purchase-orders/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('purchase_orders', ['id' => $id]);

        $id2 = $this->postJson('/api/v1/purchase-orders', $this->validPayload())->json('data.id');
        $this->postJson("/api/v1/purchase-orders/{$id2}/submit")->assertOk();
        $this->deleteJson("/api/v1/purchase-orders/{$id2}")->assertStatus(422);
    }

    public function test_resubmit_after_reject_clears_reject_fields(): void
    {
        Sanctum::actingAs(User::factory()->procurementOfficer()->create(), ['staff']);
        $id = $this->postJson('/api/v1/purchase-orders', $this->validPayload())->json('data.id');
        $this->postJson("/api/v1/purchase-orders/{$id}/submit")->assertOk();

        Sanctum::actingAs(User::factory()->storeManager()->create(), ['staff']);
        $this->postJson("/api/v1/purchase-orders/{$id}/reject", ['reason' => 'Fix qty'])->assertOk();

        Sanctum::actingAs(User::factory()->procurementOfficer()->create(), ['staff']);
        $this->postJson("/api/v1/purchase-orders/{$id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_approval')
            ->assertJsonPath('data.rejectedBy', null)
            ->assertJsonPath('data.rejectedAt', null)
            ->assertJsonPath('data.rejectionReason', null);
    }

    public function test_list_excludes_orders_for_unassigned_stores(): void
    {
        $this->actAsProcurementOfficer();
        $this->postJson('/api/v1/purchase-orders', $this->validPayload())->assertCreated();

        $otherStore = Store::factory()->create(['organization_id' => $this->orgId, 'name' => 'Annex']);
        $outsider = User::factory()->procurementOfficer()->create();
        $outsider->stores()->attach($otherStore->id, ['is_default' => true]);
        Sanctum::actingAs($outsider, ['staff']);

        $this->getJson('/api/v1/purchase-orders')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson('/api/v1/purchase-orders?storeId='.$this->store->id)
            ->assertForbidden();
    }

    private function actAsProcurementOfficer(): User
    {
        $user = User::factory()->procurementOfficer()->create();
        $user->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($user, ['staff']);

        return $user;
    }
}
