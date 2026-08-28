<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Modules\Audit\Models\AuditLog;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\Customers\Models\Customer;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Modules\Sales\Jobs\SendSaleReceipt;
use Modules\Sales\Models\Invoice;
use Modules\Sales\Models\Sale;
use Tests\TestCase;

class SaleApiTest extends TestCase
{
    use RefreshDatabase;

    private int $orgId;

    private Store $store;

    private ProductVariant $variant;

    private InventoryLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionMatrixSeeder::class);
        $this->ledger = app(InventoryLedgerService::class);
        $this->orgId = app(OrganizationService::class)->current()->id;
        $this->store = Store::factory()->create([
            'organization_id' => $this->orgId,
            'tax_config' => [
                'rate' => '0.0750',
                'inclusive' => false,
                'name' => 'VAT',
            ],
        ]);

        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'sku' => 'SALE-P1',
        ]);

        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->orgId,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'SALE-V1',
            'price' => '10.0000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->seedStock('10.0000');
    }

    private function seedStock(string $qty): void
    {
        $this->ledger->post(new PostMovementData(
            organizationId: $this->orgId,
            locationType: LocationType::Store,
            locationId: $this->store->id,
            productVariantId: $this->variant->id,
            type: MovementType::Adjustment,
            quantity: $qty,
            userId: User::factory()->inventoryManager()->create()->id,
            notes: 'Seed for sale tests',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function salePayload(string $total = '10.7500', ?array $payments = null): array
    {
        return [
            'uuid' => '550e8400-e29b-41d4-a716-446655440000',
            'customerId' => null,
            'items' => [
                [
                    'variantId' => $this->variant->id,
                    'qty' => '1.0000',
                    'unitPrice' => '10.0000',
                    'discount' => '0.0000',
                ],
            ],
            'payments' => $payments ?? [
                ['method' => 'cash', 'amount' => $total, 'provider' => 'manual'],
            ],
        ];
    }

    private function postSale(array $payload, string $key = 'sale-happy-1', ?User $user = null): \Illuminate\Testing\TestResponse
    {
        $actor = $user ?? User::factory()->cashier()->create();
        if (! $actor->isOrgWide()) {
            $actor->stores()->attach($this->store->id, ['is_default' => true]);
        }
        Sanctum::actingAs($actor);

        return $this->postJson('/api/v1/pos/sales', $payload, [
            'Idempotency-Key' => $key,
            'X-Store-Id' => (string) $this->store->id,
        ]);
    }

    public function test_happy_cash_sale_posts_stock_and_invoice(): void
    {
        Queue::fake();

        $response = $this->postSale($this->salePayload());

        $response->assertCreated()
            ->assertJsonPath('data.total', '10.7500')
            ->assertJsonPath('data.taxTotal', '0.7500')
            ->assertJsonPath('data.items.0.qty', '1.0000');

        $saleId = $response->json('data.id');
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'total' => '10.7500']);
        $this->assertDatabaseHas('invoices', ['sale_id' => $saleId, 'status' => 'issued']);

        $this->assertSame(
            '9.0000',
            $this->ledger->balanceFor(
                $this->orgId,
                LocationType::Store->value,
                $this->store->id,
                $this->variant->id,
            )?->available
        );

        $this->assertSame(1, InventoryMovement::query()->where('type', MovementType::Sale)->count());
        $this->assertGreaterThan(0, AuditLog::query()->where('auditable_type', Sale::class)->count());
        Queue::assertPushed(SendSaleReceipt::class);
    }

    public function test_split_tender_sale(): void
    {
        Queue::fake();

        $response = $this->postSale($this->salePayload('10.7500', [
            ['method' => 'cash', 'amount' => '5.0000', 'provider' => 'manual'],
            ['method' => 'card', 'amount' => '5.7500', 'provider' => 'manual'],
        ]), 'sale-split-1');

        $response->assertCreated();
        $this->assertSame(2, Sale::query()->first()?->payments()->count());
    }

    public function test_idempotency_replay_and_conflict(): void
    {
        Queue::fake();

        $payload = $this->salePayload();
        $this->postSale($payload, 'sale-idem-1')->assertCreated();
        $this->postSale($payload, 'sale-idem-1')->assertOk();

        $this->assertSame(1, Sale::query()->count());
        $this->assertSame(1, InventoryMovement::query()->where('type', MovementType::Sale)->count());

        $conflict = $this->salePayload('10.7500');
        $conflict['items'][0]['qty'] = '2.0000';
        $this->postSale($conflict, 'sale-idem-1')->assertStatus(409);
    }

    public function test_payment_mismatch_rejected(): void
    {
        $response = $this->postSale($this->salePayload('9.0000'), 'sale-underpay-1');
        $response->assertStatus(422)->assertJsonPath('message', 'Payment total must equal sale total.');
        $this->assertSame(0, Sale::query()->count());
    }

    public function test_insufficient_stock_rejected(): void
    {
        $payload = $this->salePayload('118.2500');
        $payload['items'][0]['qty'] = '11.0000';
        $payload['payments'] = [
            ['method' => 'cash', 'amount' => '118.2500', 'provider' => 'manual'],
        ];

        $this->postSale($payload, 'sale-stock-1')->assertStatus(422);
        $this->assertSame(0, Sale::query()->count());
    }

    public function test_installment_plan_rejected(): void
    {
        $payload = $this->salePayload();
        $payload['installmentPlan'] = ['mode' => 'pay_over_time'];

        $this->postSale($payload, 'sale-install-1')->assertStatus(422);
    }

    public function test_missing_idempotency_key_rejected(): void
    {
        $user = User::factory()->cashier()->create();
        $user->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/pos/sales', $this->salePayload(), [
            'X-Store-Id' => (string) $this->store->id,
        ])->assertStatus(422);
    }

    public function test_cashier_without_permission_forbidden(): void
    {
        $user = User::factory()->create(['role' => 'accountant']);
        $user->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/pos/sales', $this->salePayload(), [
            'Idempotency-Key' => 'sale-auth-1',
            'X-Store-Id' => (string) $this->store->id,
        ])->assertForbidden();
    }

    public function test_walk_in_customer_used_when_customer_id_null(): void
    {
        Queue::fake();

        $response = $this->postSale($this->salePayload(), 'sale-walkin-1');
        $response->assertCreated();

        $customerId = $response->json('data.customerId');
        $this->assertTrue(Customer::query()->whereKey($customerId)->value('is_walk_in'));
    }

    public function test_receipt_show_and_print(): void
    {
        Queue::fake();

        $saleId = $this->postSale($this->salePayload(), 'sale-receipt-1')
            ->assertCreated()
            ->json('data.id');

        $cashier = User::factory()->cashier()->create();
        $cashier->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($cashier);

        $this->getJson("/api/v1/pos/receipts/{$saleId}")
            ->assertOk()
            ->assertJsonPath('data.saleId', $saleId)
            ->assertJsonPath('data.total', '10.7500');

        $this->postJson("/api/v1/pos/receipts/{$saleId}/print")
            ->assertOk()
            ->assertJsonPath('meta.queued', true);

        Queue::assertPushed(SendSaleReceipt::class, 2);
    }
}
