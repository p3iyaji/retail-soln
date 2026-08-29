<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\Customers\Models\Customer;
use Modules\Customers\Services\CustomerService;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Modules\Sales\Enums\InstallmentPlanStatus;
use Modules\Sales\Enums\InstallmentStatus;
use Modules\Sales\Models\Installment;
use Modules\Sales\Models\InstallmentPayment;
use Modules\Sales\Models\InstallmentPlan;
use Tests\TestCase;

class InstallmentCollectionApiTest extends TestCase
{
    use RefreshDatabase;

    private int $orgId;

    private Store $store;

    private ProductVariant $variant;

    private Customer $customer;

    private InstallmentPlan $plan;

    private Installment $installment;

    private int $sessionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionMatrixSeeder::class);
        $ledger = app(InventoryLedgerService::class);
        $this->orgId = app(OrganizationService::class)->current()->id;
        $this->store = Store::factory()->create([
            'organization_id' => $this->orgId,
            'tax_config' => ['rate' => '0.0750', 'inclusive' => false, 'name' => 'VAT'],
        ]);

        $product = Product::factory()->create(['organization_id' => $this->orgId, 'sku' => 'COL-P1']);
        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->orgId,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'COL-V1',
            'price' => '10.0000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $ledger->post(new PostMovementData(
            organizationId: $this->orgId,
            locationType: LocationType::Store,
            locationId: $this->store->id,
            productVariantId: $this->variant->id,
            type: MovementType::Adjustment,
            quantity: '10.0000',
            userId: User::factory()->inventoryManager()->create()->id,
            notes: 'Seed',
        ));

        $this->customer = app(CustomerService::class)->create($this->orgId, [
            'name' => 'Collector Customer',
            'type' => 'registered',
            'credit_limit' => '1000.0000',
        ]);

        $manager = User::factory()->storeManager()->create();
        $manager->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($manager);

        $sessionRes = $this->postJson('/api/v1/pos/sessions/open', [
            'terminalName' => 'POS-COL',
            'openingFloat' => '0.0000',
        ], ['X-Store-Id' => (string) $this->store->id])->assertCreated();
        $this->sessionId = (int) $sessionRes->json('data.id');

        $saleRes = $this->postJson('/api/v1/pos/sales', [
            'uuid' => '770e8400-e29b-41d4-a716-446655440002',
            'customerId' => $this->customer->id,
            'sessionId' => $this->sessionId,
            'items' => [[
                'variantId' => $this->variant->id,
                'qty' => '1.0000',
                'unitPrice' => '10.0000',
                'discount' => '0.0000',
            ]],
            'payments' => [['method' => 'cash', 'amount' => '5.0000', 'provider' => 'manual']],
            'installmentPlan' => [
                'mode' => 'pay_over_time',
                'installments' => [
                    ['sequence' => 1, 'amount' => '5.7500', 'dueAt' => now()->subDay()->toDateString()],
                ],
            ],
        ], [
            'Idempotency-Key' => 'collect-setup-sale',
            'X-Store-Id' => (string) $this->store->id,
        ])->assertCreated();

        $this->plan = InstallmentPlan::query()->findOrFail($saleRes->json('data.installmentPlan.id'));
        $this->installment = Installment::query()->where('installment_plan_id', $this->plan->id)->firstOrFail();
        $this->customer->refresh();
    }

    private function collectPayload(string $amount = '5.7500', ?int $sessionId = null): array
    {
        return [
            'installmentId' => $this->installment->id,
            'sessionId' => $sessionId ?? $this->sessionId,
            'payment' => [
                'method' => 'cash',
                'amount' => $amount,
                'provider' => 'manual',
            ],
        ];
    }

    public function test_cashier_collects_installment_and_closes_plan(): void
    {
        $cashier = User::factory()->cashier()->create();
        $cashier->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($cashier);
        $sessionId = $this->openSessionFor($cashier);

        $response = $this->postJson(
            '/api/v1/installment-plans/'.$this->plan->id.'/payments',
            $this->collectPayload('5.7500', $sessionId),
            [
                'Idempotency-Key' => 'collect-1',
                'X-Store-Id' => (string) $this->store->id,
            ],
        );

        $response->assertCreated()
            ->assertJsonPath('data.amount', '5.7500')
            ->assertJsonPath('data.statement.planOutstanding', '0.0000')
            ->assertJsonPath('data.statement.planStatus', 'closed');

        $this->installment->refresh();
        $this->plan->refresh();
        $this->customer->refresh();

        $this->assertSame(InstallmentStatus::Paid, $this->installment->status);
        $this->assertSame(InstallmentPlanStatus::Closed, $this->plan->status);
        $this->assertSame('0.0000', $this->customer->balance);
        $this->assertDatabaseHas('cash_movements', [
            'session_id' => $sessionId,
            'type' => 'installment_cash',
            'amount' => '5.7500',
        ]);
    }

    private function openSessionFor(User $user): int
    {
        $response = $this->postJson('/api/v1/pos/sessions/open', [
            'terminalName' => 'POS-COL-'.$user->id,
            'openingFloat' => '0.0000',
        ], ['X-Store-Id' => (string) $this->store->id]);

        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    public function test_idempotent_collection_replay(): void
    {
        $cashier = User::factory()->cashier()->create();
        $cashier->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($cashier);
        $sessionId = $this->openSessionFor($cashier);

        $this->postJson(
            '/api/v1/installment-plans/'.$this->plan->id.'/payments',
            $this->collectPayload('5.7500', $sessionId),
            ['Idempotency-Key' => 'collect-idem', 'X-Store-Id' => (string) $this->store->id],
        )->assertCreated();

        $this->postJson(
            '/api/v1/installment-plans/'.$this->plan->id.'/payments',
            $this->collectPayload('5.7500', $sessionId),
            ['Idempotency-Key' => 'collect-idem', 'X-Store-Id' => (string) $this->store->id],
        )->assertOk();

        $this->assertSame(1, InstallmentPayment::query()->count());
    }

    public function test_partial_collection_requires_permission(): void
    {
        $cashier = User::factory()->cashier()->create();
        $cashier->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($cashier);
        $sessionId = $this->openSessionFor($cashier);

        $this->postJson(
            '/api/v1/installment-plans/'.$this->plan->id.'/payments',
            $this->collectPayload('2.0000', $sessionId),
            ['Idempotency-Key' => 'collect-partial', 'X-Store-Id' => (string) $this->store->id],
        )->assertStatus(422)->assertJsonPath('message', 'Full installment amount is required.');
    }

    public function test_overpay_beyond_plan_balance_rejected(): void
    {
        $manager = User::factory()->storeManager()->create();
        $manager->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($manager);
        $sessionId = $this->openSessionFor($manager);

        $this->postJson(
            '/api/v1/installment-plans/'.$this->plan->id.'/payments',
            $this->collectPayload('6.0000', $sessionId),
            ['Idempotency-Key' => 'collect-over', 'X-Store-Id' => (string) $this->store->id],
        )->assertStatus(422)->assertJsonPath('message', 'Payment exceeds remaining plan balance.');
    }

    public function test_list_open_plans_marks_overdue_installment(): void
    {
        $cashier = User::factory()->cashier()->create();
        $cashier->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($cashier);

        $this->getJson('/api/v1/installment-plans?overdueOnly=1', [
            'X-Store-Id' => (string) $this->store->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.0.installments.0.isOverdue', true)
            ->assertJsonPath('data.0.installments.0.status', 'overdue');
    }

    public function test_cancel_open_plan_reduces_customer_balance(): void
    {
        $balanceBefore = (string) $this->customer->balance;
        $manager = User::factory()->storeManager()->create();
        $manager->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($manager);

        $this->postJson(
            '/api/v1/installment-plans/'.$this->plan->id.'/cancel',
            [],
            ['X-Store-Id' => (string) $this->store->id],
        )
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->customer->refresh();
        $this->assertSame('0.0000', (string) $this->customer->balance);
        $this->assertNotSame($balanceBefore, (string) $this->customer->balance);
    }
}
