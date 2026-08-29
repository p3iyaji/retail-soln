<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\Product;
use Tests\TestCase;

class ProductVariantApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionMatrixSeeder::class);
    }

    public function test_inventory_manager_can_crud_variants_with_sku_barcode_and_price(): void
    {
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);
        $orgId = app(OrganizationService::class)->current()->id;

        $product = Product::factory()->create([
            'organization_id' => $orgId,
            'sku' => 'PARENT-1',
            'barcode' => '1111111111111',
        ]);

        $create = $this->postJson("/api/v1/products/{$product->id}/variants", [
            'name' => '500ml',
            'sku' => 'COLA-500',
            'barcode' => '2222222222222',
            'price' => '2.5000',
            'costPrice' => '1.0000',
            'isDefault' => true,
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.name', '500ml')
            ->assertJsonPath('data.sku', 'COLA-500')
            ->assertJsonPath('data.barcode', '2222222222222')
            ->assertJsonPath('data.price', '2.5000')
            ->assertJsonPath('data.isDefault', true);

        $variantId = $create->json('data.id');

        $this->postJson("/api/v1/products/{$product->id}/variants", [
            'name' => '1L',
            'sku' => 'COLA-1L',
            'barcode' => '3333333333333',
            'price' => '3.5000',
        ])->assertCreated();

        $this->getJson("/api/v1/products/{$product->id}/variants")
            ->assertOk()
            ->assertJsonPath('data.0.id', $variantId);

        $this->patchJson("/api/v1/products/{$product->id}/variants/{$variantId}", [
            'price' => '2.7500',
            'name' => '500ml bottle',
        ])
            ->assertOk()
            ->assertJsonPath('data.price', '2.7500')
            ->assertJsonPath('data.name', '500ml bottle');

        $this->deleteJson("/api/v1/products/{$product->id}/variants/{$variantId}")
            ->assertOk();

        $this->assertDatabaseMissing('product_variants', ['id' => $variantId]);

        $this->deleteJson("/api/v1/products/{$product->id}/variants/".$this->getJson("/api/v1/products/{$product->id}/variants")->json('data.0.id'))
            ->assertStatus(422);
    }

    public function test_barcode_uniqueness_enforced_across_products_and_variants(): void
    {
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);
        $orgId = app(OrganizationService::class)->current()->id;

        $product = Product::factory()->create([
            'organization_id' => $orgId,
            'sku' => 'PARENT-2',
            'barcode' => '3333333333333',
        ]);

        $this->postJson("/api/v1/products/{$product->id}/variants", [
            'name' => 'Default',
            'sku' => 'PARENT-2',
            'barcode' => '3333333333333',
            'price' => '1.0000',
        ])->assertCreated()
            ->assertJsonPath('data.sku', 'PARENT-2')
            ->assertJsonPath('data.barcode', '3333333333333');

        $this->postJson("/api/v1/products/{$product->id}/variants", [
            'name' => 'Duplicate barcode',
            'sku' => 'VAR-1',
            'barcode' => '3333333333333',
            'price' => '1.0000',
        ])->assertStatus(422);

        $other = Product::factory()->create([
            'organization_id' => $orgId,
            'sku' => 'PARENT-3',
        ]);

        $created = $this->postJson("/api/v1/products/{$other->id}/variants", [
            'name' => 'Small',
            'sku' => 'VAR-2',
            'barcode' => '4444444444444',
            'price' => '1.0000',
        ])->assertCreated();

        $variantId = $created->json('data.id');

        $this->postJson("/api/v1/products/{$product->id}/variants", [
            'name' => 'Clash',
            'sku' => 'VAR-3',
            'barcode' => '4444444444444',
            'price' => '1.0000',
        ])->assertStatus(422);

        $this->postJson('/api/v1/products', [
            'name' => 'New product',
            'sku' => 'NEW-PROD',
            'barcode' => '4444444444444',
            'sellingPrice' => '5.0000',
        ])->assertStatus(422);

        $this->patchJson("/api/v1/products/{$other->id}/variants/{$variantId}", [
            'barcode' => '3333333333333',
        ])->assertStatus(422);
    }

    public function test_sku_uniqueness_enforced_across_products_and_variants(): void
    {
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);
        $orgId = app(OrganizationService::class)->current()->id;

        Product::factory()->create([
            'organization_id' => $orgId,
            'sku' => 'SHARED-SKU',
        ]);

        $product = Product::factory()->create([
            'organization_id' => $orgId,
            'sku' => 'PARENT-4',
        ]);

        $this->postJson("/api/v1/products/{$product->id}/variants", [
            'name' => 'Dup sku',
            'sku' => 'SHARED-SKU',
            'price' => '1.0000',
        ])->assertStatus(422);

        $this->postJson("/api/v1/products/{$product->id}/variants", [
            'name' => 'Parent sku ok',
            'sku' => 'PARENT-4',
            'price' => '1.0000',
        ])->assertCreated();
    }

    public function test_cashier_cannot_manage_variants(): void
    {
        Sanctum::actingAs(User::factory()->cashier()->create(), ['staff']);
        $orgId = app(OrganizationService::class)->current()->id;

        $product = Product::factory()->create([
            'organization_id' => $orgId,
            'sku' => 'PARENT-5',
        ]);

        $this->getJson("/api/v1/products/{$product->id}/variants")->assertOk();

        $this->postJson("/api/v1/products/{$product->id}/variants", [
            'name' => 'Nope',
            'sku' => 'NOPE',
            'price' => '1.0000',
        ])->assertForbidden();
    }
}
