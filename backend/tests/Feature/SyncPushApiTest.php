<?php

namespace Tests\Feature;

use App\Models\User;
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
use Modules\Sales\Enums\CashierSessionStatus;
use Modules\Sales\Models\CashierSession;
use Modules\Sales\Models\Sale;
use Modules\Sync\Enums\OversellExceptionStatus;
use Modules\Sync\Models\Device;
use Modules\Sync\Models\InventoryOversellException;
use Modules\Sync\Models\SyncRecord;
use Tests\TestCase;

class SyncPushApiTest extends TestCase
{
    use RefreshDatabase;

    private int $orgId;

    private Store $store;

    private ProductVariant $variant;

    private InventoryLedgerService $ledger;

    private User $cashier;

    private int $sessionId;

    private string $deviceUuid = '550e8400-e29b-41d4-a716-446655440099';

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
            'sku' => 'SYNC-P1',
        ]);

        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->orgId,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'SYNC-V1',
            'price' => '10.0000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->seedStock('10.0000');

        $this->cashier = User::factory()->cashier()->create();
        $this->cashier->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($this->cashier);
        $this->sessionId = $this->openSession($this->cashier);

        Device::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => $this->store->id,
            'uuid' => $this->deviceUuid,
            'name' => 'POS Sync',
            'enabled' => true,
        ]);
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
            notes: 'Seed for sync tests',
        ));
    }

    private function openSession(User $user): int
    {
        Sanctum::actingAs($user);
        $response = $this->postJson('/api/v1/pos/sessions/open', [
            'terminalName' => 'POS-SYNC',
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
    private function salePayload(string $uuid, ?int $sessionId = null, string $qty = '1.0000', string $total = '10.7500'): array
    {
        return [
            'uuid' => $uuid,
            'customerId' => null,
            'sessionId' => $sessionId ?? $this->sessionId,
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
    private function push(array $records, ?string $deviceUuid = null): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->cashier);

        return $this->postJson('/api/v1/sync/push', [
            'deviceUuid' => $deviceUuid ?? $this->deviceUuid,
            'records' => $records,
        ], $this->headers());
    }

    public function test_first_push_creates_sale_and_sync_record(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440001';

        $response = $this->push([[
            'uuid' => $uuid,
            'localSequence' => 1,
            'payloadType' => 'sale',
            'createdAt' => now()->toIso8601String(),
            'payload' => $this->salePayload($uuid),
        ]]);

        $response->assertOk()
            ->assertJsonPath('data.items.0.uuid', $uuid)
            ->assertJsonPath('data.items.0.status', 'synced');

        $saleId = $response->json('data.items.0.saleId');
        $this->assertNotNull($saleId);
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'uuid' => $uuid]);
        $this->assertDatabaseHas('sync_records', [
            'uuid' => $uuid,
            'status' => 'synced',
            'sale_id' => $saleId,
        ]);
        $this->assertDatabaseHas('devices', [
            'uuid' => $this->deviceUuid,
            'enabled' => true,
            'store_id' => $this->store->id,
        ]);
    }

    public function test_duplicate_uuid_is_idempotent(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440002';
        $envelope = [
            'uuid' => $uuid,
            'localSequence' => 1,
            'payloadType' => 'sale',
            'payload' => $this->salePayload($uuid),
        ];

        $first = $this->push([$envelope]);
        $first->assertOk();
        $saleId = $first->json('data.items.0.saleId');

        $second = $this->push([$envelope]);
        $second->assertOk()
            ->assertJsonPath('data.items.0.saleId', $saleId)
            ->assertJsonPath('data.items.0.status', 'synced');

        $this->assertSame(1, Sale::query()->count());
        $this->assertSame(1, InventoryMovement::query()->where('type', MovementType::Sale)->count());
        $this->assertSame(1, SyncRecord::query()->where('uuid', $uuid)->count());
    }

    public function test_oversell_posts_sale_and_creates_exception(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440003';
        // Stock is 10; sell 11 → deficit 1.0000; total = 11 * 10 * 1.075 = 118.2500
        $response = $this->push([[
            'uuid' => $uuid,
            'localSequence' => 1,
            'payloadType' => 'sale',
            'payload' => $this->salePayload($uuid, null, '11.0000', '118.2500'),
        ]]);

        $response->assertOk()
            ->assertJsonPath('data.items.0.status', 'synced');

        $exceptionId = $response->json('data.items.0.exceptionId');
        $this->assertNotNull($exceptionId);
        $this->assertDatabaseHas('inventory_oversell_exceptions', [
            'id' => $exceptionId,
            'sale_id' => $response->json('data.items.0.saleId'),
            'product_variant_id' => $this->variant->id,
            'qty_deficit' => '1.0000',
            'status' => OversellExceptionStatus::Pending->value,
        ]);

        $available = $this->ledger->balanceFor(
            $this->orgId,
            LocationType::Store->value,
            $this->store->id,
            $this->variant->id,
        )?->available;
        $this->assertSame('-1.0000', $available);
    }

    public function test_online_sale_still_hard_rejects_insufficient_stock(): void
    {
        Sanctum::actingAs($this->cashier);
        $this->postJson('/api/v1/pos/sales', $this->salePayload(
            '550e8400-e29b-41d4-a716-4466554400aa',
            null,
            '11.0000',
            '118.2500',
        ), [
            'Idempotency-Key' => 'online-stock-1',
            'X-Store-Id' => (string) $this->store->id,
        ])->assertStatus(422)->assertJsonPath('message', 'Insufficient available stock for sale.');

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_disabled_device_rejects_batch(): void
    {
        Device::query()
            ->where('organization_id', $this->orgId)
            ->where('uuid', $this->deviceUuid)
            ->update(['enabled' => false]);

        $uuid = '550e8400-e29b-41d4-a716-446655440004';
        $this->push([[
            'uuid' => $uuid,
            'localSequence' => 1,
            'payloadType' => 'sale',
            'payload' => $this->salePayload($uuid),
        ]])->assertForbidden()
            ->assertJsonPath('code', 'device_disabled');

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_unregistered_device_rejects_batch_without_creating_row(): void
    {
        $unknown = '550e8400-e29b-41d4-a716-4466554400ff';
        $before = Device::query()->count();

        $uuid = '550e8400-e29b-41d4-a716-446655440005';
        $this->push([[
            'uuid' => $uuid,
            'localSequence' => 1,
            'payloadType' => 'sale',
            'payload' => $this->salePayload($uuid),
        ]], $unknown)->assertForbidden()
            ->assertJsonPath('code', 'device_unregistered');

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame($before, Device::query()->count());
        $this->assertDatabaseMissing('devices', ['uuid' => $unknown]);
    }

    public function test_cross_store_device_is_unregistered_and_store_id_unchanged(): void
    {
        $otherStore = Store::factory()->create(['organization_id' => $this->orgId]);
        $this->cashier->stores()->attach($otherStore->id, ['is_default' => false]);

        $device = Device::query()
            ->where('organization_id', $this->orgId)
            ->where('uuid', $this->deviceUuid)
            ->firstOrFail();
        $originalStoreId = (int) $device->store_id;

        Sanctum::actingAs($this->cashier);
        $uuid = '550e8400-e29b-41d4-a716-446655440006';
        $this->postJson('/api/v1/sync/push', [
            'deviceUuid' => $this->deviceUuid,
            'records' => [[
                'uuid' => $uuid,
                'localSequence' => 1,
                'payloadType' => 'sale',
                'payload' => $this->salePayload($uuid),
            ]],
        ], ['X-Store-Id' => (string) $otherStore->id])
            ->assertForbidden()
            ->assertJsonPath('code', 'device_unregistered');

        $device->refresh();
        $this->assertSame($originalStoreId, (int) $device->store_id);
        $this->assertSame(0, Sale::query()->count());
    }

    public function test_successful_push_updates_last_seen_at(): void
    {
        $device = Device::query()
            ->where('organization_id', $this->orgId)
            ->where('uuid', $this->deviceUuid)
            ->firstOrFail();
        $device->forceFill(['last_seen_at' => null])->save();

        $uuid = '550e8400-e29b-41d4-a716-446655440007';
        $this->push([[
            'uuid' => $uuid,
            'localSequence' => 1,
            'payloadType' => 'sale',
            'createdAt' => now()->toIso8601String(),
            'payload' => $this->salePayload($uuid),
        ]])->assertOk();

        $device->refresh();
        $this->assertNotNull($device->last_seen_at);
    }

    public function test_closed_session_remaps_to_open_session(): void
    {
        $oldSessionId = $this->sessionId;
        CashierSession::query()->whereKey($oldSessionId)->update([
            'status' => CashierSessionStatus::Closed,
            'closed_at' => now(),
            'expected_cash' => '0.0000',
            'closing_cash' => '0.0000',
            'variance' => '0.0000',
        ]);

        $newSessionId = $this->openSession($this->cashier);
        $uuid = '550e8400-e29b-41d4-a716-446655440005';

        $this->push([[
            'uuid' => $uuid,
            'localSequence' => 1,
            'payloadType' => 'sale',
            'payload' => $this->salePayload($uuid, $oldSessionId),
        ]])->assertOk()
            ->assertJsonPath('data.items.0.status', 'synced');

        $this->assertDatabaseHas('sales', [
            'uuid' => $uuid,
            'session_id' => $newSessionId,
        ]);
    }

    public function test_closed_session_with_none_open_returns_item_error(): void
    {
        CashierSession::query()->whereKey($this->sessionId)->update([
            'status' => CashierSessionStatus::Closed,
            'closed_at' => now(),
            'expected_cash' => '0.0000',
            'closing_cash' => '0.0000',
            'variance' => '0.0000',
        ]);

        $uuid = '550e8400-e29b-41d4-a716-446655440006';
        $this->push([[
            'uuid' => $uuid,
            'localSequence' => 1,
            'payloadType' => 'sale',
            'payload' => $this->salePayload($uuid, $this->sessionId),
        ]])->assertOk()
            ->assertJsonPath('data.items.0.status', 'error');

        $this->assertSame(0, Sale::query()->count());
    }

    public function test_repush_after_closed_session_error_succeeds_when_session_open(): void
    {
        CashierSession::query()->whereKey($this->sessionId)->update([
            'status' => CashierSessionStatus::Closed,
            'closed_at' => now(),
            'expected_cash' => '0.0000',
            'closing_cash' => '0.0000',
            'variance' => '0.0000',
        ]);

        $uuid = '550e8400-e29b-41d4-a716-446655440011';
        $envelope = [
            'uuid' => $uuid,
            'localSequence' => 1,
            'payloadType' => 'sale',
            'payload' => $this->salePayload($uuid, $this->sessionId),
        ];

        $this->push([$envelope])
            ->assertOk()
            ->assertJsonPath('data.items.0.status', 'error');

        $this->assertSame(0, Sale::query()->count());

        $newSessionId = $this->openSession($this->cashier);

        $this->push([$envelope])
            ->assertOk()
            ->assertJsonPath('data.items.0.status', 'synced');

        $this->assertSame(1, Sale::query()->count());
        $this->assertDatabaseHas('sales', [
            'uuid' => $uuid,
            'session_id' => $newSessionId,
        ]);
    }

    public function test_installment_plan_payload_is_rejected_per_item(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440012';
        $payload = $this->salePayload($uuid);
        $payload['installmentPlan'] = [
            'mode' => 'pay_over_time',
            'installments' => [
                ['sequence' => 1, 'amount' => '5.0000', 'dueAt' => '2030-01-01'],
                ['sequence' => 2, 'amount' => '5.7500', 'dueAt' => '2030-02-01'],
            ],
        ];

        $response = $this->push([[
            'uuid' => $uuid,
            'localSequence' => 1,
            'payloadType' => 'sale',
            'payload' => $payload,
        ]]);

        $response->assertOk()
            ->assertJsonPath('data.items.0.status', 'error');

        $errors = $response->json('data.items.0.errors') ?? [];
        $this->assertNotEmpty($errors);
        $this->assertStringContainsStringIgnoringCase('installment', implode(' ', $errors));
        $this->assertSame(0, Sale::query()->count());
    }

    public function test_mid_batch_item_failure_allows_others_to_sync(): void
    {
        $goodUuid = '550e8400-e29b-41d4-a716-446655440007';
        $badUuid = '550e8400-e29b-41d4-a716-446655440008';

        $badPayload = $this->salePayload($badUuid);
        $badPayload['items'][0]['variantId'] = 999999;

        $response = $this->push([
            [
                'uuid' => $goodUuid,
                'localSequence' => 1,
                'payloadType' => 'sale',
                'payload' => $this->salePayload($goodUuid),
            ],
            [
                'uuid' => $badUuid,
                'localSequence' => 2,
                'payloadType' => 'sale',
                'payload' => $badPayload,
            ],
        ]);

        $response->assertOk();
        $items = collect($response->json('data.items'))->keyBy('uuid');
        $this->assertSame('synced', $items[$goodUuid]['status']);
        $this->assertSame('error', $items[$badUuid]['status']);
        $this->assertSame(1, Sale::query()->count());
    }

    public function test_unsupported_payload_type_errors_item(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440009';
        $this->push([[
            'uuid' => $uuid,
            'localSequence' => 1,
            'payloadType' => 'return',
            'payload' => ['foo' => 'bar'],
        ]])->assertOk()
            ->assertJsonPath('data.items.0.status', 'error');
    }

    public function test_approve_oversell_exception(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440010';
        $push = $this->push([[
            'uuid' => $uuid,
            'localSequence' => 1,
            'payloadType' => 'sale',
            'payload' => $this->salePayload($uuid, null, '11.0000', '118.2500'),
        ]]);
        $exceptionId = $push->json('data.items.0.exceptionId');

        $manager = User::factory()->inventoryManager()->create();
        $manager->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($manager);

        $this->postJson("/api/v1/sync/oversell-exceptions/{$exceptionId}/approve", [], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.id', $exceptionId);

        $this->assertDatabaseHas('inventory_oversell_exceptions', [
            'id' => $exceptionId,
            'status' => OversellExceptionStatus::Approved->value,
            'approved_by' => $manager->id,
        ]);
    }
}
