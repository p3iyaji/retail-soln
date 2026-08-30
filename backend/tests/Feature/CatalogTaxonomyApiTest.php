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
use Modules\Products\Models\Unit;
use Tests\TestCase;

class CatalogTaxonomyApiTest extends TestCase
{
    use RefreshDatabase;

    private int $orgId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionMatrixSeeder::class);
        $this->orgId = app(OrganizationService::class)->current()->id;
    }

    public function test_inventory_manager_can_crud_categories_brands_and_units(): void
    {
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        $categoryId = $this->postJson('/api/v1/categories', [
            'name' => 'Beverages',
            'slug' => 'beverages',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Beverages')
            ->json('data.id');

        $this->patchJson("/api/v1/categories/{$categoryId}", [
            'name' => 'Drinks',
            'isActive' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Drinks')
            ->assertJsonPath('data.slug', 'drinks');

        $brandId = $this->postJson('/api/v1/brands', ['name' => 'Acme'])
            ->assertCreated()
            ->json('data.id');

        $this->patchJson("/api/v1/brands/{$brandId}", ['name' => 'Acme Foods'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Acme Foods');

        $unitId = $this->postJson('/api/v1/units', [
            'name' => 'Case',
            'abbreviation' => 'cs',
        ])
            ->assertCreated()
            ->json('data.id');

        $this->patchJson("/api/v1/units/{$unitId}", [
            'name' => 'Case of 12',
            'abbreviation' => 'cs12',
        ])
            ->assertOk()
            ->assertJsonPath('data.abbreviation', 'cs12');

        $this->deleteJson("/api/v1/categories/{$categoryId}")->assertNoContent();
        $this->deleteJson("/api/v1/brands/{$brandId}")->assertNoContent();
        $this->deleteJson("/api/v1/units/{$unitId}")->assertNoContent();

        $this->assertDatabaseMissing('categories', ['id' => $categoryId]);
        $this->assertDatabaseMissing('brands', ['id' => $brandId]);
        $this->assertDatabaseMissing('units', ['id' => $unitId]);
    }

    public function test_delete_deactivates_taxonomy_linked_to_products(): void
    {
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        $category = Category::query()->create([
            'organization_id' => $this->orgId,
            'name' => 'Linked Cat',
            'slug' => 'linked-cat',
            'is_active' => true,
        ]);
        $brand = Brand::query()->create([
            'organization_id' => $this->orgId,
            'name' => 'Linked Brand',
            'slug' => 'linked-brand',
            'is_active' => true,
        ]);
        $unit = Unit::query()->create([
            'organization_id' => $this->orgId,
            'name' => 'Linked Unit',
            'abbreviation' => 'lu',
            'is_active' => true,
        ]);

        Product::factory()->create([
            'organization_id' => $this->orgId,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'unit_id' => $unit->id,
            'sku' => 'TAX-1',
        ]);

        $this->deleteJson("/api/v1/categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('meta.deactivated', true)
            ->assertJsonPath('data.isActive', false);

        $this->deleteJson("/api/v1/brands/{$brand->id}")
            ->assertOk()
            ->assertJsonPath('meta.deactivated', true);

        $this->deleteJson("/api/v1/units/{$unit->id}")
            ->assertOk()
            ->assertJsonPath('meta.deactivated', true);

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'is_active' => false]);
        $this->assertDatabaseHas('brands', ['id' => $brand->id, 'is_active' => false]);
        $this->assertDatabaseHas('units', ['id' => $unit->id, 'is_active' => false]);
    }

    public function test_cashier_cannot_manage_taxonomy(): void
    {
        Sanctum::actingAs(User::factory()->cashier()->create(), ['staff']);

        $this->getJson('/api/v1/categories')->assertOk();

        $this->postJson('/api/v1/categories', ['name' => 'Nope'])->assertForbidden();
        $this->postJson('/api/v1/brands', ['name' => 'Nope'])->assertForbidden();
        $this->postJson('/api/v1/units', [
            'name' => 'Nope',
            'abbreviation' => 'np',
        ])->assertForbidden();
    }
}
