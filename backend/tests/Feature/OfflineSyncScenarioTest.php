<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
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
use Modules\Sales\Models\Sale;
use Modules\Sync\Enums\OversellExceptionStatus;
use Modules\Sync\Models\Device;
use Modules\Sync\Models\InventoryOversellException;
use Modules\Sync\Models\SyncRecord;
use Tests\TestCase;

/**
 * Epic 9 / offline-sync design scenario matrix — complements SyncPushApiTest.
 */
class OfflineSyncScenarioTest extends TestCase
{
    use RefreshDatabase;

    private int $orgId;

    private Store $store;

    private ProductVariant $variant;

    private InventoryLedgerService $ledger;

    private User $cashier;

    private int $sessionId;

    private string $deviceA = '550e8400-e29b-41d4-a716-4466554400a1';

    private string $deviceB = '550e8400-e29b-41d4-a716-4466554400b2';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
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
            'sku' => 'SCENARIO-P1',
        ]);

        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->orgId,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'SCENARIO-V1',
            'price' => '10.0000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->seedStock('10.0000');

        $this->cashier = User::factory()->cashier()->create();
        $this->cashier->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($this->cashier, ['staff']);
        $this->sessionId = $this->openSession($this->cashier);

        foreach ([$this->deviceA => 'POS A', $this->deviceB => 'POS B'] as $uuid => $name) {
            Device::query()->create([
                'organization_id' => $this->orgId,
                'store_id' => $this->store->id,
                'uuid' => $uuid,
                'name' => $name,
                'enabled' => true,
            ]);
        }
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
            notes: 'Seed for offline scenario tests',
        ));
    }

    private function openSession(User $user): int
    {
        Sanctum::actingAs($user, ['staff']);
        $response = $this->postJson('/api/v1/pos/sessions/open', [
            'terminalName' => 'POS-SCENARIO',
            'openingFloat' => '0.0000',
        ], ['X-Store-Id' => (string) $this->store->id]);

        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    /**
     * @return array<string, mixed>
     */
    private function headers(): array
    {
        return ['X-Store-Id' => (string) $this->store->id];
    }

    /**
     * @return array<string, mixed>
     */
    private function salePayload(string $uuid, string $qty = '1.0000', string $total = '10.7500'): array
    {
        return [
            'uuid' => $uuid,
            'customerId' => null,
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
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function push(array $records, string $deviceUuid): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->cashier, ['staff']);

        return $this->postJson('/api/v1/sync/push', [
            'deviceUuid' => $deviceUuid,
            'records' => $records,
        ], $this->headers());
    }

    public function test_concurrent_offline_oversell_from_two_devices(): void
    {
        $uuidA = '550e8400-e29b-41d4-a716-4466554400c1';
        $uuidB = '550e8400-e29b-41d4-a716-4466554400c2';

        $this->push([[
            'uuid' => $uuidA,
            'localSequence' => 1,
            'payloadType' => 'sale',
            'payload' => $this->salePayload($uuidA, '8.0000', '86.0000'),
        ]], $this->deviceA)->assertOk()
            ->assertJsonPath('data.items.0.status', 'synced');

        $this->push([[
            'uuid' => $uuidB,
            'localSequence' => 1,
            'payloadType' => 'sale',
            'payload' => $this->salePayload($uuidB, '8.0000', '86.0000'),
        ]], $this->deviceB)->assertOk()
            ->assertJsonPath('data.items.0.status', 'synced')
            ->assertJsonPath('data.items.0.exceptionId', fn ($id) => $id !== null);

        $this->assertSame(2, Sale::query()->count());
        $this->assertSame(1, InventoryOversellException::query()->count());
        $this->assertSame(2, InventoryMovement::query()->where('type', MovementType::Sale)->count());

        $available = $this->ledger->balanceFor(
            $this->orgId,
            LocationType::Store->value,
            $this->store->id,
            $this->variant->id,
        )?->available;
        $this->assertSame('-6.0000', $available);
    }

    public function test_device_disabled_mid_queue_rejects_subsequent_batch(): void
    {
        $firstUuid = '550e8400-e29b-41d4-a716-4466554400d1';
        $secondUuid = '550e8400-e29b-41d4-a716-4466554400d2';

        $this->push([[
            'uuid' => $firstUuid,
            'localSequence' => 1,
            'payloadType' => 'sale',
            'payload' => $this->salePayload($firstUuid),
        ]], $this->deviceA)->assertOk()
            ->assertJsonPath('data.items.0.status', 'synced');

        Device::query()
            ->where('uuid', $this->deviceA)
            ->update(['enabled' => false]);

        $this->push([[
            'uuid' => $secondUuid,
            'localSequence' => 2,
            'payloadType' => 'sale',
            'payload' => $this->salePayload($secondUuid),
        ]], $this->deviceA)->assertForbidden()
            ->assertJsonPath('code', 'device_disabled');

        $this->assertSame(1, Sale::query()->count());
        $this->assertDatabaseMissing('sync_records', ['uuid' => $secondUuid]);
    }

    public function test_clock_skew_created_at_does_not_block_sync(): void
    {
        $pastUuid = '550e8400-e29b-41d4-a716-4466554400e1';
        $futureUuid = '550e8400-e29b-41d4-a716-4466554400e2';

        $response = $this->push([
            [
                'uuid' => $pastUuid,
                'localSequence' => 1,
                'payloadType' => 'sale',
                'createdAt' => Carbon::now()->subDays(30)->toIso8601String(),
                'payload' => $this->salePayload($pastUuid),
            ],
            [
                'uuid' => $futureUuid,
                'localSequence' => 2,
                'payloadType' => 'sale',
                'createdAt' => Carbon::now()->addDay()->toIso8601String(),
                'payload' => $this->salePayload($futureUuid),
            ],
        ], $this->deviceA);

        $response->assertOk();
        $items = collect($response->json('data.items'))->keyBy('uuid');
        $this->assertSame('synced', $items[$pastUuid]['status']);
        $this->assertSame('synced', $items[$futureUuid]['status']);
        $this->assertSame(2, Sale::query()->count());
    }

    public function test_retry_after_item_error_then_duplicate_push_is_idempotent(): void
    {
        $badUuid = '550e8400-e29b-41d4-a716-4466554400f1';
        $badPayload = $this->salePayload($badUuid);
        $badPayload['items'][0]['variantId'] = 999999;

        $this->push([[
            'uuid' => $badUuid,
            'localSequence' => 1,
            'payloadType' => 'sale',
            'payload' => $badPayload,
        ]], $this->deviceA)->assertOk()
            ->assertJsonPath('data.items.0.status', 'error');

        $fixedPayload = $this->salePayload($badUuid);
        $retry = $this->push([[
            'uuid' => $badUuid,
            'localSequence' => 1,
            'payloadType' => 'sale',
            'payload' => $fixedPayload,
        ]], $this->deviceA)->assertOk()
            ->assertJsonPath('data.items.0.status', 'synced');

        $saleId = $retry->json('data.items.0.saleId');

        $this->push([[
            'uuid' => $badUuid,
            'localSequence' => 1,
            'payloadType' => 'sale',
            'payload' => $fixedPayload,
        ]], $this->deviceA)->assertOk()
            ->assertJsonPath('data.items.0.saleId', $saleId)
            ->assertJsonPath('data.items.0.status', 'synced');

        $this->assertSame(1, Sale::query()->count());
        $this->assertSame(1, SyncRecord::query()->where('uuid', $badUuid)->where('status', 'synced')->count());
    }

    public function test_sync_status_is_store_scoped(): void
    {
        $otherStore = Store::factory()->create(['organization_id' => $this->orgId]);

        Sanctum::actingAs($this->cashier, ['staff']);

        $this->getJson('/api/v1/sync/status?deviceUuid='.$this->deviceA, $this->headers())
            ->assertOk()
            ->assertJsonPath('data.deviceEnabled', true);

        $this->getJson('/api/v1/sync/status', ['X-Store-Id' => (string) $otherStore->id])
            ->assertForbidden();
    }

    public function test_oversell_exceptions_list_is_store_scoped(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440011';
        $push = $this->push([[
            'uuid' => $uuid,
            'localSequence' => 1,
            'payloadType' => 'sale',
            'payload' => $this->salePayload($uuid, '11.0000', '118.2500'),
        ]], $this->deviceA);
        $push->assertOk();

        $exceptionId = $push->json('data.items.0.exceptionId');
        $this->assertNotNull($exceptionId);

        $otherStore = Store::factory()->create(['organization_id' => $this->orgId]);
        $manager = User::factory()->inventoryManager()->create();
        $manager->stores()->attach($this->store->id, ['is_default' => true]);
        $manager->stores()->attach($otherStore->id, ['is_default' => false]);
        Sanctum::actingAs($manager, ['staff']);

        $this->getJson('/api/v1/sync/oversell-exceptions', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.0.id', $exceptionId);

        $this->getJson('/api/v1/sync/oversell-exceptions', ['X-Store-Id' => (string) $otherStore->id])
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->postJson("/api/v1/sync/oversell-exceptions/{$exceptionId}/approve", [], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.status', OversellExceptionStatus::Approved->value);
    }
}
