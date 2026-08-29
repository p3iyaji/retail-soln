<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\CustomerPortal\Models\CustomerAccount;
use Modules\Customers\Enums\CustomerType;
use Modules\Customers\Models\Customer;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;
use Modules\Products\Models\Category;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Tests\TestCase;

class PortalCatalogueApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Store $store;

    private Category $category;

    private Product $activeProduct;

    private ProductVariant $activeVariant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'name' => 'Portal Catalogue Org',
        ]);
        config(['customer_portal.organization_id' => $this->organization->id]);

        $this->store = Store::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Ikeja Store A',
            'is_active' => true,
        ]);

        $this->category = Category::query()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Beverages',
            'slug' => 'beverages',
            'is_active' => true,
        ]);

        $this->activeProduct = Product::factory()->create([
            'organization_id' => $this->organization->id,
            'category_id' => $this->category->id,
            'name' => 'Peak Milk 400g',
            'sku' => 'SKU-1042',
            'selling_price' => '1850.0000',
            'is_active' => true,
        ]);

        $this->activeVariant = ProductVariant::query()->create([
            'organization_id' => $this->organization->id,
            'product_id' => $this->activeProduct->id,
            'name' => 'Default',
            'sku' => 'SKU-1042-D',
            'price' => '1850.0000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        app(InventoryLedgerService::class)->post(new PostMovementData(
            organizationId: $this->organization->id,
            locationType: LocationType::Store,
            locationId: $this->store->id,
            productVariantId: $this->activeVariant->id,
            type: MovementType::Purchase,
            quantity: '12.0000',
            unitCost: '1000.0000',
        ));
    }

    public function test_guest_lists_catalogue_with_price_and_stock_signal(): void
    {
        $response = $this->getJson('/api/v1/portal/catalogue/products');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.storeId', $this->store->id)
            ->assertJsonPath('data.0.id', $this->activeProduct->id)
            ->assertJsonPath('data.0.name', 'Peak Milk 400g')
            ->assertJsonPath('data.0.price.listPrice', '1850.0000')
            ->assertJsonPath('data.0.price.amount', '1988.7500')
            ->assertJsonPath('data.0.stock.inStock', true)
            ->assertJsonPath('data.0.stock.storeName', 'Ikeja Store A');

        $this->assertStringContainsString('In stock', (string) $response->json('data.0.stock.label'));
        $this->assertArrayNotHasKey('available', $response->json('data.0.stock'));
        $this->assertArrayNotHasKey('reserved', $response->json('data.0.stock'));
    }

    public function test_guest_lists_categories(): void
    {
        Category::query()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Inactive Cat',
            'slug' => 'inactive-cat',
            'is_active' => false,
        ]);

        $this->getJson('/api/v1/portal/catalogue/categories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Beverages');
    }

    public function test_search_filters_active_products(): void
    {
        $other = Product::factory()->create([
            'organization_id' => $this->organization->id,
            'category_id' => $this->category->id,
            'name' => 'Indomie Chicken',
            'sku' => 'SKU-2201',
            'is_active' => true,
        ]);
        ProductVariant::query()->create([
            'organization_id' => $this->organization->id,
            'product_id' => $other->id,
            'name' => 'Default',
            'sku' => 'SKU-2201-D',
            'price' => '100.0000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->getJson('/api/v1/portal/catalogue/products?q=Peak')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.sku', 'SKU-1042');

        $this->getJson('/api/v1/portal/catalogue/products?q=NomatchXYZ')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_category_filter(): void
    {
        $snacks = Category::query()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Snacks',
            'slug' => 'snacks',
            'is_active' => true,
        ]);
        $snackProduct = Product::factory()->create([
            'organization_id' => $this->organization->id,
            'category_id' => $snacks->id,
            'name' => 'Chips',
            'sku' => 'SKU-SNACK',
            'is_active' => true,
        ]);
        ProductVariant::query()->create([
            'organization_id' => $this->organization->id,
            'product_id' => $snackProduct->id,
            'name' => 'Default',
            'sku' => 'SKU-SNACK-D',
            'price' => '50.0000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->getJson('/api/v1/portal/catalogue/products?categoryId='.$this->category->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $this->activeProduct->id);
    }

    public function test_product_detail_includes_price_stock_and_variants(): void
    {
        $this->getJson('/api/v1/portal/catalogue/products/'.$this->activeProduct->id)
            ->assertOk()
            ->assertJsonPath('data.id', $this->activeProduct->id)
            ->assertJsonPath('data.price.listPrice', '1850.0000')
            ->assertJsonPath('data.price.amount', '1988.7500')
            ->assertJsonPath('data.stock.inStock', true)
            ->assertJsonPath('data.stock.label', 'In stock · Ikeja Store A')
            ->assertJsonPath('data.variants.0.id', $this->activeVariant->id)
            ->assertJsonPath('data.store.name', 'Ikeja Store A');

        $stock = $this->getJson('/api/v1/portal/catalogue/products/'.$this->activeProduct->id)->json('data.stock');
        $this->assertIsArray($stock);
        $this->assertArrayNotHasKey('available', $stock);
        $this->assertArrayNotHasKey('reserved', $stock);
    }

    public function test_inactive_product_returns_404(): void
    {
        $inactive = Product::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Hidden',
            'sku' => 'SKU-HIDDEN',
            'is_active' => false,
        ]);
        ProductVariant::query()->create([
            'organization_id' => $this->organization->id,
            'product_id' => $inactive->id,
            'name' => 'Default',
            'sku' => 'SKU-HIDDEN-D',
            'price' => '10.0000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->getJson('/api/v1/portal/catalogue/products/'.$inactive->id)
            ->assertNotFound();
    }

    public function test_foreign_org_product_returns_404(): void
    {
        $otherOrg = Organization::factory()->create();
        $foreign = Product::factory()->create([
            'organization_id' => $otherOrg->id,
            'name' => 'Foreign',
            'sku' => 'SKU-FOREIGN',
            'is_active' => true,
        ]);
        ProductVariant::query()->create([
            'organization_id' => $otherOrg->id,
            'product_id' => $foreign->id,
            'name' => 'Default',
            'sku' => 'SKU-FOREIGN-D',
            'price' => '10.0000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->getJson('/api/v1/portal/catalogue/products/'.$foreign->id)
            ->assertNotFound();
    }

    public function test_invalid_store_id_returns_422(): void
    {
        $this->getJson('/api/v1/portal/catalogue/products?storeId=999999')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['storeId']);
    }

    public function test_missing_store_returns_null_price_and_check_availability(): void
    {
        $this->store->update(['is_active' => false]);
        config(['customer_portal.default_store_id' => null]);

        $this->getJson('/api/v1/portal/catalogue/products/'.$this->activeProduct->id)
            ->assertOk()
            ->assertJsonPath('data.price', null)
            ->assertJsonPath('data.stock.label', 'Check availability')
            ->assertJsonPath('data.stock.inStock', null);
    }

    public function test_out_of_stock_label_when_available_zero(): void
    {
        app(InventoryLedgerService::class)->post(new PostMovementData(
            organizationId: $this->organization->id,
            locationType: LocationType::Store,
            locationId: $this->store->id,
            productVariantId: $this->activeVariant->id,
            type: MovementType::Adjustment,
            quantity: '-12.0000',
        ));

        $this->getJson('/api/v1/portal/catalogue/products/'.$this->activeProduct->id)
            ->assertOk()
            ->assertJsonPath('data.stock.inStock', false)
            ->assertJsonPath('data.stock.label', 'Out of stock');
    }

    public function test_portal_token_rejected_on_staff_products_pricing_and_inventory(): void
    {
        $account = $this->makePortalAccount('catalogue-portal@example.com');
        $token = $account->createToken('portal', ['portal'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/products')
            ->assertUnauthorized();

        $this->withToken($token)
            ->postJson('/api/v1/pricing/resolve', [
                'variantId' => $this->activeVariant->id,
                'storeId' => $this->store->id,
                'qty' => '1',
            ])
            ->assertUnauthorized();

        $this->withToken($token)
            ->getJson('/api/v1/inventory/balances')
            ->assertUnauthorized();
    }

    public function test_product_with_only_inactive_variants_excluded_from_list_and_show(): void
    {
        $dormant = Product::factory()->create([
            'organization_id' => $this->organization->id,
            'category_id' => $this->category->id,
            'name' => 'All Variants Off',
            'sku' => 'SKU-INACTIVE-VAR',
            'is_active' => true,
        ]);
        ProductVariant::query()->create([
            'organization_id' => $this->organization->id,
            'product_id' => $dormant->id,
            'name' => 'Off',
            'sku' => 'SKU-INACTIVE-VAR-D',
            'price' => '10.0000',
            'is_default' => true,
            'is_active' => false,
            'sort_order' => 0,
        ]);

        $this->getJson('/api/v1/portal/catalogue/products')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $this->activeProduct->id);

        $this->getJson('/api/v1/portal/catalogue/products/'.$dormant->id)
            ->assertNotFound();
    }

    public function test_staff_token_may_use_public_catalogue_but_not_portal_account(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api', ['staff'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/portal/catalogue/products')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->withToken($token)
            ->getJson('/api/v1/portal/auth/me')
            ->assertUnauthorized();
    }

    public function test_empty_catalogue_returns_empty_page(): void
    {
        $this->activeProduct->update(['is_active' => false]);

        $this->getJson('/api/v1/portal/catalogue/products')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_default_store_id_config_is_used(): void
    {
        $other = Store::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Other Store',
            'is_active' => true,
        ]);
        // Lower id store stays $this->store; config should prefer other.
        config(['customer_portal.default_store_id' => $other->id]);

        $this->getJson('/api/v1/portal/catalogue/products')
            ->assertOk()
            ->assertJsonPath('meta.storeId', $other->id)
            ->assertJsonPath('meta.storeName', 'Other Store');
    }

    private function makePortalAccount(string $email): CustomerAccount
    {
        $customer = Customer::query()->create([
            'organization_id' => $this->organization->id,
            'type' => CustomerType::Registered,
            'name' => 'Portal User',
            'email' => $email,
            'account_number' => 'C-PORTAL-CAT',
            'credit_limit' => '0.0000',
            'balance' => '0.0000',
            'is_active' => true,
        ]);

        return CustomerAccount::query()->create([
            'organization_id' => $this->organization->id,
            'customer_id' => $customer->id,
            'email' => $email,
            'password' => Hash::make('Password1!'),
            'email_verified_at' => now(),
        ]);
    }
}
