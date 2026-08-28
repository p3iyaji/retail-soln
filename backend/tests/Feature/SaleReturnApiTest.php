<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Modules\Sales\Enums\CashMovementType;
use Modules\Sales\Models\CashMovement;
use Modules\Sales\Models\Sale;
use Modules\Sales\Models\SaleReturn;
use Tests\TestCase;

class SaleReturnApiTest extends TestCase
{
    use RefreshDatabase;

    private int $orgId;

    private Store $store;

    private ProductVariant $variant;

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
            'sku' => 'RET-P1',
        ]);

        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->orgId,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'RET-V1',
            'price' => '10.0000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->seedStock('20.0000');
    }

    private function seedStock(string $qty): void
    {
        $this->ledger->post(new \Modules\Inventory\Data\PostMovementData(
            organizationId: $this->orgId,
            locationType: LocationType::Store,
            locationId: $this->store->id,
            productVariantId: $this->variant->id,
            type: MovementType::Adjustment,
            quantity: $qty,
            userId: User::factory()->inventoryManager()->create()->id,
            notes: 'Seed for return tests',
        ));
    }

    private function actingManager(): User
    {
        $user = User::factory()->storeManager()->create();
        $user->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function openSession(User $user): int
    {
        Sanctum::actingAs($user);

        return (int) $this->postJson('/api/v1/pos/sessions/open', [
            'terminalName' => 'POS-RET',
            'openingFloat' => '100.0000',
        ], ['X-Store-Id' => (string) $this->store->id])
            ->assertCreated()
            ->json('data.id');
    }

    private function createCompletedSale(User $user, string $qty = '2.0000', string $key = 'ret-sale-1'): Sale
    {
        $this->sessionId = $this->openSession($user);

        $total = $qty === '2.0000' ? '21.5000' : '10.7500';

        $response = $this->postJson('/api/v1/pos/sales', [
            'uuid' => 'aa0e8400-e29b-41d4-a716-446655440001',
            'sessionId' => $this->sessionId,
            'items' => [
                [
                    'variantId' => $this->variant->id,
                    'qty' => $qty,
                    'unitPrice' => '10.0000',
                    'discount' => '0.0000',
                ],
            ],
            'payments' => [
                ['method' => 'cash', 'amount' => $total, 'provider' => 'manual'],
            ],
        ], [
            'Idempotency-Key' => $key,
            'X-Store-Id' => (string) $this->store->id,
        ])->assertCreated();

        return Sale::query()->findOrFail($response->json('data.id'));
    }

    private function headers(): array
    {
        return ['X-Store-Id' => (string) $this->store->id];
    }

    public function test_returnable_sale_lookup(): void
    {
        $manager = $this->actingManager();
        $sale = $this->createCompletedSale($manager);
        $saleItemId = $sale->items()->firstOrFail()->id;

        $this->getJson("/api/v1/pos/sales/{$sale->id}/returnable", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.id', $sale->id)
            ->assertJsonPath('data.items.0.id', $saleItemId)
            ->assertJsonPath('data.items.0.returnableQty', '2.0000');
    }

    public function test_full_cash_return_restores_stock_and_records_refund_cash(): void
    {
        $manager = $this->actingManager();
        $sale = $this->createCompletedSale($manager);
        $saleItemId = $sale->items()->firstOrFail()->id;

        $before = $this->ledger->balanceFor(
            $this->orgId,
            LocationType::Store->value,
            $this->store->id,
            $this->variant->id,
        )?->available;

        $response = $this->postJson('/api/v1/pos/returns', [
            'uuid' => 'bb0e8400-e29b-41d4-a716-446655440002',
            'saleId' => $sale->id,
            'sessionId' => $this->sessionId,
            'reason' => 'defective',
            'items' => [
                ['saleItemId' => $saleItemId, 'qty' => '2.0000'],
            ],
            'refunds' => [
                ['method' => 'cash', 'amount' => '21.5000', 'provider' => 'manual'],
            ],
        ], [
            'Idempotency-Key' => 'ret-full-1',
            ...$this->headers(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.total', '21.5000')
            ->assertJsonPath('data.refunds.0.method', 'cash');

        $after = $this->ledger->balanceFor(
            $this->orgId,
            LocationType::Store->value,
            $this->store->id,
            $this->variant->id,
        )?->available;

        $this->assertSame(
            bcadd((string) $before, '2.0000', 4),
            $after,
        );

        $this->assertSame(1, InventoryMovement::query()->where('type', MovementType::Return)->count());
        $this->assertDatabaseHas('cash_movements', [
            'session_id' => $this->sessionId,
            'type' => CashMovementType::RefundCash->value,
            'reference_type' => 'sale_return',
        ]);
    }

    public function test_partial_return_with_store_credit(): void
    {
        $manager = $this->actingManager();
        $sale = $this->createCompletedSale($manager);
        $saleItemId = $sale->items()->firstOrFail()->id;

        $this->postJson('/api/v1/pos/returns', [
            'uuid' => 'cc0e8400-e29b-41d4-a716-446655440003',
            'saleId' => $sale->id,
            'reason' => 'changed_mind',
            'items' => [
                ['saleItemId' => $saleItemId, 'qty' => '1.0000'],
            ],
            'refunds' => [
                ['method' => 'store_credit', 'amount' => '10.7500', 'provider' => 'manual'],
            ],
        ], [
            'Idempotency-Key' => 'ret-partial-1',
            ...$this->headers(),
        ])->assertCreated();

        $this->getJson("/api/v1/pos/sales/{$sale->id}/returnable", $this->headers())
            ->assertJsonPath('data.items.0.returnableQty', '1.0000');
    }

    public function test_over_return_qty_rejected(): void
    {
        $manager = $this->actingManager();
        $sale = $this->createCompletedSale($manager, '1.0000', 'ret-sale-2');
        $saleItemId = $sale->items()->firstOrFail()->id;

        $this->postJson('/api/v1/pos/returns', [
            'uuid' => 'dd0e8400-e29b-41d4-a716-446655440004',
            'saleId' => $sale->id,
            'reason' => 'other',
            'items' => [
                ['saleItemId' => $saleItemId, 'qty' => '2.0000'],
            ],
            'refunds' => [
                ['method' => 'cash', 'amount' => '21.5000', 'provider' => 'manual'],
            ],
        ], [
            'Idempotency-Key' => 'ret-over-1',
            ...$this->headers(),
        ])->assertStatus(422);

        $this->assertSame(0, SaleReturn::query()->count());
    }

    public function test_cashier_cannot_process_return(): void
    {
        $manager = $this->actingManager();
        $sale = $this->createCompletedSale($manager, '1.0000', 'ret-sale-3');
        $saleItemId = $sale->items()->firstOrFail()->id;

        $cashier = User::factory()->cashier()->create();
        $cashier->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($cashier);

        $this->getJson("/api/v1/pos/sales/{$sale->id}/returnable", $this->headers())->assertForbidden();

        $this->postJson('/api/v1/pos/returns', [
            'uuid' => 'ee0e8400-e29b-41d4-a716-446655440005',
            'saleId' => $sale->id,
            'reason' => 'defective',
            'items' => [['saleItemId' => $saleItemId, 'qty' => '1.0000']],
            'refunds' => [['method' => 'cash', 'amount' => '10.7500', 'provider' => 'manual']],
        ], [
            'Idempotency-Key' => 'ret-auth-1',
            ...$this->headers(),
        ])->assertForbidden();
    }

    public function test_idempotency_replay(): void
    {
        $manager = $this->actingManager();
        $sale = $this->createCompletedSale($manager, '1.0000', 'ret-sale-4');
        $saleItemId = $sale->items()->firstOrFail()->id;

        $payload = [
            'uuid' => 'ff0e8400-e29b-41d4-a716-446655440006',
            'saleId' => $sale->id,
            'reason' => 'wrong_item',
            'items' => [['saleItemId' => $saleItemId, 'qty' => '1.0000']],
            'refunds' => [['method' => 'cash', 'amount' => '10.7500', 'provider' => 'manual']],
        ];

        $this->postJson('/api/v1/pos/returns', $payload, [
            'Idempotency-Key' => 'ret-idem-1',
            ...$this->headers(),
        ])->assertCreated();

        $this->postJson('/api/v1/pos/returns', $payload, [
            'Idempotency-Key' => 'ret-idem-1',
            ...$this->headers(),
        ])->assertOk();

        $this->assertSame(1, SaleReturn::query()->count());
        $this->assertSame(1, InventoryMovement::query()->where('type', MovementType::Return)->count());
    }
}
