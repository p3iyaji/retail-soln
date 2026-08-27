<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Jobs\ProcessProductImportJob;
use Modules\Products\Models\Category;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductImport;
use Tests\TestCase;

class ProductSearchImportApiTest extends TestCase
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

        Product::factory()->create([
            'organization_id' => $this->orgId,
            'name' => 'Potato Chips',
            'sku' => 'CHIP-001',
            'barcode' => '9990001112223',
            'category_id' => $category->id,
            'base_price' => '2.5000',
        ]);

        Product::factory()->create([
            'organization_id' => $this->orgId,
            'name' => 'Cola Drink',
            'sku' => 'COLA-001',
            'barcode' => '9990001112224',
            'base_price' => '1.5000',
        ]);
    }

    public function test_search_finds_products_by_sku_barcode_name_and_category(): void
    {
        Sanctum::actingAs(User::factory()->inventoryManager()->create());

        $this->getJson('/api/v1/products/search?q=CHIP-001')
            ->assertOk()
            ->assertJsonPath('data.0.sku', 'CHIP-001')
            ->assertJsonPath('meta.count', 1);

        $this->getJson('/api/v1/products/search?q=9990001112223')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Potato Chips');

        $this->getJson('/api/v1/products/search?q=Cola')
            ->assertOk()
            ->assertJsonPath('data.0.sku', 'COLA-001');

        $this->getJson('/api/v1/products/search?q=Snacks')
            ->assertOk()
            ->assertJsonPath('data.0.sku', 'CHIP-001');

        $elapsed = $this->getJson('/api/v1/products/search?q=CHIP')->json('meta.elapsedMs');
        $this->assertLessThan(200, $elapsed);
    }

    public function test_import_preview_reports_validation_errors(): void
    {
        Sanctum::actingAs(User::factory()->inventoryManager()->create());

        $csv = implode("\n", [
            'name,sku,basePrice,barcode',
            'Good Item,GOOD-1,3.00,111',
            ',BAD-1,3.00,222',
            'No Price,BAD-2,,333',
        ]);

        $file = UploadedFile::fake()->createWithContent('products.csv', $csv);

        $this->post('/api/v1/products/import/preview', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.totalRows', 3)
            ->assertJsonPath('data.validCount', 1)
            ->assertJsonPath('data.errorCount', 2);
    }

    public function test_sync_import_creates_products_from_valid_csv(): void
    {
        Sanctum::actingAs(User::factory()->inventoryManager()->create());

        $csv = implode("\n", [
            'name,sku,basePrice,barcode,category,brand,unit',
            'Imported Tea,TEA-100,4.2500,5556667778889,Beverages,LeafCo,ea',
        ]);

        $file = UploadedFile::fake()->createWithContent('import.csv', $csv);

        $response = $this->post('/api/v1/products/import', ['file' => $file], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.successCount', 1)
            ->assertJsonPath('data.async', false);

        $this->assertDatabaseHas('products', [
            'organization_id' => $this->orgId,
            'sku' => 'TEA-100',
            'name' => 'Imported Tea',
            'base_price' => '4.2500',
        ]);
    }

    public function test_large_import_is_queued_asynchronously(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->inventoryManager()->create());

        $lines = ['name,sku,basePrice'];
        for ($i = 1; $i <= 100; $i++) {
            $lines[] = "Item {$i},SKU-{$i},1.00";
        }
        $file = UploadedFile::fake()->createWithContent('big.csv', implode("\n", $lines));

        $this->post('/api/v1/products/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(202)
            ->assertJsonPath('data.async', true)
            ->assertJsonPath('data.status', 'pending');

        Queue::assertPushed(ProcessProductImportJob::class);
        $this->assertSame(1, ProductImport::query()->count());
    }

    public function test_export_returns_csv(): void
    {
        Sanctum::actingAs(User::factory()->inventoryManager()->create());

        $response = $this->get('/api/v1/products/export');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('CHIP-001', $response->streamedContent());
        $this->assertStringContainsString('Potato Chips', $response->streamedContent());
    }

    public function test_cashier_cannot_import(): void
    {
        Sanctum::actingAs(User::factory()->cashier()->create());

        $file = UploadedFile::fake()->createWithContent('x.csv', "name,sku,basePrice\nA,A1,1.00");

        $this->post('/api/v1/products/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertForbidden();
    }
}
