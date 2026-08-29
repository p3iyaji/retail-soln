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
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
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
}
