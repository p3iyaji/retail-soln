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
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Modules\Sales\Models\Installment;
use Modules\Sales\Models\InstallmentPlan;
use Modules\Sales\Models\Sale;
use Tests\TestCase;

class InstallmentPlanApiTest extends TestCase
{
    use RefreshDatabase;

    private int $orgId;

    private Store $store;

    private ProductVariant $variant;

    private Customer $registeredCustomer;

    private InventoryLedgerService $ledger;

    private ?int $sessionId = null;

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
            'sku' => 'INST-P1',
        ]);

        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->orgId,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'INST-V1',
            'price' => '10.0000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->registeredCustomer = app(CustomerService::class)->create($this->orgId, [
            'name' => 'Chinedu Adeyemi',
            'type' => 'registered',
            'phone' => '08012345678',
            'credit_limit' => '1000.0000',
            'balance' => '0.0000',
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
            notes: 'Seed for installment tests',
        ));
    }

    private function openSession(User $user): int
    {
        if (! $user->isOrgWide() && ! $user->stores()->whereKey($this->store->id)->exists()) {
            $user->stores()->attach($this->store->id, ['is_default' => true]);
        }
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/pos/sessions/open', [
            'terminalName' => 'POS-INST',
            'openingFloat' => '0.0000',
        ], ['X-Store-Id' => (string) $this->store->id]);

        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    /**
     * @return array<string, mixed>
     */
    private function installmentSalePayload(
        string $deposit = '5.0000',
        ?array $installments = null,
        ?int $customerId = null,
    ): array {
        return [
            'uuid' => '660e8400-e29b-41d4-a716-446655440001',
            'customerId' => $customerId ?? $this->registeredCustomer->id,
            'sessionId' => $this->sessionId,
            'items' => [
                [
                    'variantId' => $this->variant->id,
                    'qty' => '1.0000',
                    'unitPrice' => '10.0000',
                    'discount' => '0.0000',
                ],
            ],
            'payments' => [
                ['method' => 'cash', 'amount' => $deposit, 'provider' => 'manual'],
            ],
            'installmentPlan' => [
                'mode' => 'pay_over_time',
                'installments' => $installments ?? [
                    ['sequence' => 1, 'amount' => '5.7500', 'dueAt' => '2026-09-24'],
                ],
            ],
        ];
    }

    private function postInstallmentSale(array $payload, string $key, User $user): \Illuminate\Testing\TestResponse
    {
        if ($this->sessionId === null) {
            $this->sessionId = $this->openSession($user);
        }
        $payload['sessionId'] = $this->sessionId;

        return $this->postJson('/api/v1/pos/sales', $payload, [
            'Idempotency-Key' => $key,
            'X-Store-Id' => (string) $this->store->id,
        ]);
    }

    public function test_installment_sale_creates_plan_and_updates_customer_balance(): void
    {
        Queue::fake();

        $manager = User::factory()->storeManager()->create();
        $response = $this->postInstallmentSale(
            $this->installmentSalePayload(),
            'inst-happy-1',
            $manager,
        );

        $response->assertCreated()
            ->assertJsonPath('data.total', '10.7500')
            ->assertJsonPath('data.installmentPlan.principal', '5.7500')
            ->assertJsonPath('data.installmentPlan.installments.0.amount', '5.7500')
            ->assertJsonPath('data.installmentPlan.installments.0.status', 'pending');

        $saleId = $response->json('data.id');
        $planId = $response->json('data.installmentPlan.id');

        $this->assertDatabaseHas('installment_plans', [
            'id' => $planId,
            'sale_id' => $saleId,
            'customer_id' => $this->registeredCustomer->id,
            'principal' => '5.7500',
            'status' => 'open',
        ]);
        $this->assertSame(1, Installment::query()->where('installment_plan_id', $planId)->count());
        $this->assertSame('5.7500', Customer::query()->find($this->registeredCustomer->id)?->balance);
        $this->assertSame(1, Sale::query()->count());
    }

    public function test_installment_sale_allows_split_deposit_tender(): void
    {
        Queue::fake();

        $manager = User::factory()->storeManager()->create();
        $payload = $this->installmentSalePayload('5.0000');
        $payload['payments'] = [
            ['method' => 'cash', 'amount' => '3.0000', 'provider' => 'manual'],
            ['method' => 'card', 'amount' => '2.0000', 'provider' => 'manual'],
        ];

        $this->postInstallmentSale($payload, 'inst-split-1', $manager)->assertCreated();
    }

    public function test_walk_in_customer_rejected_for_installment_plan(): void
    {
        $manager = User::factory()->storeManager()->create();
        $walkIn = app(CustomerService::class)->ensureWalkIn($this->orgId);

        $payload = $this->installmentSalePayload(customerId: $walkIn->id);
        $this->postInstallmentSale($payload, 'inst-walkin-1', $manager)->assertStatus(422);
    }

    public function test_cashier_without_installment_create_permission_forbidden(): void
    {
        $cashier = User::factory()->cashier()->create();
        $this->postInstallmentSale($this->installmentSalePayload(), 'inst-auth-1', $cashier)
            ->assertForbidden();
    }

    public function test_installment_amounts_must_match_balance_after_deposit(): void
    {
        $manager = User::factory()->storeManager()->create();
        $payload = $this->installmentSalePayload('5.0000', [
            ['sequence' => 1, 'amount' => '4.0000', 'dueAt' => '2026-09-24'],
        ]);

        $this->postInstallmentSale($payload, 'inst-mismatch-1', $manager)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Installment amounts must equal the sale balance after deposit.');
        $this->assertSame(0, InstallmentPlan::query()->count());
    }

    public function test_credit_limit_enforced(): void
    {
        $manager = User::factory()->storeManager()->create();
        $this->registeredCustomer->credit_limit = '5.0000';
        $this->registeredCustomer->save();

        $this->postInstallmentSale($this->installmentSalePayload(), 'inst-credit-1', $manager)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Customer credit limit exceeded.');
    }
}
