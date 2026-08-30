<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\Brand;
use Modules\Products\Models\Category;
use Modules\Products\Models\Product;
use Modules\Products\Models\Supplier;
use Modules\Products\Models\Unit;
use Tests\TestCase;

class ProductCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionMatrixSeeder::class);
    }

    public function test_inventory_manager_can_crud_product_with_catalog_fields(): void
    {
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);
        $orgId = app(OrganizationService::class)->current()->id;

        $category = Category::query()->create([
            'organization_id' => $orgId,
            'name' => 'Beverages',
            'slug' => 'beverages',
        ]);
        $brand = Brand::query()->create([
            'organization_id' => $orgId,
            'name' => 'Acme',
            'slug' => 'acme',
        ]);
        $unit = Unit::query()->create([
            'organization_id' => $orgId,
            'name' => 'Each',
            'abbreviation' => 'ea',
        ]);
        $supplier = Supplier::query()->create([
            'organization_id' => $orgId,
            'name' => 'Wholesale Co',
            'code' => 'WS1',
        ]);

        $create = $this->postJson('/api/v1/products', [
            'name' => 'Cola 500ml',
            'sku' => 'COLA-500',
            'barcode' => '1234567890123',
            'description' => 'Soft drink',
            'categoryId' => $category->id,
            'brandId' => $brand->id,
            'unitId' => $unit->id,
            'supplierId' => $supplier->id,
            'sellingPrice' => '2.5000',
            'costPrice' => '1.2500',
            'reorderLevel' => '10.0000',
            'minStock' => '5.0000',
            'maxStock' => '200.0000',
            'images' => [
                [
                    'url' => 'https://cdn.example.com/cola.jpg',
                    'altText' => 'Cola bottle',
                    'sortOrder' => 0,
                    'isPrimary' => true,
                ],
            ],
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.name', 'Cola 500ml')
            ->assertJsonPath('data.sku', 'COLA-500')
            ->assertJsonPath('data.barcode', '1234567890123')
            ->assertJsonPath('data.categoryId', $category->id)
            ->assertJsonPath('data.brandId', $brand->id)
            ->assertJsonPath('data.unitId', $unit->id)
            ->assertJsonPath('data.supplierId', $supplier->id)
            ->assertJsonPath('data.sellingPrice', '2.5000')
            ->assertJsonPath('data.reorderLevel', '10.0000')
            ->assertJsonPath('data.minStock', '5.0000')
            ->assertJsonPath('data.maxStock', '200.0000')
            ->assertJsonPath('data.images.0.url', 'https://cdn.example.com/cola.jpg');

        $productId = $create->json('data.id');

        $create->assertJsonPath('data.variants.0.name', 'Default')
            ->assertJsonPath('data.variants.0.sku', 'COLA-500')
            ->assertJsonPath('data.variants.0.barcode', '1234567890123')
            ->assertJsonPath('data.variants.0.price', '2.5000')
            ->assertJsonPath('data.variants.0.isDefault', true);

        $this->assertDatabaseHas('product_variants', [
            'product_id' => $productId,
            'sku' => 'COLA-500',
            'is_default' => true,
        ]);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->patchJson("/api/v1/products/{$productId}", [
            'name' => 'Cola 500ml Zero',
            'reorderLevel' => '12.0000',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Cola 500ml Zero')
            ->assertJsonPath('data.reorderLevel', '12.0000');

        // Re-submitting the same product SKU (shared with the default variant) must succeed.
        $this->patchJson("/api/v1/products/{$productId}", [
            'name' => 'Cola 500ml Zero',
            'sku' => 'COLA-500',
            'barcode' => '1234567890123',
            'sellingPrice' => '2.5000',
        ])
            ->assertOk()
            ->assertJsonPath('data.sku', 'COLA-500');

        $this->deleteJson("/api/v1/products/{$productId}")
            ->assertOk();

        $this->assertDatabaseMissing('products', ['id' => $productId]);
    }

    public function test_cashier_cannot_manage_products_but_can_view_catalog(): void
    {
        Sanctum::actingAs(User::factory()->cashier()->create(), ['staff']);
        $orgId = app(OrganizationService::class)->current()->id;

        Product::factory()->create([
            'organization_id' => $orgId,
            'name' => 'Visible Item',
            'sku' => 'VIS-1',
        ]);

        $this->getJson('/api/v1/products')->assertOk();
        $this->getJson('/api/v1/categories')->assertOk();

        $this->postJson('/api/v1/products', [
            'name' => 'Nope',
            'sku' => 'NOPE',
            'sellingPrice' => '1.00',
        ])->assertForbidden();
    }

    public function test_inventory_manager_can_create_categories_brands_units_suppliers(): void
    {
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        $this->postJson('/api/v1/categories', ['name' => 'Snacks'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Snacks');

        $this->postJson('/api/v1/brands', ['name' => 'YumCo'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'YumCo');

        $this->postJson('/api/v1/units', [
            'name' => 'Pack',
            'abbreviation' => 'pk',
        ])
            ->assertCreated()
            ->assertJsonPath('data.abbreviation', 'pk');

        $this->postJson('/api/v1/suppliers', [
            'name' => 'Local Foods',
            'code' => 'LF',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Local Foods');

        $this->getJson('/api/v1/brands')->assertOk()->assertJsonPath('data.0.name', 'YumCo');
    }

    public function test_duplicate_sku_is_rejected_within_organization(): void
    {
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);
        $orgId = app(OrganizationService::class)->current()->id;

        Product::factory()->create([
            'organization_id' => $orgId,
            'sku' => 'DUP-1',
        ]);

        $this->postJson('/api/v1/products', [
            'name' => 'Other',
            'sku' => 'DUP-1',
            'sellingPrice' => '3.00',
        ])->assertStatus(422);
    }
}
