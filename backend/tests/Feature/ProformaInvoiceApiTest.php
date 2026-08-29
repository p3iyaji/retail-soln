<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\Customers\Models\Customer;
use Modules\Customers\Services\CustomerService;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Models\InventoryBalance;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Pricing\Services\PricingService;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Modules\Sales\Enums\ProformaInvoiceStatus;
use Modules\Sales\Jobs\SendProformaInvoice;
use Modules\Sales\Models\InstallmentPlan;
use Modules\Sales\Models\Invoice;
use Modules\Sales\Models\ProformaInvoice;
use Modules\Sales\Models\Sale;
use Modules\Sales\Services\SaleLineCalculator;
use Tests\TestCase;

class ProformaInvoiceApiTest extends TestCase
{
    use RefreshDatabase;

    private int $orgId;

    private Store $store;

    private ProductVariant $variant;

    private Customer $customer;

    private ?int $sessionId = null;

    private InventoryLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionMatrixSeeder::class);
        $this->ledger = app(InventoryLedgerService::class);
        $this->orgId = app(OrganizationService::class)->current()->id;
        $this->store = Store::factory()->create([
            'organization_id' => $this->orgId,
            'tax_config' => ['rate' => '0.0750', 'inclusive' => false, 'name' => 'VAT'],
        ]);

        $product = Product::factory()->create(['organization_id' => $this->orgId, 'sku' => 'PF-P1']);
        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->orgId,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'PF-V1',
            'price' => '10.0000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->customer = app(CustomerService::class)->create($this->orgId, [
            'name' => 'Proforma Customer',
            'type' => 'registered',
            'email' => 'proforma@example.com',
            'credit_limit' => '1000.0000',
        ]);

        $manager = User::factory()->storeManager()->create();
        $manager->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($manager, ['staff']);
    }

    private function seedStock(string $qty = '10.0000'): void
    {
        $this->ledger->post(new PostMovementData(
            organizationId: $this->orgId,
            locationType: LocationType::Store,
            locationId: $this->store->id,
            productVariantId: $this->variant->id,
            type: MovementType::Adjustment,
            quantity: $qty,
            userId: User::factory()->inventoryManager()->create()->id,
            notes: 'Seed for proforma convert',
        ));
    }

    private function openSession(): int
    {
        if ($this->sessionId !== null) {
            return $this->sessionId;
        }

        $response = $this->postJson('/api/v1/pos/sessions/open', [
            'terminalName' => 'POS-PF',
            'openingFloat' => '0.0000',
        ], ['X-Store-Id' => (string) $this->store->id])->assertCreated();

        $this->sessionId = (int) $response->json('data.id');

        return $this->sessionId;
    }

    private function createIssuedProforma(array $overrides = []): int
    {
        $payload = array_merge([
            'storeId' => $this->store->id,
            'customerId' => $this->customer->id,
            'items' => [[
                'variantId' => $this->variant->id,
                'qty' => '1.0000',
                'unitPrice' => '10.0000',
                'discount' => '0.0000',
            ]],
        ], $overrides);

        $id = (int) $this->postJson('/api/v1/proforma-invoices', $payload)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/proforma-invoices/{$id}/issue")->assertOk();

        return $id;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function convertProforma(int $id, array $payload, string $key = 'pf-convert-1'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1/proforma-invoices/{$id}/convert", $payload, [
            'Idempotency-Key' => $key,
            'X-Store-Id' => (string) $this->store->id,
        ]);
    }

    public function test_create_draft_proforma_without_inventory_or_ar_side_effects(): void
    {
        $balanceCountBefore = InventoryBalance::query()->count();

        $response = $this->postJson('/api/v1/proforma-invoices', [
            'storeId' => $this->store->id,
            'customerId' => $this->customer->id,
            'notes' => 'Quote for bulk order',
            'validUntil' => now()->addDays(14)->toDateString(),
            'items' => [[
                'variantId' => $this->variant->id,
                'qty' => '2.0000',
                'unitPrice' => '10.0000',
                'discount' => '0.0000',
            ]],
        ])->assertCreated();

        $response->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.number', null)
            ->assertJsonPath('data.customerId', $this->customer->id);

        $this->customer->refresh();
        $this->assertSame('0.0000', (string) $this->customer->balance);
        $this->assertSame($balanceCountBefore, InventoryBalance::query()->count());
    }

    public function test_issue_assigns_proforma_number_and_dispatches_delivery_job(): void
    {
        Queue::fake();

        $create = $this->postJson('/api/v1/proforma-invoices', [
            'storeId' => $this->store->id,
            'customerId' => $this->customer->id,
            'items' => [[
                'variantId' => $this->variant->id,
                'qty' => '1.0000',
                'unitPrice' => '10.0000',
                'discount' => '0.0000',
            ]],
        ])->assertCreated();

        $id = (int) $create->json('data.id');

        $issue = $this->postJson("/api/v1/proforma-invoices/{$id}/issue")->assertOk();
        $number = (string) $issue->json('data.number');

        $this->assertStringStartsWith('PF-'.$this->store->id.'-', $number);
        $issue->assertJsonPath('data.status', 'issued');

        Queue::assertPushed(SendProformaInvoice::class, fn (SendProformaInvoice $job) => $job->proformaInvoiceId === $id);
    }

    public function test_pdf_shows_proforma_label_after_issue(): void
    {
        $id = (int) $this->postJson('/api/v1/proforma-invoices', [
            'storeId' => $this->store->id,
            'customerId' => $this->customer->id,
            'items' => [[
                'variantId' => $this->variant->id,
                'qty' => '1.0000',
                'unitPrice' => '10.0000',
                'discount' => '0.0000',
            ]],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/proforma-invoices/{$id}/issue")->assertOk();

        $pdf = $this->get("/api/v1/proforma-invoices/{$id}/pdf");
        $pdf->assertOk();
        $pdf->assertSee('Proforma — Not a tax invoice', false);
        $pdf->assertSee('PROFORMA', false);
        $pdf->assertSee('PF-'.$this->store->id.'-', false);
    }

    public function test_cancel_issued_proforma(): void
    {
        $id = (int) $this->postJson('/api/v1/proforma-invoices', [
            'storeId' => $this->store->id,
            'customerId' => $this->customer->id,
            'items' => [[
                'variantId' => $this->variant->id,
                'qty' => '1.0000',
                'unitPrice' => '10.0000',
                'discount' => '0.0000',
            ]],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/proforma-invoices/{$id}/issue")->assertOk();
        $this->postJson("/api/v1/proforma-invoices/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_expired_proforma_on_show(): void
    {
        $proforma = ProformaInvoice::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => $this->store->id,
            'customer_id' => $this->customer->id,
            'number' => 'PF-'.$this->store->id.'-0099',
            'status' => ProformaInvoiceStatus::Issued,
            'subtotal' => '10.0000',
            'discount_total' => '0.0000',
            'tax_total' => '0.7500',
            'total' => '10.7500',
            'valid_until' => now()->subDay()->toDateString(),
            'created_by' => User::factory()->storeManager()->create()->id,
            'issued_by' => User::factory()->storeManager()->create()->id,
            'issued_at' => now()->subDays(10),
        ]);

        $this->getJson("/api/v1/proforma-invoices/{$proforma->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'expired');
    }

    public function test_walk_in_customer_rejected(): void
    {
        $walkIn = app(CustomerService::class)->ensureWalkIn($this->orgId);

        $this->postJson('/api/v1/proforma-invoices', [
            'storeId' => $this->store->id,
            'customerId' => $walkIn->id,
            'items' => [[
                'variantId' => $this->variant->id,
                'qty' => '1.0000',
                'unitPrice' => '10.0000',
                'discount' => '0.0000',
            ]],
        ])->assertUnprocessable();
    }

    public function test_convert_to_sale_posts_inventory_and_links_documents(): void
    {
        Queue::fake();
        $this->seedStock();

        $balanceBefore = InventoryBalance::query()
            ->where('product_variant_id', $this->variant->id)
            ->value('available');

        $proformaId = $this->createIssuedProforma();
        $sessionId = $this->openSession();

        $response = $this->convertProforma($proformaId, [
            'target' => 'sale',
            'uuid' => '880e8400-e29b-41d4-a716-446655440003',
            'sessionId' => $sessionId,
            'payments' => [['method' => 'cash', 'amount' => '10.7500', 'provider' => 'manual']],
        ])->assertOk();

        $response->assertJsonPath('data.proforma.status', 'converted')
            ->assertJsonPath('data.proforma.convertedSaleId', fn ($v) => $v > 0)
            ->assertJsonPath('data.proforma.convertedInvoiceId', fn ($v) => $v > 0)
            ->assertJsonPath('data.sale.total', '10.7500');

        $balanceAfter = InventoryBalance::query()
            ->where('product_variant_id', $this->variant->id)
            ->value('available');

        $this->assertSame(
            bcsub((string) $balanceBefore, '1.0000', 4),
            (string) $balanceAfter,
        );

        $this->assertSame(1, Sale::query()->whereKey($response->json('data.sale.id'))->count());
        $this->assertSame(1, Invoice::query()->whereKey($response->json('data.invoice.id'))->count());
    }

    public function test_convert_to_invoice_only_skips_inventory(): void
    {
        $balanceCountBefore = InventoryBalance::query()->count();
        $proformaId = $this->createIssuedProforma();

        $response = $this->convertProforma($proformaId, [
            'target' => 'invoice',
        ], 'pf-convert-invoice-only')->assertOk();

        $response->assertJsonPath('data.proforma.status', 'converted')
            ->assertJsonPath('data.sale', null)
            ->assertJsonPath('data.invoice.invoiceNumber', fn ($v) => str_starts_with((string) $v, 'TI-'.$this->store->id.'-'));

        $this->assertSame($balanceCountBefore, InventoryBalance::query()->count());
    }

    public function test_convert_reprices_without_honor_prices_permission(): void
    {
        $this->seedStock();
        $proformaId = $this->createIssuedProforma([
            'items' => [[
                'variantId' => $this->variant->id,
                'qty' => '1.0000',
                'unitPrice' => '10.0000',
                'discount' => '0.0000',
            ]],
        ]);

        $this->variant->update(['price' => '20.0000']);
        $sessionId = $this->openSession();

        $resolved = app(PricingService::class)->resolve(
            $this->orgId,
            $this->variant->id,
            $this->store->id,
            '1.0000',
            $this->customer->id,
        );
        $computed = app(SaleLineCalculator::class)->compute($this->store, [[
            'variantId' => $this->variant->id,
            'qty' => '1.0000',
            'unitPrice' => $resolved['listPrice'],
            'discount' => $resolved['discountAmount'],
        ]]);

        $response = $this->convertProforma($proformaId, [
            'target' => 'sale',
            'uuid' => '880e8400-e29b-41d4-a716-446655440004',
            'sessionId' => $sessionId,
            'payments' => [['method' => 'cash', 'amount' => $computed['total'], 'provider' => 'manual']],
        ], 'pf-convert-reprice')->assertOk();

        $response->assertJsonPath('data.sale.total', $computed['total']);
        $this->assertNotSame('10.7500', $response->json('data.sale.total'));
    }

    public function test_convert_honors_proforma_prices_when_requested(): void
    {
        Queue::fake();
        $this->seedStock();
        $proformaId = $this->createIssuedProforma();
        $this->variant->update(['price' => '20.0000']);
        $sessionId = $this->openSession();

        $this->convertProforma($proformaId, [
            'target' => 'sale',
            'uuid' => '880e8400-e29b-41d4-a716-446655440006',
            'sessionId' => $sessionId,
            'honorPrices' => true,
            'payments' => [['method' => 'cash', 'amount' => '10.7500', 'provider' => 'manual']],
        ], 'pf-convert-honor')->assertOk()->assertJsonPath('data.sale.total', '10.7500');
    }

    public function test_convert_rejects_cancelled_proforma(): void
    {
        $proformaId = $this->createIssuedProforma();
        $this->postJson("/api/v1/proforma-invoices/{$proformaId}/cancel")->assertOk();

        $this->convertProforma($proformaId, [
            'target' => 'invoice',
        ], 'pf-convert-cancelled')->assertUnprocessable();
    }

    public function test_convert_with_installment_plan_at_conversion(): void
    {
        Queue::fake();
        $this->seedStock();
        $proformaId = $this->createIssuedProforma();
        $sessionId = $this->openSession();

        $response = $this->convertProforma($proformaId, [
            'target' => 'both',
            'uuid' => '880e8400-e29b-41d4-a716-446655440005',
            'sessionId' => $sessionId,
            'payments' => [['method' => 'cash', 'amount' => '5.0000', 'provider' => 'manual']],
            'installmentPlan' => [
                'mode' => 'pay_over_time',
                'installments' => [
                    ['sequence' => 1, 'amount' => '5.7500', 'dueAt' => now()->addMonth()->toDateString()],
                ],
            ],
        ], 'pf-convert-installment')->assertOk();

        $saleId = (int) $response->json('data.sale.id');
        $this->assertSame(1, InstallmentPlan::query()->where('sale_id', $saleId)->count());
        $this->customer->refresh();
        $this->assertSame('5.7500', (string) $this->customer->balance);
    }
}
