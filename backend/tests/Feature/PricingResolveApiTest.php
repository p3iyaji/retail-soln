<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Pricing\Models\Price;
use Modules\Pricing\Models\PriceList;
use Modules\Pricing\Models\Promotion;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Tests\TestCase;

class PricingResolveApiTest extends TestCase
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
        $this->store = Store::factory()->create([
            'organization_id' => $this->orgId,
            'name' => 'Pricing Store',
            'tax_config' => [
                'rate' => '0.1000',
                'inclusive' => false,
                'name' => 'VAT',
            ],
        ]);

        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'sku' => 'PR-P1',
        ]);

        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->orgId,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'PR-V1',
            'price' => '20.0000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    public function test_resolve_uses_store_list_price(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create(), ['staff']);

        $list = PriceList::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => $this->store->id,
            'name' => 'Store Retail',
            'is_active' => true,
            'is_default' => false,
        ]);
        Price::query()->create([
            'organization_id' => $this->orgId,
            'price_list_id' => $list->id,
            'product_variant_id' => $this->variant->id,
            'amount' => '25.0000',
        ]);

        $this->postJson('/api/v1/pricing/resolve', [
            'variantId' => $this->variant->id,
            'storeId' => $this->store->id,
            'qty' => '1',
        ])->assertOk()
            ->assertJsonPath('data.listPrice', '25.0000')
            ->assertJsonPath('data.unitPrice', '25.0000')
            ->assertJsonPath('data.tax.rate', '0.1000')
            ->assertJsonPath('data.tax.amount', '2.5000')
            ->assertJsonPath('data.grossPrice', '27.5000');
    }

    public function test_resolve_falls_back_to_variant_price(): void
    {
        $cashier = User::factory()->cashier()->create();
        $cashier->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($cashier, ['staff']);

        $this->postJson('/api/v1/pricing/resolve', [
            'variantId' => $this->variant->id,
            'storeId' => $this->store->id,
        ])->assertOk()
            ->assertJsonPath('data.listPrice', '20.0000')
            ->assertJsonPath('data.unitPrice', '20.0000')
            ->assertJsonPath('data.discountAmount', '0.0000')
            ->assertJsonPath('data.priceListId', null);
    }

    public function test_resolve_applies_percent_promo(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create(), ['staff']);

        $this->createOrgListPrice('20.0000');
        $this->createPromo('10% Off', 'percent', '10.0000');

        $this->postJson('/api/v1/pricing/resolve', [
            'variantId' => $this->variant->id,
            'storeId' => $this->store->id,
            'qty' => '2',
        ])->assertOk()
            ->assertJsonPath('data.listPrice', '20.0000')
            ->assertJsonPath('data.discountAmount', '4.0000')
            ->assertJsonPath('data.unitPrice', '18.0000')
            ->assertJsonPath('data.appliedPromotions.0.type', 'percent')
            ->assertJsonPath('data.netPrice', '36.0000')
            ->assertJsonPath('data.tax.amount', '3.6000')
            ->assertJsonPath('data.grossPrice', '39.6000');
    }

    public function test_resolve_applies_fixed_promo_capped_at_unit(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create(), ['staff']);

        $this->createOrgListPrice('5.0000');
        $this->createPromo('Big Off', 'fixed', '10.0000');

        $this->postJson('/api/v1/pricing/resolve', [
            'variantId' => $this->variant->id,
            'storeId' => $this->store->id,
            'qty' => '1',
        ])->assertOk()
            ->assertJsonPath('data.discountAmount', '5.0000')
            ->assertJsonPath('data.unitPrice', '0.0000')
            ->assertJsonPath('data.appliedPromotions.0.type', 'fixed');
    }

    public function test_resolve_applies_simple_bogo(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create(), ['staff']);

        $this->createOrgListPrice('10.0000');

        Promotion::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => null,
            'name' => 'BOGO',
            'is_active' => true,
        ])->rules()->create([
            'type' => 'bogo',
            'value' => '0',
            'buy_qty' => '2.0000',
            'get_qty' => '1.0000',
            'get_discount_percent' => '100.0000',
        ]);

        // qty 3 → one free unit → discount 10
        $this->postJson('/api/v1/pricing/resolve', [
            'variantId' => $this->variant->id,
            'storeId' => $this->store->id,
            'qty' => '3',
        ])->assertOk()
            ->assertJsonPath('data.discountAmount', '10.0000')
            ->assertJsonPath('data.appliedPromotions.0.type', 'bogo')
            ->assertJsonPath('data.netPrice', '20.0000');
    }

    public function test_resolve_tax_exclusive(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create(), ['staff']);

        $this->postJson('/api/v1/pricing/resolve', [
            'variantId' => $this->variant->id,
            'storeId' => $this->store->id,
            'qty' => '1',
        ])->assertOk()
            ->assertJsonPath('data.tax.inclusive', false)
            ->assertJsonPath('data.tax.amount', '2.0000')
            ->assertJsonPath('data.netPrice', '20.0000')
            ->assertJsonPath('data.grossPrice', '22.0000');
    }

    public function test_resolve_tax_inclusive(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create(), ['staff']);

        $this->store->update([
            'tax_config' => [
                'rate' => '0.1000',
                'inclusive' => true,
                'name' => 'Inc VAT',
            ],
        ]);

        // gross 20; net = 20/1.1 = 18.1818; tax = 1.8182
        $this->postJson('/api/v1/pricing/resolve', [
            'variantId' => $this->variant->id,
            'storeId' => $this->store->id,
            'qty' => '1',
        ])->assertOk()
            ->assertJsonPath('data.tax.inclusive', true)
            ->assertJsonPath('data.grossPrice', '20.0000')
            ->assertJsonPath('data.netPrice', '18.1818')
            ->assertJsonPath('data.tax.amount', '1.8182');
    }

    public function test_resolve_missing_variant_or_store_returns_422(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create(), ['staff']);

        $this->postJson('/api/v1/pricing/resolve', [
            'variantId' => 999999,
            'storeId' => $this->store->id,
        ])->assertStatus(422);

        $this->postJson('/api/v1/pricing/resolve', [
            'variantId' => $this->variant->id,
            'storeId' => 999999,
        ])->assertStatus(422);
    }

    public function test_crud_unauthorized_without_manage_permission(): void
    {
        Sanctum::actingAs(User::factory()->cashier()->create(), ['staff']);

        $this->postJson('/api/v1/price-lists', [
            'name' => 'Retail',
        ])->assertForbidden();

        $this->postJson('/api/v1/promotions', [
            'name' => 'Promo',
            'rules' => [['type' => 'percent', 'value' => '5']],
        ])->assertForbidden();
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->postJson('/api/v1/pricing/resolve', [
            'variantId' => $this->variant->id,
            'storeId' => $this->store->id,
        ])->assertUnauthorized();

        $this->getJson('/api/v1/price-lists')->assertUnauthorized();
    }

    public function test_price_list_and_promotion_crud_happy_path(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create(), ['staff']);

        $listId = $this->postJson('/api/v1/price-lists', [
            'name' => 'Retail',
            'isDefault' => true,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Retail')
            ->assertJsonPath('data.isDefault', true)
            ->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => PriceList::class,
            'auditable_id' => $listId,
        ]);

        $this->postJson('/api/v1/prices', [
            'priceListId' => $listId,
            'productVariantId' => $this->variant->id,
            'amount' => '22.5000',
        ])->assertCreated()
            ->assertJsonPath('data.amount', '22.5000');

        $promoId = $this->postJson('/api/v1/promotions', [
            'name' => 'Spring',
            'rules' => [
                ['type' => 'percent', 'value' => '15.0000', 'productVariantId' => $this->variant->id],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.rules.0.type', 'percent')
            ->json('data.id');

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Promotion::class,
            'auditable_id' => $promoId,
        ]);

        $this->getJson('/api/v1/price-lists')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/promotions')->assertOk()->assertJsonCount(1, 'data');

        $this->postJson('/api/v1/pricing/resolve', [
            'variantId' => $this->variant->id,
            'storeId' => $this->store->id,
            'qty' => '1',
            'customerId' => 1,
        ])->assertOk()
            ->assertJsonPath('data.listPrice', '22.5000')
            ->assertJsonPath('data.discountAmount', '3.3750');
    }

    public function test_resolve_prefers_store_list_over_org_default(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create(), ['staff']);

        $orgList = PriceList::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => null,
            'name' => 'Retail',
            'is_active' => true,
            'is_default' => true,
        ]);
        Price::query()->create([
            'organization_id' => $this->orgId,
            'price_list_id' => $orgList->id,
            'product_variant_id' => $this->variant->id,
            'amount' => '30.0000',
        ]);

        $storeList = PriceList::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => $this->store->id,
            'name' => 'Store Special',
            'is_active' => true,
            'is_default' => false,
        ]);
        Price::query()->create([
            'organization_id' => $this->orgId,
            'price_list_id' => $storeList->id,
            'product_variant_id' => $this->variant->id,
            'amount' => '28.0000',
        ]);

        $this->postJson('/api/v1/pricing/resolve', [
            'variantId' => $this->variant->id,
            'storeId' => $this->store->id,
        ])->assertOk()
            ->assertJsonPath('data.listPrice', '28.0000')
            ->assertJsonPath('data.priceListId', $storeList->id);
    }

    public function test_inactive_price_list_ignored_falls_back(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create(), ['staff']);

        $inactive = PriceList::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => null,
            'name' => 'Old Retail',
            'is_active' => false,
            'is_default' => true,
        ]);
        Price::query()->create([
            'organization_id' => $this->orgId,
            'price_list_id' => $inactive->id,
            'product_variant_id' => $this->variant->id,
            'amount' => '99.0000',
        ]);

        $this->postJson('/api/v1/pricing/resolve', [
            'variantId' => $this->variant->id,
            'storeId' => $this->store->id,
        ])->assertOk()
            ->assertJsonPath('data.listPrice', '20.0000')
            ->assertJsonPath('data.priceListId', null);

        $active = PriceList::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => null,
            'name' => 'Active Retail',
            'is_active' => true,
            'is_default' => true,
        ]);
        Price::query()->create([
            'organization_id' => $this->orgId,
            'price_list_id' => $active->id,
            'product_variant_id' => $this->variant->id,
            'amount' => '21.5000',
        ]);

        $this->postJson('/api/v1/pricing/resolve', [
            'variantId' => $this->variant->id,
            'storeId' => $this->store->id,
        ])->assertOk()
            ->assertJsonPath('data.listPrice', '21.5000')
            ->assertJsonPath('data.priceListId', $active->id);
    }

    public function test_expired_inactive_wrong_store_promo_gives_zero_discount(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create(), ['staff']);
        $this->createOrgListPrice('20.0000');

        $otherStore = Store::factory()->create([
            'organization_id' => $this->orgId,
            'name' => 'Other Store',
        ]);

        Promotion::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => null,
            'name' => 'Expired',
            'is_active' => true,
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDay(),
        ])->rules()->create(['type' => 'percent', 'value' => '50.0000']);

        Promotion::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => null,
            'name' => 'Inactive',
            'is_active' => false,
        ])->rules()->create(['type' => 'percent', 'value' => '40.0000']);

        Promotion::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => $otherStore->id,
            'name' => 'Wrong Store',
            'is_active' => true,
        ])->rules()->create(['type' => 'percent', 'value' => '30.0000']);

        $this->postJson('/api/v1/pricing/resolve', [
            'variantId' => $this->variant->id,
            'storeId' => $this->store->id,
            'qty' => '1',
        ])->assertOk()
            ->assertJsonPath('data.discountAmount', '0.0000')
            ->assertJsonPath('data.appliedPromotions', []);
    }

    public function test_competing_promos_apply_best_single_only(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create(), ['staff']);
        $this->createOrgListPrice('100.0000');

        $this->createPromo('Five Off', 'percent', '5.0000');
        $this->createPromo('Twenty Off', 'percent', '20.0000');
        $this->createPromo('Fixed Ten', 'fixed', '10.0000');

        $response = $this->postJson('/api/v1/pricing/resolve', [
            'variantId' => $this->variant->id,
            'storeId' => $this->store->id,
            'qty' => '1',
        ])->assertOk()
            ->assertJsonPath('data.discountAmount', '20.0000')
            ->assertJsonPath('data.appliedPromotions.0.type', 'percent')
            ->assertJsonPath('data.appliedPromotions.0.name', 'Twenty Off');

        $this->assertCount(1, $response->json('data.appliedPromotions'));
    }

    public function test_resolve_forbidden_without_resolve_permissions(): void
    {
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        $this->postJson('/api/v1/pricing/resolve', [
            'variantId' => $this->variant->id,
            'storeId' => $this->store->id,
        ])->assertForbidden();
    }

    public function test_empty_tax_config_yields_zero_tax(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create(), ['staff']);

        $this->store->update(['tax_config' => null]);

        $this->postJson('/api/v1/pricing/resolve', [
            'variantId' => $this->variant->id,
            'storeId' => $this->store->id,
            'qty' => '1',
        ])->assertOk()
            ->assertJsonPath('data.tax.rate', '0.0000')
            ->assertJsonPath('data.tax.amount', '0.0000')
            ->assertJsonPath('data.grossPrice', '20.0000')
            ->assertJsonPath('data.netPrice', '20.0000');

        $this->store->update(['tax_config' => []]);

        $this->postJson('/api/v1/pricing/resolve', [
            'variantId' => $this->variant->id,
            'storeId' => $this->store->id,
            'qty' => '1',
        ])->assertOk()
            ->assertJsonPath('data.tax.amount', '0.0000');
    }

    public function test_org_default_list_wins_over_non_default_when_both_price_variant(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create(), ['staff']);

        // Create non-default first so it would win if prices.id order were used.
        $nonDefault = PriceList::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => null,
            'name' => 'Wholesale',
            'is_active' => true,
            'is_default' => false,
        ]);
        Price::query()->create([
            'organization_id' => $this->orgId,
            'price_list_id' => $nonDefault->id,
            'product_variant_id' => $this->variant->id,
            'amount' => '15.0000',
        ]);

        $default = PriceList::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => null,
            'name' => 'Retail',
            'is_active' => true,
            'is_default' => true,
        ]);
        Price::query()->create([
            'organization_id' => $this->orgId,
            'price_list_id' => $default->id,
            'product_variant_id' => $this->variant->id,
            'amount' => '22.0000',
        ]);

        $this->postJson('/api/v1/pricing/resolve', [
            'variantId' => $this->variant->id,
            'storeId' => $this->store->id,
        ])->assertOk()
            ->assertJsonPath('data.listPrice', '22.0000')
            ->assertJsonPath('data.priceListId', $default->id);
    }

    public function test_inactive_variant_returns_422(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create(), ['staff']);
        $this->variant->update(['is_active' => false]);

        $this->postJson('/api/v1/pricing/resolve', [
            'variantId' => $this->variant->id,
            'storeId' => $this->store->id,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Variant is inactive.');
    }

    public function test_qty_zero_decimal_rejected(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create(), ['staff']);

        $this->postJson('/api/v1/pricing/resolve', [
            'variantId' => $this->variant->id,
            'storeId' => $this->store->id,
            'qty' => '0.0000',
        ])->assertStatus(422);
    }

    public function test_updating_price_list_to_store_clears_default(): void
    {
        Sanctum::actingAs(User::factory()->orgAdmin()->create(), ['staff']);

        $listId = $this->postJson('/api/v1/price-lists', [
            'name' => 'Retail',
            'isDefault' => true,
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/price-lists/{$listId}", [
            'storeId' => $this->store->id,
        ])->assertOk()
            ->assertJsonPath('data.storeId', $this->store->id)
            ->assertJsonPath('data.isDefault', false);
    }

    private function createOrgListPrice(string $amount): PriceList
    {
        $list = PriceList::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => null,
            'name' => 'Retail',
            'is_active' => true,
            'is_default' => true,
        ]);
        Price::query()->create([
            'organization_id' => $this->orgId,
            'price_list_id' => $list->id,
            'product_variant_id' => $this->variant->id,
            'amount' => $amount,
        ]);

        return $list;
    }

    private function createPromo(string $name, string $type, string $value): Promotion
    {
        $promo = Promotion::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => null,
            'name' => $name,
            'is_active' => true,
        ]);
        $promo->rules()->create([
            'type' => $type,
            'value' => $value,
        ]);

        return $promo;
    }
}
