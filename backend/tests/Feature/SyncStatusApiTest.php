<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\Customers\Services\CustomerService;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Modules\Sales\Enums\SaleStatus;
use Modules\Sales\Models\Sale;
use Modules\Sales\Models\Terminal;
use Modules\Sync\Enums\OversellExceptionStatus;
use Modules\Sync\Models\Device;
use Modules\Sync\Models\InventoryOversellException;
use Modules\Sync\Models\SyncBatch;
use Tests\TestCase;

class SyncStatusApiTest extends TestCase
{
    use RefreshDatabase;

    private int $orgId;

    private Store $store;

    private ProductVariant $variant;

    private User $cashier;

    private string $deviceUuid = '550e8400-e29b-41d4-a716-446655440099';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed(PermissionMatrixSeeder::class);
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
            'sku' => 'STAT-P1',
        ]);

        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->orgId,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'STAT-V1',
            'price' => '10.0000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        app(InventoryLedgerService::class)->post(new PostMovementData(
            organizationId: $this->orgId,
            locationType: LocationType::Store,
            locationId: $this->store->id,
            productVariantId: $this->variant->id,
            type: MovementType::Adjustment,
            quantity: '1.0000',
            userId: User::factory()->inventoryManager()->create()->id,
            notes: 'Seed for status tests',
        ));

        $this->cashier = User::factory()->cashier()->create();
        $this->cashier->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($this->cashier, ['staff']);
    }

    /**
     * @return array<string, string>
     */
    private function headers(?string $deviceUuid = null): array
    {
        $h = ['X-Store-Id' => (string) $this->store->id];
        if ($deviceUuid !== null) {
            $h['X-Device-Uuid'] = $deviceUuid;
        }

        return $h;
    }

    private function seedSaleWithPendingException(string $uuid): InventoryOversellException
    {
        $walkIn = app(CustomerService::class)->ensureWalkIn($this->orgId);
        $terminal = Terminal::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => $this->store->id,
            'uuid' => '550e8400-e29b-41d4-a716-4466554400aa',
            'name' => 'POS-STATUS',
            'status' => 'active',
        ]);

        $sessionId = $this->postJson('/api/v1/pos/sessions/open', [
            'terminalName' => 'POS-STATUS',
            'openingFloat' => '0.0000',
        ], $this->headers())->json('data.id');

        $sale = Sale::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => $this->store->id,
            'uuid' => $uuid,
            'customer_id' => $walkIn->id,
            'cashier_id' => $this->cashier->id,
            'subtotal' => '10.0000',
            'discount_total' => '0.0000',
            'tax_total' => '0.7500',
            'total' => '10.7500',
            'status' => SaleStatus::Completed,
            'idempotency_key' => $uuid,
            'terminal_id' => $terminal->id,
            'session_id' => $sessionId,
        ]);

        return InventoryOversellException::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => $this->store->id,
            'sale_id' => $sale->id,
            'product_variant_id' => $this->variant->id,
            'qty_deficit' => '1.0000',
            'status' => OversellExceptionStatus::Pending,
        ]);
    }

    public function test_status_returns_pending_oversell_count_and_sync_summary(): void
    {
        $device = Device::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => $this->store->id,
            'uuid' => $this->deviceUuid,
            'enabled' => true,
        ]);

        SyncBatch::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => $this->store->id,
            'device_id' => $device->id,
            'user_id' => $this->cashier->id,
            'record_count' => 1,
        ]);

        $this->seedSaleWithPendingException('550e8400-e29b-41d4-a716-446655440001');

        $this->getJson('/api/v1/sync/status?deviceUuid='.$this->deviceUuid, $this->headers())
            ->assertOk()
            ->assertJsonPath('data.pendingOversellCount', 1)
            ->assertJsonPath('data.deviceEnabled', true)
            ->assertJsonPath('data.pendingClientHint', null)
            ->assertJsonStructure([
                'data' => [
                    'lastPushAt',
                    'lastPullAt',
                    'pendingOversellCount',
                    'pendingClientHint',
                    'deviceEnabled',
                ],
            ]);

        $this->assertNotNull($this->getJson('/api/v1/sync/status', $this->headers())->json('data.lastPushAt'));
    }

    public function test_status_omits_device_enabled_without_device_uuid(): void
    {
        $this->getJson('/api/v1/sync/status', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.pendingOversellCount', 0)
            ->assertJsonMissingPath('data.deviceEnabled');
    }

    public function test_status_reports_disabled_device(): void
    {
        Device::query()->create([
            'organization_id' => $this->orgId,
            'store_id' => $this->store->id,
            'uuid' => $this->deviceUuid,
            'enabled' => false,
        ]);

        $this->getJson('/api/v1/sync/status', $this->headers($this->deviceUuid))
            ->assertOk()
            ->assertJsonPath('data.deviceEnabled', false);
    }

    public function test_status_unknown_device_uuid_reports_enabled_false(): void
    {
        $this->getJson('/api/v1/sync/status', $this->headers('550e8400-e29b-41d4-a716-4466554400ff'))
            ->assertOk()
            ->assertJsonPath('data.deviceEnabled', false);
    }

    public function test_status_rejects_invalid_device_uuid_header(): void
    {
        $this->getJson('/api/v1/sync/status', [
            'X-Store-Id' => (string) $this->store->id,
            'X-Device-Uuid' => 'not-a-uuid',
        ])->assertStatus(422);
    }

    public function test_status_requires_auth_and_store(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/sync/status', ['X-Store-Id' => (string) $this->store->id])
            ->assertUnauthorized();

        Sanctum::actingAs($this->cashier, ['staff']);
        $this->getJson('/api/v1/sync/status')
            ->assertForbidden();
    }

    public function test_list_pending_oversell_exceptions_and_approve(): void
    {
        $exception = $this->seedSaleWithPendingException('550e8400-e29b-41d4-a716-446655440002');
        $saleUuid = $exception->sale->uuid;

        $this->getJson('/api/v1/sync/oversell-exceptions', $this->headers())
            ->assertForbidden();

        $manager = User::factory()->inventoryManager()->create();
        $manager->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($manager, ['staff']);

        $this->getJson('/api/v1/sync/oversell-exceptions', $this->headers())
            ->assertOk()
            ->assertJsonPath('data.0.id', $exception->id)
            ->assertJsonPath('data.0.saleUuid', $saleUuid)
            ->assertJsonPath('data.0.qtyDeficit', '1.0000')
            ->assertJsonPath('meta.total', 1);

        $this->postJson("/api/v1/sync/oversell-exceptions/{$exception->id}/approve", [], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->getJson('/api/v1/sync/oversell-exceptions', $this->headers())
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }
}
