<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\CustomerPortal\Models\CustomerAccount;
use Modules\Customers\Enums\CustomerType;
use Modules\Customers\Models\Customer;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\BalanceBucket;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\Category;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Modules\Reporting\Services\InventoryStockReportService;
use Tests\TestCase;

class ReportingInventoryStockApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Store $store;

    private Store $otherStore;

    private ProductVariant $variantA;

    private ProductVariant $variantB;

    private User $manager;

    private User $cashier;

    private User $orgAdmin;

    private InventoryLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionMatrixSeeder::class);

        $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC'));

        $this->organization = app(OrganizationService::class)->current();
        $this->organization->forceFill(['timezone' => 'UTC', 'default_currency' => 'NGN'])->save();

        $noTax = ['rate' => '0.0000', 'inclusive' => false, 'name' => 'None'];
        $this->store = Store::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Inventory Report Store',
            'tax_config' => $noTax,
        ]);
        $this->otherStore = Store::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Other Inventory Store',
            'tax_config' => $noTax,
        ]);

        $category = Category::query()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Stock Goods',
            'slug' => 'stock-goods-'.Str::lower(Str::random(5)),
            'is_active' => true,
        ]);

        $this->variantA = $this->makeVariant('INV-A', $category);
        $this->variantB = $this->makeVariant('INV-B', $category);

        $this->orgAdmin = User::factory()->orgAdmin()->create();
        $this->ledger = app(InventoryLedgerService::class);

        $this->manager = User::factory()->storeManager()->create(['name' => 'Mo Manager']);
        $this->manager->stores()->attach($this->store->id, ['is_default' => true]);

        $this->cashier = User::factory()->cashier()->create(['name' => 'Cee Cashier']);
        $this->cashier->stores()->attach($this->store->id, ['is_default' => true]);
    }

    private function makeVariant(string $sku, Category $category): ProductVariant
    {
        $product = Product::factory()->create([
            'organization_id' => $this->organization->id,
            'sku' => $sku,
            'name' => $sku.' product',
            'category_id' => $category->id,
        ]);

        return ProductVariant::query()->create([
            'organization_id' => $this->organization->id,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => $sku.'-V',
            'price' => '10.0000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function postMovement(
        Store $store,
        ProductVariant $variant,
        MovementType $type,
        string $qty,
        BalanceBucket $bucket = BalanceBucket::Available,
        ?string $at = null,
    ): InventoryMovement {
        $movement = $this->ledger->post(new PostMovementData(
            organizationId: $this->organization->id,
            locationType: LocationType::Store,
            locationId: $store->id,
            productVariantId: $variant->id,
            type: $type,
            quantity: $qty,
            bucket: $bucket,
            userId: $this->orgAdmin->id,
            notes: 'Inventory report fixture',
        ));

        if ($at !== null) {
            InventoryMovement::query()->whereKey($movement->id)->update(['created_at' => $at]);
            $movement->refresh();
        }

        return $movement;
    }

    /**
     * @return array<string, string>
     */
    private function headers(?Store $store = null): array
    {
        return ['X-Store-Id' => (string) ($store ?? $this->store)->id];
    }

    /**
     * @param  array<string, string|int>  $query
     */
    private function reportUrl(array $query = []): string
    {
        return '/api/v1/reports/inventory?'.http_build_query(array_merge([
            'period' => 'day',
            'anchorDate' => '2026-03-10',
        ], $query));
    }

    public function test_happy_path_position_and_movements_match_ledger(): void
    {
        // Opening stock before the day.
        $this->postMovement($this->store, $this->variantA, MovementType::Adjustment, '12.0000', at: '2026-03-09 10:00:00');
        $this->postMovement($this->store, $this->variantB, MovementType::Adjustment, '5.0000', at: '2026-03-09 10:00:00');

        // In-period sale of 2 on A → available 10 at end of day before post-end noise.
        $this->postMovement($this->store, $this->variantA, MovementType::Sale, '-2.0000', at: '2026-03-10 09:00:00');

        // After period end: another sale of -2 leaves current available 8; as-of EOD must be 10.
        $this->postMovement($this->store, $this->variantA, MovementType::Sale, '-2.0000', at: '2026-03-11 08:00:00');

        // Other-store noise must not appear.
        $this->postMovement($this->otherStore, $this->variantA, MovementType::Adjustment, '99.0000', at: '2026-03-10 09:00:00');

        Sanctum::actingAs($this->manager, ['staff']);
        $response = $this->getJson($this->reportUrl(), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.store.id', $this->store->id)
            ->assertJsonPath('data.period.preset', 'day')
            ->assertJsonPath('data.period.anchorDate', '2026-03-10')
            ->assertJsonPath('data.period.start', '2026-03-10')
            ->assertJsonPath('data.period.end', '2026-03-10')
            ->assertJsonPath('data.period.timezone', 'UTC')
            ->assertJsonPath('data.summary.positionSkuCount', 2)
            ->assertJsonPath('meta.total', 2);

        $rows = collect($response->json('data.rows'))->keyBy('productVariantId');
        $this->assertSame('10.0000', $rows[$this->variantA->id]['available']);
        $this->assertSame('10.0000', $rows[$this->variantA->id]['onHand']);
        $this->assertSame('5.0000', $rows[$this->variantB->id]['available']);
        $this->assertSame('15.0000', $response->json('data.summary.onHandTotal'));

        $movements = collect($response->json('data.summary.movements'));
        $sale = $movements->firstWhere('type', 'sale');
        $this->assertNotNull($sale);
        $this->assertSame('-2.0000', $sale['quantity']);
        $this->assertArrayNotHasKey('bucket', $sale);
    }

    public function test_empty_store_returns_zeros_and_csv_headers_only(): void
    {
        Sanctum::actingAs($this->manager, ['staff']);
        $this->getJson($this->reportUrl(), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.summary.positionSkuCount', 0)
            ->assertJsonPath('data.summary.onHandTotal', '0.0000')
            ->assertJsonPath('data.summary.movements', [])
            ->assertJsonPath('data.rows', []);

        $csv = $this->get('/api/v1/reports/inventory/export?period=day&anchorDate=2026-03-10', $this->headers())
            ->assertOk()
            ->streamedContent();

        $this->assertSame(
            'product_variant_id,sku,name,available,reserved,damaged,in_transit,on_hand',
            trim($csv),
        );
    }

    public function test_as_of_excludes_post_cutoff_movements(): void
    {
        $this->postMovement($this->store, $this->variantA, MovementType::Adjustment, '10.0000', at: '2026-03-09 10:00:00');
        $this->postMovement($this->store, $this->variantA, MovementType::Reserve, '-1.0000', BalanceBucket::Available, '2026-03-09 11:00:00');
        $this->postMovement($this->store, $this->variantA, MovementType::Reserve, '1.0000', BalanceBucket::Reserved, '2026-03-09 11:00:01');

        // Post-end ledger noise on multiple buckets.
        $this->postMovement($this->store, $this->variantA, MovementType::Sale, '-2.0000', at: '2026-03-11 01:00:00');
        $this->postMovement($this->store, $this->variantA, MovementType::Release, '-1.0000', BalanceBucket::Reserved, '2026-03-11 02:00:00');
        $this->postMovement($this->store, $this->variantA, MovementType::Release, '1.0000', BalanceBucket::Available, '2026-03-11 02:00:01');
        $this->postMovement($this->store, $this->variantA, MovementType::Damage, '-1.0000', BalanceBucket::Available, '2026-03-11 03:00:00');
        $this->postMovement($this->store, $this->variantA, MovementType::Damage, '1.0000', BalanceBucket::Damaged, '2026-03-11 03:00:01');

        // Current ends at available 7, reserved 0, damaged 1.
        // As-of undoes post-end → available 9, reserved 1, damaged 0.
        Sanctum::actingAs($this->manager, ['staff']);
        $row = $this->getJson($this->reportUrl(['period' => 'day', 'anchorDate' => '2026-03-10']), $this->headers())
            ->assertOk()
            ->json('data.rows.0');

        $this->assertSame('9.0000', $row['available']);
        $this->assertSame('1.0000', $row['reserved']);
        $this->assertSame('0.0000', $row['damaged']);
        $this->assertSame('10.0000', $row['onHand']);
        $this->assertSame([], $this->getJson($this->reportUrl(['period' => 'day', 'anchorDate' => '2026-03-10']), $this->headers())->json('data.summary.movements'));
    }

    public function test_reserve_release_show_typed_bucketed_lines_without_inventing_physical_stock(): void
    {
        $this->postMovement($this->store, $this->variantA, MovementType::Adjustment, '20.0000', at: '2026-03-09 10:00:00');

        $this->postMovement($this->store, $this->variantA, MovementType::Reserve, '-3.0000', BalanceBucket::Available, '2026-03-10 11:00:00');
        $this->postMovement($this->store, $this->variantA, MovementType::Reserve, '3.0000', BalanceBucket::Reserved, '2026-03-10 11:00:01');
        $this->postMovement($this->store, $this->variantA, MovementType::Release, '-1.0000', BalanceBucket::Reserved, '2026-03-10 15:00:00');
        $this->postMovement($this->store, $this->variantA, MovementType::Release, '1.0000', BalanceBucket::Available, '2026-03-10 15:00:01');

        Sanctum::actingAs($this->manager, ['staff']);
        $response = $this->getJson($this->reportUrl(), $this->headers())->assertOk();

        $row = $response->json('data.rows.0');
        $this->assertSame('18.0000', $row['available']);
        $this->assertSame('2.0000', $row['reserved']);
        $this->assertSame('20.0000', $row['onHand']);

        $movements = collect($response->json('data.summary.movements'));
        $reserveLines = $movements->where('type', 'reserve')->values();
        $this->assertCount(2, $reserveLines);
        $this->assertTrue($reserveLines->every(fn (array $line) => isset($line['bucket'])));
        $this->assertSame('-3.0000', $reserveLines->firstWhere('bucket', 'available')['quantity']);
        $this->assertSame('3.0000', $reserveLines->firstWhere('bucket', 'reserved')['quantity']);

        $releaseLines = $movements->where('type', 'release')->values();
        $this->assertCount(2, $releaseLines);
        $this->assertSame('-1.0000', $releaseLines->firstWhere('bucket', 'reserved')['quantity']);
        $this->assertSame('1.0000', $releaseLines->firstWhere('bucket', 'available')['quantity']);
    }

    public function test_period_day_bound_uses_organization_timezone(): void
    {
        $this->postMovement($this->store, $this->variantA, MovementType::Adjustment, '4.0000', at: '2026-03-14 20:00:00');

        Sanctum::actingAs($this->manager, ['staff']);
        $this->getJson($this->reportUrl(['period' => 'day', 'anchorDate' => '2026-03-15']), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.summary.positionSkuCount', 1)
            ->assertJsonPath('data.rows.0.available', '4.0000')
            ->assertJsonPath('data.summary.movements', []);

        $this->organization->forceFill(['timezone' => 'Pacific/Kiritimati'])->save();

        $response = $this->getJson($this->reportUrl(['period' => 'day', 'anchorDate' => '2026-03-15']), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.period.timezone', 'Pacific/Kiritimati')
            ->assertJsonPath('data.rows.0.available', '4.0000');

        $movements = $response->json('data.summary.movements');
        $this->assertNotEmpty($movements);
        $this->assertSame('adjustment', $movements[0]['type']);
        $this->assertSame('4.0000', $movements[0]['quantity']);
    }

    public function test_position_pagination_slices_rows(): void
    {
        $this->postMovement($this->store, $this->variantA, MovementType::Adjustment, '1.0000', at: '2026-03-09 10:00:00');
        $this->postMovement($this->store, $this->variantB, MovementType::Adjustment, '2.0000', at: '2026-03-09 10:00:00');
        $variantC = $this->makeVariant('INV-C', Category::query()->where('organization_id', $this->organization->id)->first());
        $this->postMovement($this->store, $variantC, MovementType::Adjustment, '3.0000', at: '2026-03-09 10:00:00');

        Sanctum::actingAs($this->manager, ['staff']);
        $page1 = $this->getJson($this->reportUrl(['per_page' => 1, 'page' => 1]), $this->headers())
            ->assertOk()
            ->assertJsonPath('meta.currentPage', 1)
            ->assertJsonPath('meta.lastPage', 3)
            ->assertJsonPath('meta.perPage', 1)
            ->assertJsonPath('meta.total', 3);
        $this->assertCount(1, $page1->json('data.rows'));
        $firstId = $page1->json('data.rows.0.productVariantId');

        $page2 = $this->getJson($this->reportUrl(['per_page' => 1, 'page' => 2]), $this->headers())
            ->assertOk()
            ->assertJsonPath('meta.currentPage', 2);
        $this->assertCount(1, $page2->json('data.rows'));
        $this->assertNotSame($firstId, $page2->json('data.rows.0.productVariantId'));
    }

    public function test_week_month_year_presets_resolve_inclusive_bounds(): void
    {
        Sanctum::actingAs($this->manager, ['staff']);

        // 2026-03-10 is a Tuesday → week Mon 09 – Sun 15.
        $this->getJson($this->reportUrl(['period' => 'week', 'anchorDate' => '2026-03-10']), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.period.start', '2026-03-09')
            ->assertJsonPath('data.period.end', '2026-03-15');

        $this->getJson($this->reportUrl(['period' => 'month', 'anchorDate' => '2026-03-10']), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.period.start', '2026-03-01')
            ->assertJsonPath('data.period.end', '2026-03-31');

        $this->getJson($this->reportUrl(['period' => 'year', 'anchorDate' => '2026-03-10']), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.period.start', '2026-01-01')
            ->assertJsonPath('data.period.end', '2026-12-31');
    }

    public function test_bad_period_and_missing_store_return_422(): void
    {
        Sanctum::actingAs($this->manager, ['staff']);

        $this->getJson('/api/v1/reports/inventory?period=fortnight&anchorDate=2026-03-10', $this->headers())
            ->assertStatus(422);
        $this->getJson('/api/v1/reports/inventory?period=day&anchorDate=10-03-2026', $this->headers())
            ->assertStatus(422);
        $this->getJson($this->reportUrl())->assertStatus(422);
    }

    public function test_export_returns_csv_matching_json_positions(): void
    {
        $this->postMovement($this->store, $this->variantA, MovementType::Adjustment, '12.0000', at: '2026-03-09 10:00:00');
        $this->postMovement($this->store, $this->variantA, MovementType::Sale, '-2.0000', at: '2026-03-11 08:00:00');
        $this->postMovement($this->store, $this->variantB, MovementType::Adjustment, '5.0000', at: '2026-03-09 10:00:00');

        Sanctum::actingAs($this->manager, ['staff']);
        $json = $this->getJson($this->reportUrl(), $this->headers())->assertOk()->json('data.rows');

        $response = $this->get(
            '/api/v1/reports/inventory/export?period=day&anchorDate=2026-03-10',
            $this->headers(),
        )->assertOk();

        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString(
            'inventory-stock-'.$this->store->id.'-day-2026-03-10.csv',
            (string) $response->headers->get('Content-Disposition'),
        );

        $lines = array_values(array_filter(explode("\n", $response->streamedContent())));
        $this->assertSame(
            'product_variant_id,sku,name,available,reserved,damaged,in_transit,on_hand',
            trim($lines[0]),
        );
        $this->assertCount(3, $lines);
        // Current available is 10 after the post-end sale; as-of EOD subtracts that −2 → 12.
        $this->assertSame('12.0000', $json[0]['available']);
        $this->assertSame('5.0000', $json[1]['available']);
        $this->assertStringContainsString('12.0000', $lines[1]);
        $this->assertStringContainsString('5.0000', $lines[2]);
    }

    public function test_export_rejects_oversize_position_set(): void
    {
        $this->postMovement($this->store, $this->variantA, MovementType::Adjustment, '1.0000', at: '2026-03-09 10:00:00');
        $this->postMovement($this->store, $this->variantB, MovementType::Adjustment, '1.0000', at: '2026-03-09 10:00:00');

        Sanctum::actingAs($this->manager, ['staff']);

        $this->app->instance(InventoryStockReportService::class, new class extends InventoryStockReportService
        {
            protected function csvRowLimit(): int
            {
                return 1;
            }
        });

        $this->get('/api/v1/reports/inventory/export?period=day&anchorDate=2026-03-10', $this->headers())
            ->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Export is too large (2 position rows). This store has too many balance rows to export synchronously (max 1).',
            ]);
    }

    public function test_report_and_export_require_reports_view_and_store_scope(): void
    {
        Sanctum::actingAs($this->cashier, ['staff']);
        $this->getJson($this->reportUrl(), $this->headers())->assertForbidden();
        $this->get('/api/v1/reports/inventory/export?period=day&anchorDate=2026-03-10', $this->headers())
            ->assertForbidden();

        Sanctum::actingAs($this->manager, ['staff']);
        $this->getJson($this->reportUrl(), $this->headers($this->otherStore))->assertForbidden();
        $this->get('/api/v1/reports/inventory/export?period=day&anchorDate=2026-03-10', $this->headers($this->otherStore))
            ->assertForbidden();
    }

    public function test_report_rejects_guests_and_portal_tokens(): void
    {
        $this->getJson($this->reportUrl(), $this->headers())->assertUnauthorized();

        $customer = Customer::query()->create([
            'organization_id' => $this->organization->id,
            'type' => CustomerType::Registered,
            'name' => 'Portal Shopper',
            'account_number' => 'CUST-'.Str::upper(Str::random(8)),
            'is_active' => true,
        ]);
        $account = CustomerAccount::query()->create([
            'organization_id' => $this->organization->id,
            'customer_id' => $customer->id,
            'email' => Str::lower(Str::random(10)).'@example.com',
            'password' => 'password',
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($account, ['portal']);
        $this->getJson($this->reportUrl(), $this->headers())->assertUnauthorized();
        $this->get('/api/v1/reports/inventory/export?period=day&anchorDate=2026-03-10', $this->headers())
            ->assertUnauthorized();
    }
}
