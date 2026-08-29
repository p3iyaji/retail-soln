<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Audit\Models\AuditLog;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Modules\Sales\Enums\SaleStatus;
use Modules\Sales\Models\Sale;
use Tests\TestCase;

class SaleHoldVoidApiTest extends TestCase
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
        $this->store = Store::factory()->create(['organization_id' => $this->orgId]);

        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'sku' => 'HV-P1',
        ]);

        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->orgId,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'HV-V1',
            'price' => '10.0000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->seedStock('20.0000');
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
            notes: 'Seed for hold/void tests',
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function cartPayload(): array
    {
        return [
            'uuid' => '660e8400-e29b-41d4-a716-446655440001',
            'customerId' => null,
            'items' => [
                [
                    'variantId' => $this->variant->id,
                    'qty' => '2.0000',
                    'unitPrice' => '10.0000',
                    'discount' => '0.0000',
                ],
            ],
        ];
    }

    private function actingCashier(): User
    {
        $user = User::factory()->cashier()->create();
        $user->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function openSessionFor(User $user, string $terminal = 'POS-HV'): int
    {
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/pos/sessions/open', [
            'terminalName' => $terminal,
            'openingFloat' => '0.0000',
        ], ['X-Store-Id' => (string) $this->store->id]);

        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postHold(array $payload): \Illuminate\Testing\TestResponse
    {
        $this->actingCashier();

        return $this->postJson('/api/v1/pos/sales/hold', $payload, [
            'X-Store-Id' => (string) $this->store->id,
        ]);
    }

    public function test_hold_creates_held_sale_without_inventory_movement(): void
    {
        $response = $this->postHold($this->cartPayload());

        $response->assertCreated()
            ->assertJsonPath('data.status', 'held')
            ->assertJsonPath('data.total', '21.5000')
            ->assertJsonPath('data.items.0.qty', '2.0000');

        $this->assertSame(0, InventoryMovement::query()->where('type', MovementType::Sale)->count());
        $this->assertSame('20.0000', $this->ledger->balanceFor(
            $this->orgId,
            LocationType::Store->value,
            $this->store->id,
            $this->variant->id,
        )?->available);
    }

    public function test_list_and_show_held_sales(): void
    {
        $holdId = $this->postHold($this->cartPayload())->assertCreated()->json('data.id');

        $this->actingCashier();
        $this->getJson('/api/v1/pos/sales/held', ['X-Store-Id' => (string) $this->store->id])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $holdId);

        $this->getJson("/api/v1/pos/sales/{$holdId}", ['X-Store-Id' => (string) $this->store->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'held');
    }

    public function test_void_held_sale_has_no_inventory_impact(): void
    {
        $holdId = $this->postHold($this->cartPayload())->assertCreated()->json('data.id');

        $this->actingCashier();
        $this->postJson("/api/v1/pos/sales/{$holdId}/void", ['reason' => 'Customer left'])
            ->assertOk()
            ->assertJsonPath('data.status', 'voided');

        $this->assertDatabaseHas('sales', ['id' => $holdId, 'status' => SaleStatus::Voided->value]);
        $this->assertSame(0, InventoryMovement::query()->where('type', MovementType::Return)->count());
        $this->assertGreaterThan(0, AuditLog::query()->where('auditable_type', Sale::class)->count());
    }

    public function test_void_completed_sale_reverses_inventory(): void
    {
        $cashier = $this->actingCashier();
        $sessionId = $this->openSessionFor($cashier, 'POS-HV-1');

        $complete = $this->postJson('/api/v1/pos/sales', [
            'uuid' => '770e8400-e29b-41d4-a716-446655440002',
            'sessionId' => $sessionId,
            'items' => $this->cartPayload()['items'],
            'payments' => [['method' => 'cash', 'amount' => '21.5000', 'provider' => 'manual']],
        ], [
            'Idempotency-Key' => 'hv-complete-1',
            'X-Store-Id' => (string) $this->store->id,
        ])->assertCreated();

        $saleId = $complete->json('data.id');
        $this->assertSame('18.0000', $this->ledger->balanceFor(
            $this->orgId,
            LocationType::Store->value,
            $this->store->id,
            $this->variant->id,
        )?->available);

        $manager = User::factory()->storeManager()->create();
        $manager->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($manager);

        $this->postJson("/api/v1/pos/sales/{$saleId}/void", ['reason' => 'Wrong item'])
            ->assertOk()
            ->assertJsonPath('data.status', 'voided');

        $this->assertSame('20.0000', $this->ledger->balanceFor(
            $this->orgId,
            LocationType::Store->value,
            $this->store->id,
            $this->variant->id,
        )?->available);
        $this->assertSame(1, InventoryMovement::query()->where('type', MovementType::Return)->count());
        unset($cashier);
    }

    public function test_cashier_cannot_void_completed_sale(): void
    {
        $cashier = $this->actingCashier();
        $sessionId = $this->openSessionFor($cashier, 'POS-HV-2');

        $saleId = $this->postJson('/api/v1/pos/sales', [
            'uuid' => '880e8400-e29b-41d4-a716-446655440003',
            'sessionId' => $sessionId,
            'items' => $this->cartPayload()['items'],
            'payments' => [['method' => 'cash', 'amount' => '21.5000', 'provider' => 'manual']],
        ], [
            'Idempotency-Key' => 'hv-complete-2',
            'X-Store-Id' => (string) $this->store->id,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/pos/sales/{$saleId}/void")
            ->assertForbidden();
    }
}
