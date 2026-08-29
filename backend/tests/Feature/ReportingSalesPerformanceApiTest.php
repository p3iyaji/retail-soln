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
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\Category;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Modules\Reporting\Data\SalesReportFilters;
use Modules\Reporting\Services\SalesPerformanceReportService;
use Modules\Sales\Models\Sale;
use Tests\TestCase;

class ReportingSalesPerformanceApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Store $store;

    private Store $otherStore;

    private Category $categoryA;

    private Category $categoryB;

    private ProductVariant $variantA;

    private ProductVariant $variantB;

    private User $manager;

    private User $cashier;

    private User $orgAdmin;

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
            'name' => 'Report Store',
            'tax_config' => $noTax,
        ]);
        $this->otherStore = Store::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Other Store',
            'tax_config' => $noTax,
        ]);

        $this->categoryA = $this->makeCategory('Drinks');
        $this->categoryB = $this->makeCategory('Snacks');

        $this->variantA = $this->makeVariant('RPT-A', $this->categoryA);
        $this->variantB = $this->makeVariant('RPT-B', $this->categoryB);

        $this->orgAdmin = User::factory()->orgAdmin()->create();

        foreach ([$this->store, $this->otherStore] as $store) {
            $this->seedStock($store, $this->variantA, '500.0000');
            $this->seedStock($store, $this->variantB, '500.0000');
        }

        $this->manager = User::factory()->storeManager()->create(['name' => 'Mo Manager']);
        $this->manager->stores()->attach($this->store->id, ['is_default' => true]);

        $this->cashier = User::factory()->cashier()->create(['name' => 'Cee Cashier']);
        $this->cashier->stores()->attach($this->store->id, ['is_default' => true]);
    }

    private function makeCategory(string $name): Category
    {
        return Category::query()->create([
            'organization_id' => $this->organization->id,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
            'is_active' => true,
        ]);
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

    private function seedStock(Store $store, ProductVariant $variant, string $qty): void
    {
        app(InventoryLedgerService::class)->post(new PostMovementData(
            organizationId: $this->organization->id,
            locationType: LocationType::Store,
            locationId: $store->id,
            productVariantId: $variant->id,
            type: MovementType::Adjustment,
            quantity: $qty,
            userId: $this->orgAdmin->id,
            notes: 'Sales report fixture',
        ));
    }

    /**
     * @return array<string, string>
     */
    private function headers(?Store $store = null): array
    {
        return ['X-Store-Id' => (string) ($store ?? $this->store)->id];
    }

    private function openSession(User $user, Store $store, string $terminal): int
    {
        Sanctum::actingAs($user, ['staff']);

        return (int) $this->postJson('/api/v1/pos/sessions/open', [
            'terminalName' => $terminal,
            'openingFloat' => '0.0000',
        ], $this->headers($store))->assertCreated()->json('data.id');
    }

    private function completeSale(
        User $user,
        Store $store,
        int $sessionId,
        ProductVariant $variant,
        string $qty,
        string $unitPrice,
        string $method,
        string $idempotencyKey,
    ): int {
        Sanctum::actingAs($user, ['staff']);

        $amount = bcmul($qty, $unitPrice, 4);

        return (int) $this->postJson('/api/v1/pos/sales', [
            'uuid' => (string) Str::uuid(),
            'sessionId' => $sessionId,
            'items' => [[
                'variantId' => $variant->id,
                'qty' => $qty,
                'unitPrice' => $unitPrice,
                'discount' => '0.0000',
            ]],
            'payments' => [['method' => $method, 'amount' => $amount, 'provider' => 'manual']],
        ], array_merge($this->headers($store), ['Idempotency-Key' => $idempotencyKey]))
            ->assertCreated()
            ->json('data.id');
    }

    /**
     * Two in-range sales at the reported store (50.0000 cash by the manager on variant A,
     * 10.5000 card by the cashier on variant B), plus out-of-range and other-store noise.
     */
    private function seedSales(): void
    {
        $managerSession = $this->openSession($this->manager, $this->store, 'RPT-MGR');
        $cashierSession = $this->openSession($this->cashier, $this->store, 'RPT-CSH');

        $this->completeSale($this->manager, $this->store, $managerSession, $this->variantA, '2.0000', '25.0000', 'cash', 'rpt-a');
        $this->completeSale($this->cashier, $this->store, $cashierSession, $this->variantB, '1.0000', '10.5000', 'card', 'rpt-b');

        $stale = $this->completeSale($this->manager, $this->store, $managerSession, $this->variantA, '1.0000', '99.0000', 'cash', 'rpt-stale');
        Sale::query()->whereKey($stale)->update(['created_at' => '2026-03-01 09:00:00']);

        $otherSession = $this->openSession($this->orgAdmin, $this->otherStore, 'RPT-OTHER');
        $this->completeSale($this->orgAdmin, $this->otherStore, $otherSession, $this->variantA, '1.0000', '77.0000', 'cash', 'rpt-other');
    }

    /**
     * @param  array<string, string|int>  $query
     */
    private function reportUrl(array $query = []): string
    {
        return '/api/v1/reports/sales?'.http_build_query(array_merge([
            'from' => '2026-03-05',
            'to' => '2026-03-15',
        ], $query));
    }

    public function test_sales_report_returns_store_scoped_totals_rows_and_breakdowns(): void
    {
        $this->seedSales();

        Sanctum::actingAs($this->manager, ['staff']);
        $response = $this->getJson($this->reportUrl(), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.store.id', $this->store->id)
            ->assertJsonPath('data.range.from', '2026-03-05')
            ->assertJsonPath('data.range.to', '2026-03-15')
            ->assertJsonPath('data.range.timezone', 'UTC')
            ->assertJsonPath('data.currency', 'NGN')
            ->assertJsonPath('data.summary.salesTotal', '60.5000')
            ->assertJsonPath('data.summary.salesCount', 2)
            ->assertJsonPath('data.summary.averageOrderValue', '30.2500')
            ->assertJsonPath('meta.total', 2);

        $rows = $response->json('data.rows');
        $this->assertCount(2, $rows);
        $this->assertSame(['card'], $rows[0]['paymentMethods']);
        $this->assertSame('10.5000', $rows[0]['total']);
        $this->assertSame('Cee Cashier', $rows[0]['cashier']['name']);
        $this->assertSame('50.0000', $rows[1]['total']);

        $cashiers = $response->json('data.cashiers');
        $this->assertSame(
            [
                ['cashierId' => $this->manager->id, 'name' => 'Mo Manager', 'salesCount' => 1, 'salesTotal' => '50.0000'],
                ['cashierId' => $this->cashier->id, 'name' => 'Cee Cashier', 'salesCount' => 1, 'salesTotal' => '10.5000'],
            ],
            $cashiers,
        );

        $topProducts = $response->json('data.topProducts');
        $this->assertCount(2, $topProducts);
        $this->assertSame($this->variantA->id, $topProducts[0]['productVariantId']);
        $this->assertSame('50.0000', $topProducts[0]['lineTotal']);
        $this->assertSame('2.0000', $topProducts[0]['qty']);
        $this->assertSame('10.5000', $topProducts[1]['lineTotal']);
    }

    public function test_empty_range_returns_zeroes_and_no_rows(): void
    {
        $this->seedSales();

        Sanctum::actingAs($this->manager, ['staff']);
        $this->getJson($this->reportUrl(['from' => '2026-01-01', 'to' => '2026-01-31']), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.summary.salesTotal', '0.0000')
            ->assertJsonPath('data.summary.salesCount', 0)
            ->assertJsonPath('data.summary.averageOrderValue', '0.0000')
            ->assertJsonPath('data.rows', [])
            ->assertJsonPath('data.cashiers', [])
            ->assertJsonPath('data.topProducts', []);
    }

    public function test_payment_method_filter_narrows_results(): void
    {
        $this->seedSales();

        Sanctum::actingAs($this->manager, ['staff']);
        $this->getJson($this->reportUrl(['paymentMethod' => 'cash']), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.summary.salesTotal', '50.0000')
            ->assertJsonPath('data.summary.salesCount', 1)
            ->assertJsonPath('data.summary.averageOrderValue', '50.0000')
            ->assertJsonCount(1, 'data.rows');

        $this->getJson($this->reportUrl(['paymentMethod' => 'bitcoin']), $this->headers())
            ->assertStatus(422);
    }

    public function test_cashier_and_product_filters_narrow_results(): void
    {
        $this->seedSales();

        Sanctum::actingAs($this->manager, ['staff']);

        $this->getJson($this->reportUrl(['cashierId' => $this->cashier->id]), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.summary.salesTotal', '10.5000')
            ->assertJsonPath('data.summary.salesCount', 1)
            ->assertJsonPath('data.topProducts.0.productVariantId', $this->variantB->id);

        $this->getJson($this->reportUrl(['productVariantId' => $this->variantA->id]), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.summary.salesTotal', '50.0000')
            ->assertJsonCount(1, 'data.topProducts');

        $this->getJson($this->reportUrl(['categoryId' => $this->categoryB->id]), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.summary.salesTotal', '10.5000')
            ->assertJsonPath('data.topProducts.0.productVariantId', $this->variantB->id);
    }

    public function test_range_is_bucketed_by_organization_calendar_day(): void
    {
        $sessionId = $this->openSession($this->manager, $this->store, 'RPT-TZ');
        $saleId = $this->completeSale($this->manager, $this->store, $sessionId, $this->variantA, '1.0000', '40.0000', 'cash', 'rpt-tz');

        // 2026-03-14 20:00 UTC is 2026-03-15 in UTC+14 but still 2026-03-14 in UTC.
        Sale::query()->whereKey($saleId)->update(['created_at' => '2026-03-14 20:00:00']);

        Sanctum::actingAs($this->manager, ['staff']);
        $this->getJson($this->reportUrl(['from' => '2026-03-15', 'to' => '2026-03-15']), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.summary.salesTotal', '0.0000');

        $this->organization->forceFill(['timezone' => 'Pacific/Kiritimati'])->save();

        $this->getJson($this->reportUrl(['from' => '2026-03-15', 'to' => '2026-03-15']), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.range.timezone', 'Pacific/Kiritimati')
            ->assertJsonPath('data.summary.salesTotal', '40.0000')
            ->assertJsonPath('data.summary.salesCount', 1);
    }

    public function test_report_requires_a_valid_date_range(): void
    {
        Sanctum::actingAs($this->manager, ['staff']);

        $this->getJson('/api/v1/reports/sales', $this->headers())->assertStatus(422);
        $this->getJson($this->reportUrl(['from' => '2026-03-15', 'to' => '2026-03-05']), $this->headers())
            ->assertStatus(422);
        $this->getJson($this->reportUrl(['from' => '15/03/2026']), $this->headers())->assertStatus(422);
    }

    public function test_export_returns_csv_matching_the_json_rows(): void
    {
        $this->seedSales();

        Sanctum::actingAs($this->manager, ['staff']);
        $response = $this->get(
            '/api/v1/reports/sales/export?from=2026-03-05&to=2026-03-15',
            $this->headers(),
        )->assertOk();

        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString(
            'sales-report-'.$this->store->id.'-2026-03-05-to-2026-03-15.csv',
            (string) $response->headers->get('Content-Disposition'),
        );

        $lines = array_values(array_filter(explode("\n", $response->streamedContent())));
        $this->assertSame('sale_id,uuid,sold_at,cashier,total,payment_methods', trim($lines[0]));
        $this->assertCount(3, $lines);
        $this->assertStringContainsString('10.5000,card', $lines[1]);
        $this->assertStringContainsString('50.0000,cash', $lines[2]);
        $this->assertStringNotContainsString('99.0000', $response->streamedContent());
        $this->assertStringNotContainsString('77.0000', $response->streamedContent());
    }

    public function test_export_respects_filters_and_returns_headers_only_when_empty(): void
    {
        $this->seedSales();

        Sanctum::actingAs($this->manager, ['staff']);
        $filtered = $this->get(
            '/api/v1/reports/sales/export?from=2026-03-05&to=2026-03-15&paymentMethod=card',
            $this->headers(),
        )->assertOk();
        $this->assertCount(2, array_values(array_filter(explode("\n", $filtered->streamedContent()))));

        $empty = $this->get(
            '/api/v1/reports/sales/export?from=2026-01-01&to=2026-01-31',
            $this->headers(),
        )->assertOk();
        $this->assertSame(
            'sale_id,uuid,sold_at,cashier,total,payment_methods',
            trim($empty->streamedContent()),
        );
    }

    public function test_report_and_export_require_reports_view_permission(): void
    {
        Sanctum::actingAs($this->cashier, ['staff']);

        $this->getJson($this->reportUrl(), $this->headers())->assertForbidden();
        $this->get('/api/v1/reports/sales/export?from=2026-03-05&to=2026-03-15', $this->headers())
            ->assertForbidden();
    }

    public function test_report_enforces_store_scope(): void
    {
        Sanctum::actingAs($this->manager, ['staff']);

        $this->getJson($this->reportUrl())->assertStatus(422);
        $this->getJson($this->reportUrl(), ['X-Store-Id' => 'abc'])->assertStatus(422);
        $this->getJson($this->reportUrl(), $this->headers($this->otherStore))->assertForbidden();

        $this->get('/api/v1/reports/sales/export?from=2026-03-05&to=2026-03-15')->assertStatus(422);
        $this->get('/api/v1/reports/sales/export?from=2026-03-05&to=2026-03-15', ['X-Store-Id' => 'abc'])
            ->assertStatus(422);
        $this->get('/api/v1/reports/sales/export?from=2026-03-05&to=2026-03-15', $this->headers($this->otherStore))
            ->assertForbidden();

        Sanctum::actingAs($this->orgAdmin, ['staff']);
        $this->getJson($this->reportUrl(), ['X-Store-Id' => '999999'])->assertStatus(422);
        $this->get('/api/v1/reports/sales/export?from=2026-03-05&to=2026-03-15', ['X-Store-Id' => '999999'])
            ->assertStatus(422);
    }

    public function test_held_and_voided_sales_are_excluded(): void
    {
        $sessionId = $this->openSession($this->manager, $this->store, 'RPT-HOLD');
        $this->completeSale($this->manager, $this->store, $sessionId, $this->variantA, '1.0000', '20.0000', 'cash', 'rpt-completed-keep');

        Sanctum::actingAs($this->manager, ['staff']);
        $heldId = (int) $this->postJson('/api/v1/pos/sales/hold', [
            'uuid' => (string) Str::uuid(),
            'customerId' => null,
            'items' => [[
                'variantId' => $this->variantA->id,
                'qty' => '1.0000',
                'unitPrice' => '99.0000',
                'discount' => '0.0000',
            ]],
        ], $this->headers())->assertCreated()->json('data.id');

        $voidedId = $this->completeSale($this->manager, $this->store, $sessionId, $this->variantA, '1.0000', '50.0000', 'cash', 'rpt-void-later');
        Sale::query()->whereKey($voidedId)->update(['status' => 'voided']);
        Sale::query()->whereKey($heldId)->update(['created_at' => now()]);

        $this->getJson($this->reportUrl(['from' => '2026-01-01', 'to' => '2026-12-31']), $this->headers())
            ->assertOk()
            ->assertJsonPath('data.summary.salesTotal', '20.0000')
            ->assertJsonPath('data.summary.salesCount', 1);

        $csv = $this->get('/api/v1/reports/sales/export?from=2026-01-01&to=2026-12-31', $this->headers())
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString(',20.0000,', $csv);
        $this->assertStringNotContainsString(',99.0000,', $csv);
        $this->assertStringNotContainsString(',50.0000,', $csv);
    }

    public function test_export_rejects_ranges_over_row_limit(): void
    {
        Sanctum::actingAs($this->manager, ['staff']);

        $this->app->instance(SalesPerformanceReportService::class, new class extends SalesPerformanceReportService
        {
            public function exportCsv(
                Organization $organization,
                Store $store,
                SalesReportFilters $filters,
                string $timezone,
            ): string {
                abort(422, 'Export range is too large ('.(self::CSV_ROW_LIMIT + 1).' sales). Narrow the date range or filters to at most '.self::CSV_ROW_LIMIT.' sales.');
            }
        });

        $this->get('/api/v1/reports/sales/export?from=2026-03-05&to=2026-03-15', $this->headers())
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Export range is too large ('.(SalesPerformanceReportService::CSV_ROW_LIMIT + 1).' sales). Narrow the date range or filters to at most '.SalesPerformanceReportService::CSV_ROW_LIMIT.' sales.']);
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
        $this->get('/api/v1/reports/sales/export?from=2026-03-05&to=2026-03-15', $this->headers())
            ->assertUnauthorized();
    }
}
