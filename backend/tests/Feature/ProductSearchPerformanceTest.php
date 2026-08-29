<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\Category;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Modules\Products\Services\ProductSearchService;
use Tests\TestCase;

class ProductSearchPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private int $orgId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionMatrixSeeder::class);
        $this->orgId = app(OrganizationService::class)->current()->id;

        $category = Category::query()->create([
            'organization_id' => $this->orgId,
            'name' => 'Snacks',
            'slug' => 'snacks',
        ]);

        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'name' => 'Potato Chips',
            'sku' => 'CHIP-001',
            'barcode' => '9990001112223',
            'category_id' => $category->id,
            'selling_price' => '2.5000',
        ]);

        ProductVariant::query()->create([
            'organization_id' => $this->orgId,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'CHIP-V1',
            'price' => '2.5000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    public function test_exact_sku_search_avoids_like_queries(): void
    {
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('/api/v1/products/search?q=CHIP-001')
            ->assertOk()
            ->assertJsonPath('data.0.sku', 'CHIP-001');

        foreach (DB::getQueryLog() as $entry) {
            $this->assertStringNotContainsString(
                ' like ',
                strtolower($entry['query']),
                'Exact SKU search should use indexed equality, not LIKE',
            );
        }
    }

    public function test_exact_barcode_uses_fast_path_before_fuzzy_products(): void
    {
        $service = app(ProductSearchService::class);

        $results = $service->search($this->orgId, '9990001112223');

        $this->assertCount(1, $results);
        $this->assertSame('CHIP-001', $results->first()?->sku);
    }

    public function test_short_partial_term_skips_fuzzy_when_no_exact_hit(): void
    {
        $service = app(ProductSearchService::class);

        $results = $service->search($this->orgId, 'C');

        $this->assertTrue($results->isEmpty());
    }

    public function test_fuzzy_name_search_still_finds_products(): void
    {
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        $this->getJson('/api/v1/products/search?q=Potato')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Potato Chips');
    }
}
