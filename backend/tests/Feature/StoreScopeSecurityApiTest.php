<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\Customers\Services\CustomerService;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Modules\Sales\Models\ProformaInvoice;
use Tests\TestCase;

class StoreScopeSecurityApiTest extends TestCase
{
    use RefreshDatabase;

    private int $orgId;

    private Store $storeA;

    private Store $storeB;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionMatrixSeeder::class);
        $ledger = app(InventoryLedgerService::class);
        $this->orgId = app(OrganizationService::class)->current()->id;
        $this->storeA = Store::factory()->create([
            'organization_id' => $this->orgId,
            'tax_config' => ['rate' => '0.0750', 'inclusive' => false, 'name' => 'VAT'],
        ]);
        $this->storeB = Store::factory()->create([
            'organization_id' => $this->orgId,
            'tax_config' => ['rate' => '0.0750', 'inclusive' => false, 'name' => 'VAT'],
        ]);

        $product = Product::factory()->create(['organization_id' => $this->orgId, 'sku' => 'SCOPE-P1']);
        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->orgId,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'SCOPE-V1',
            'price' => '10.0000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        foreach ([$this->storeA, $this->storeB] as $store) {
            $ledger->post(new PostMovementData(
                organizationId: $this->orgId,
                locationType: LocationType::Store,
                locationId: $store->id,
                productVariantId: $this->variant->id,
                type: MovementType::Adjustment,
                quantity: '10.0000',
                userId: User::factory()->inventoryManager()->create()->id,
                notes: 'Seed',
            ));
        }
    }

    public function test_receipt_endpoints_reject_cross_store_access(): void
    {
        $manager = User::factory()->storeManager()->create();
        $manager->stores()->attach($this->storeA->id, ['is_default' => true]);
        Sanctum::actingAs($manager, ['staff']);

        $sessionId = (int) $this->postJson('/api/v1/pos/sessions/open', [
            'terminalName' => 'SCOPE-A',
            'openingFloat' => '0.0000',
        ], ['X-Store-Id' => (string) $this->storeA->id])->assertCreated()->json('data.id');

        $saleId = (int) $this->postJson('/api/v1/pos/sales', [
            'uuid' => '990e8400-e29b-41d4-a716-446655440001',
            'sessionId' => $sessionId,
            'items' => [[
                'variantId' => $this->variant->id,
                'qty' => '1.0000',
                'unitPrice' => '10.0000',
                'discount' => '0.0000',
            ]],
            'payments' => [['method' => 'cash', 'amount' => '10.7500', 'provider' => 'manual']],
        ], [
            'Idempotency-Key' => 'scope-sale-a',
            'X-Store-Id' => (string) $this->storeA->id,
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/v1/pos/receipts/{$saleId}", ['X-Store-Id' => (string) $this->storeB->id])
            ->assertForbidden();
    }

    public function test_pricing_resolve_rejects_inaccessible_store(): void
    {
        $cashier = User::factory()->cashier()->create();
        $cashier->stores()->attach($this->storeA->id, ['is_default' => true]);
        Sanctum::actingAs($cashier, ['staff']);

        $this->postJson('/api/v1/pricing/resolve', [
            'variantId' => $this->variant->id,
            'storeId' => $this->storeB->id,
            'qty' => '1.0000',
        ])->assertForbidden();
    }

    public function test_inventory_reads_reject_inaccessible_store(): void
    {
        $this->actAsStoreAManager();

        $this->getJson('/api/v1/inventory/balances?storeId='.$this->storeB->id)->assertForbidden();
        $this->getJson('/api/v1/inventory/movements?storeId='.$this->storeB->id)->assertForbidden();
        $this->getJson('/api/v1/inventory/counts?storeId='.$this->storeB->id)->assertForbidden();
        $this->getJson('/api/v1/inventory/alerts/low-stock?storeId='.$this->storeB->id)->assertForbidden();
    }

    public function test_unfiltered_inventory_reads_only_return_assigned_stores(): void
    {
        $this->actAsStoreAManager();

        $balances = $this->getJson('/api/v1/inventory/balances')->assertOk();
        $this->assertSame(1, $balances->json('meta.total'));
        $this->assertSame($this->storeA->id, $balances->json('data.0.locationId'));

        $movements = $this->getJson('/api/v1/inventory/movements')->assertOk();
        $this->assertSame(1, $movements->json('meta.total'));
        $this->assertSame($this->storeA->id, $movements->json('data.0.locationId'));
    }

    public function test_inventory_mutations_reject_inaccessible_store_without_ledger_writes(): void
    {
        $this->actAsStoreAManager();
        $movementsBefore = InventoryMovement::query()->count();

        $this->postJson('/api/v1/inventory/adjustments', [
            'storeId' => $this->storeB->id,
            'productVariantId' => $this->variant->id,
            'quantity' => '5.0000',
            'reason' => 'Cross-store attempt',
        ])->assertForbidden();

        $this->postJson('/api/v1/inventory/counts', [
            'storeId' => $this->storeB->id,
            'items' => [[
                'productVariantId' => $this->variant->id,
                'countedQuantity' => '99.0000',
            ]],
        ])->assertForbidden();

        $this->postJson('/api/v1/inventory/transfers', [
            'fromStoreId' => $this->storeA->id,
            'toStoreId' => $this->storeB->id,
            'items' => [[
                'productVariantId' => $this->variant->id,
                'quantity' => '1.0000',
            ]],
        ])->assertForbidden();

        $this->assertSame($movementsBefore, InventoryMovement::query()->count());
        $this->assertSame('10.0000', $this->availableAt($this->storeB));
    }

    public function test_proforma_list_is_scoped_to_assigned_stores(): void
    {
        $manager = $this->actAsStoreAManager();
        $this->makeProforma($this->storeA, 'PF-A-1', $manager->id);
        $this->makeProforma($this->storeB, 'PF-B-1', $manager->id);

        $response = $this->getJson('/api/v1/proforma-invoices')->assertOk();
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($this->storeA->id, $response->json('data.0.storeId'));

        $this->getJson('/api/v1/proforma-invoices?storeId='.$this->storeB->id)->assertForbidden();
    }

    private function actAsStoreAManager(): User
    {
        $manager = User::factory()->storeManager()->create();
        $manager->stores()->attach($this->storeA->id, ['is_default' => true]);
        Sanctum::actingAs($manager, ['staff']);

        return $manager;
    }

    private function availableAt(Store $store): ?string
    {
        return app(InventoryLedgerService::class)->balanceFor(
            $this->orgId,
            LocationType::Store->value,
            $store->id,
            $this->variant->id,
        )?->available;
    }

    private function makeProforma(Store $store, string $number, int $userId): ProformaInvoice
    {
        return ProformaInvoice::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => $store->id,
            'customer_id' => app(CustomerService::class)->ensureWalkIn($this->orgId)->id,
            'number' => $number,
            'status' => 'draft',
            'subtotal' => '0.0000',
            'discount_total' => '0.0000',
            'tax_total' => '0.0000',
            'total' => '0.0000',
            'created_by' => $userId,
        ]);
    }
}
