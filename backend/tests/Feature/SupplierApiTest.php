<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\Organization\Models\Organization;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\Product;
use Modules\Purchasing\Models\Supplier;
use Modules\Purchasing\Models\SupplierImport;
use Modules\Purchasing\Models\SupplierProduct;
use Tests\TestCase;

class SupplierApiTest extends TestCase
{
    use RefreshDatabase;

    private int $orgId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionMatrixSeeder::class);
        $this->orgId = app(OrganizationService::class)->current()->id;
    }

    public function test_procurement_officer_can_create_supplier_with_nested_data(): void
    {
        Sanctum::actingAs(User::factory()->procurementOfficer()->create(), ['staff']);

        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'sku' => 'MAP-001',
        ]);

        $response = $this->postJson('/api/v1/suppliers', [
            'name' => 'Acme Supply',
            'code' => 'ACME',
            'isActive' => true,
            'contacts' => [
                [
                    'name' => 'Jane Doe',
                    'email' => 'jane@acme.test',
                    'phone' => '555-0100',
                    'isPrimary' => true,
                ],
            ],
            'addresses' => [
                [
                    'type' => 'billing',
                    'line1' => '100 Main St',
                    'city' => 'Lagos',
                    'country' => 'NG',
                    'isDefault' => true,
                ],
            ],
            'productMappings' => [
                [
                    'productId' => $product->id,
                    'supplierSku' => 'ACME-MAP-001',
                    'cost' => '12.5000',
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Acme Supply')
            ->assertJsonPath('data.code', 'ACME')
            ->assertJsonPath('data.contacts.0.name', 'Jane Doe')
            ->assertJsonPath('data.addresses.0.line1', '100 Main St')
            ->assertJsonPath('data.productMappings.0.supplierSku', 'ACME-MAP-001')
            ->assertJsonPath('data.productMappings.0.cost', '12.5000');

        $this->assertDatabaseHas('suppliers', [
            'organization_id' => $this->orgId,
            'code' => 'ACME',
            'name' => 'Acme Supply',
        ]);
        $this->assertDatabaseHas('supplier_contacts', [
            'name' => 'Jane Doe',
            'email' => 'jane@acme.test',
        ]);
        $this->assertDatabaseHas('supplier_addresses', [
            'line1' => '100 Main St',
            'type' => 'billing',
        ]);
        $this->assertDatabaseHas('supplier_products', [
            'product_id' => $product->id,
            'supplier_sku' => 'ACME-MAP-001',
            'cost' => '12.5000',
        ]);
    }

    public function test_create_supplier_writes_audit_log(): void
    {
        Sanctum::actingAs(User::factory()->procurementOfficer()->create(), ['staff']);

        $this->postJson('/api/v1/suppliers', [
            'name' => 'Audited Supplier',
            'code' => 'AUD',
        ])->assertCreated();

        $supplier = Supplier::query()->where('code', 'AUD')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'created',
            'auditable_type' => Supplier::class,
            'auditable_id' => $supplier->id,
        ]);
    }

    public function test_list_supports_per_page_lite_and_search(): void
    {
        Sanctum::actingAs(User::factory()->procurementOfficer()->create(), ['staff']);

        Supplier::query()->create([
            'organization_id' => $this->orgId,
            'name' => 'Alpha Foods',
            'code' => 'ALP',
            'is_active' => true,
        ]);
        Supplier::query()->create([
            'organization_id' => $this->orgId,
            'name' => 'Beta Goods',
            'code' => 'BET',
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/suppliers?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.perPage', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/suppliers?q=Alpha')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.name', 'Alpha Foods');

        $lite = $this->getJson('/api/v1/suppliers?lite=1');
        $lite->assertOk()
            ->assertJsonPath('meta.perPage', 500)
            ->assertJsonMissingPath('data.0.contacts');
    }

    public function test_update_supplier_persists_nested_changes(): void
    {
        Sanctum::actingAs(User::factory()->procurementOfficer()->create(), ['staff']);

        $supplier = Supplier::query()->create([
            'organization_id' => $this->orgId,
            'name' => 'Old Name',
            'code' => 'OLD',
            'is_active' => true,
        ]);

        $this->patchJson("/api/v1/suppliers/{$supplier->id}", [
            'name' => 'New Name',
            'contacts' => [
                ['name' => 'Updated Contact', 'email' => 'u@test.com', 'isPrimary' => true],
            ],
            'addresses' => [
                ['type' => 'shipping', 'line1' => '200 Dock Rd', 'city' => 'Abuja', 'country' => 'NG'],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.contacts.0.name', 'Updated Contact')
            ->assertJsonPath('data.addresses.0.type', 'shipping');
    }

    public function test_delete_deactivates_when_product_references_exist(): void
    {
        Sanctum::actingAs(User::factory()->procurementOfficer()->create(), ['staff']);

        $supplier = Supplier::query()->create([
            'organization_id' => $this->orgId,
            'name' => 'Linked Supplier',
            'code' => 'LINK',
            'is_active' => true,
        ]);

        Product::factory()->create([
            'organization_id' => $this->orgId,
            'supplier_id' => $supplier->id,
        ]);

        $this->deleteJson("/api/v1/suppliers/{$supplier->id}")
            ->assertOk()
            ->assertJsonPath('data.isActive', false)
            ->assertJsonPath('meta.deactivated', true);

        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'is_active' => false,
        ]);
    }

    public function test_delete_deactivates_when_only_mappings_exist(): void
    {
        Sanctum::actingAs(User::factory()->procurementOfficer()->create(), ['staff']);

        $supplier = Supplier::query()->create([
            'organization_id' => $this->orgId,
            'name' => 'Mapped Only',
            'code' => 'MAPONLY',
            'is_active' => true,
        ]);

        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'supplier_id' => null,
        ]);

        SupplierProduct::query()->create([
            'organization_id' => $this->orgId,
            'supplier_id' => $supplier->id,
            'product_id' => $product->id,
            'supplier_sku' => 'X-1',
            'cost' => '1.0000',
        ]);

        $this->deleteJson("/api/v1/suppliers/{$supplier->id}")
            ->assertOk()
            ->assertJsonPath('meta.deactivated', true)
            ->assertJsonPath('data.isActive', false);

        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'is_active' => false,
        ]);
    }

    public function test_delete_hard_removes_unreferenced_supplier(): void
    {
        Sanctum::actingAs(User::factory()->procurementOfficer()->create(), ['staff']);

        $supplier = Supplier::query()->create([
            'organization_id' => $this->orgId,
            'name' => 'Ephemeral',
            'code' => 'EPH',
            'is_active' => true,
        ]);

        $this->deleteJson("/api/v1/suppliers/{$supplier->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('suppliers', ['id' => $supplier->id]);
    }

    public function test_unknown_product_mapping_returns_422(): void
    {
        Sanctum::actingAs(User::factory()->procurementOfficer()->create(), ['staff']);

        $this->postJson('/api/v1/suppliers', [
            'name' => 'Bad Map',
            'productMappings' => [
                ['productSku' => 'DOES-NOT-EXIST', 'cost' => '1.00'],
            ],
        ])->assertStatus(422);
    }

    public function test_duplicate_product_mapping_returns_422(): void
    {
        Sanctum::actingAs(User::factory()->procurementOfficer()->create(), ['staff']);

        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'sku' => 'DUP-MAP',
        ]);

        $this->postJson('/api/v1/suppliers', [
            'name' => 'Dup Maps',
            'productMappings' => [
                ['productId' => $product->id, 'cost' => '1.00'],
                ['productId' => $product->id, 'cost' => '2.00'],
            ],
        ])->assertStatus(422);
    }

    public function test_duplicate_code_per_org_returns_422(): void
    {
        Sanctum::actingAs(User::factory()->procurementOfficer()->create(), ['staff']);

        Supplier::query()->create([
            'organization_id' => $this->orgId,
            'name' => 'First',
            'code' => 'DUP',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/suppliers', [
            'name' => 'Second',
            'code' => 'DUP',
        ])->assertStatus(422);
    }

    public function test_cashier_cannot_manage_or_import_suppliers(): void
    {
        Sanctum::actingAs(User::factory()->cashier()->create(), ['staff']);

        $this->postJson('/api/v1/suppliers', ['name' => 'Nope'])
            ->assertForbidden();

        $file = UploadedFile::fake()->createWithContent(
            'suppliers.csv',
            "name,code\nX,X1\n"
        );

        $this->post('/api/v1/suppliers/import/preview', ['file' => $file], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->post('/api/v1/suppliers/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_unauthenticated_requests_return_401(): void
    {
        $this->getJson('/api/v1/suppliers')->assertUnauthorized();
        $this->postJson('/api/v1/suppliers', ['name' => 'X'])->assertUnauthorized();
    }

    public function test_csv_preview_import_and_export(): void
    {
        Sanctum::actingAs(User::factory()->procurementOfficer()->create(), ['staff']);

        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'sku' => 'CSV-SKU',
        ]);

        $csv = implode("\n", [
            'name,code,isActive,contactName,contactEmail,contactPhone,addressType,line1,line2,city,region,postalCode,country,productSku,supplierSku,cost',
            'CSV Supplier,CSV1,1,Bob,bob@csv.test,111,billing,1 Lane,Suite 2,Lagos,LA,100001,NG,CSV-SKU,SUP-CSV,9.2500',
            ',BAD,,,,,',
        ]);

        $file = UploadedFile::fake()->createWithContent('suppliers.csv', $csv);

        $this->post('/api/v1/suppliers/import/preview', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.totalRows', 2)
            ->assertJsonPath('data.validCount', 1)
            ->assertJsonPath('data.errorCount', 1);

        $importFile = UploadedFile::fake()->createWithContent('suppliers.csv', $csv);
        $this->post('/api/v1/suppliers/import', ['file' => $importFile], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.successCount', 1)
            ->assertJsonPath('data.async', false);

        $this->assertDatabaseHas('suppliers', [
            'organization_id' => $this->orgId,
            'code' => 'CSV1',
            'name' => 'CSV Supplier',
        ]);
        $this->assertDatabaseHas('supplier_addresses', [
            'line1' => '1 Lane',
            'line2' => 'Suite 2',
            'region' => 'LA',
            'postal_code' => '100001',
        ]);
        $this->assertDatabaseHas('supplier_products', [
            'product_id' => $product->id,
            'supplier_sku' => 'SUP-CSV',
            'cost' => '9.2500',
        ]);

        $export = $this->get('/api/v1/suppliers/export');
        $export->assertOk();
        $this->assertStringContainsString('text/csv', (string) $export->headers->get('content-type'));
        $content = $export->streamedContent();
        $this->assertStringContainsString('CSV Supplier', $content);
        $this->assertStringContainsString('line2', $content);
        $this->assertStringContainsString('postalCode', $content);
    }

    public function test_large_supplier_import_job_runs_and_creates_suppliers(): void
    {
        Sanctum::actingAs(User::factory()->procurementOfficer()->create(), ['staff']);

        $lines = ['name,code'];
        for ($i = 1; $i <= 100; $i++) {
            $lines[] = "Supplier {$i},C{$i}";
        }
        $file = UploadedFile::fake()->createWithContent('big.csv', implode("\n", $lines));

        $this->post('/api/v1/suppliers/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(202)
            ->assertJsonPath('data.async', true);

        $import = SupplierImport::query()->firstOrFail();
        $this->assertSame('completed', $import->fresh()->status);
        $this->assertSame(100, $import->fresh()->success_count);
        $this->assertSame(100, Supplier::query()->where('organization_id', $this->orgId)->count());
    }

    public function test_product_csv_supplier_resolve_still_works(): void
    {
        Sanctum::actingAs(User::factory()->inventoryManager()->create(), ['staff']);

        $csv = implode("\n", [
            'name,sku,sellingPrice,supplier',
            'Tea,TEA-CSV,3.00,Resolved Supplier',
        ]);
        $file = UploadedFile::fake()->createWithContent('products.csv', $csv);

        $this->post('/api/v1/products/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertDatabaseHas('suppliers', [
            'organization_id' => $this->orgId,
            'name' => 'Resolved Supplier',
        ]);
        $this->assertDatabaseHas('products', [
            'sku' => 'TEA-CSV',
            'name' => 'Tea',
        ]);
    }

    public function test_cross_org_supplier_returns_404(): void
    {
        Sanctum::actingAs(User::factory()->procurementOfficer()->create(), ['staff']);

        $otherOrg = Organization::factory()->create([
            'name' => 'Other Org',
        ]);

        $foreign = Supplier::query()->create([
            'organization_id' => $otherOrg->id,
            'name' => 'Foreign Supplier',
            'code' => 'FOR',
            'is_active' => true,
        ]);

        $this->getJson("/api/v1/suppliers/{$foreign->id}")->assertNotFound();
        $this->patchJson("/api/v1/suppliers/{$foreign->id}", ['name' => 'X'])->assertNotFound();
        $this->deleteJson("/api/v1/suppliers/{$foreign->id}")->assertNotFound();
    }

    public function test_non_csv_supplier_upload_is_rejected_before_storage(): void
    {
        Sanctum::actingAs(User::factory()->procurementOfficer()->create(), ['staff']);

        $file = UploadedFile::fake()->createWithContent('payload.exe', "MZ\x90\x00binary");

        $this->post('/api/v1/suppliers/import/preview', ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->post('/api/v1/suppliers/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, SupplierImport::query()->count());
    }
}
