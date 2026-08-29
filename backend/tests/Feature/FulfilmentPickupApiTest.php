<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\CustomerPortal\Models\CustomerAccount;
use Modules\CustomerPortal\Models\PortalOrder;
use Modules\CustomerPortal\Models\PortalShipment;
use Modules\CustomerPortal\Support\PortalOrderStatuses;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Models\InventoryBalance;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\Category;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Tests\TestCase;

class FulfilmentPickupApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Store $store;

    private Store $otherStore;

    private ProductVariant $variant;

    private CustomerAccount $account;

    private User $manager;

    private int $addressId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionMatrixSeeder::class);

        $this->organization = app(OrganizationService::class)->current();
        config([
            'customer_portal.organization_id' => $this->organization->id,
            'customer_portal.delivery_fee' => '0.0000',
            'customer_portal.reservation_ttl_minutes' => 60,
            'payments.portal_frontend_url' => 'http://localhost:5175',
            'payments.allowed_redirect_origins' => 'http://localhost:5175',
            'app.url' => 'http://localhost',
        ]);

        $this->store = Store::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Pickup Store',
            'is_active' => true,
        ]);
        $this->otherStore = Store::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Other Store',
            'is_active' => true,
        ]);

        $category = Category::query()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Pickup Cat',
            'slug' => 'pickup-cat',
            'is_active' => true,
        ]);

        $product = Product::factory()->create([
            'organization_id' => $this->organization->id,
            'category_id' => $category->id,
            'name' => 'Pickup Widget',
            'sku' => 'SKU-PICKUP-1',
            'selling_price' => '1500.0000',
            'is_active' => true,
        ]);

        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->organization->id,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'SKU-PICKUP-1-D',
            'price' => '1500.0000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        app(InventoryLedgerService::class)->post(new PostMovementData(
            organizationId: $this->organization->id,
            locationType: LocationType::Store,
            locationId: $this->store->id,
            productVariantId: $this->variant->id,
            type: MovementType::Purchase,
            quantity: '30.0000',
            unitCost: '500.0000',
        ));

        $this->account = $this->registerVerifiedPortalAccount('pickup@example.com');

        Sanctum::actingAs($this->account, ['portal']);
        $this->flushHeaders();
        $this->addressId = (int) $this->postJson('/api/v1/portal/account/addresses', [
            'line1' => '12 Delivery Rd',
            'city' => 'Lagos',
            'type' => 'shipping',
            'isDefault' => true,
        ])->assertCreated()->json('data.id');

        $this->manager = User::factory()->storeManager()->create();
        $this->manager->stores()->attach($this->store->id, ['is_default' => true]);
    }

    public function test_ready_for_pickup_store_pickup_sets_status_and_portal_timeline(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placePayAndPack('ready-ok', qty: 2, mode: 'store_pickup');

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $this->getJson('/api/v1/fulfilment/orders/'.$orderId, $this->storeHeaders())
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::PROCESSING)
            ->assertJsonPath('data.readyForPickup', true)
            ->assertJsonPath('data.collectable', false)
            ->assertJsonPath('data.shippable', false);

        $movementCountBefore = InventoryMovement::query()->count();
        $bucketsBefore = $this->balanceBuckets();

        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/ready',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'ready-1']),
        )
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::READY_FOR_PICKUP)
            ->assertJsonPath('data.statusLabel', 'Ready for Pickup')
            ->assertJsonPath('data.readyForPickup', false)
            ->assertJsonPath('data.collectable', true)
            ->assertJsonPath('data.shippable', false)
            ->assertJsonPath('data.deliverable', false);

        $this->assertDatabaseHas('portal_orders', [
            'id' => $orderId,
            'status' => PortalOrderStatuses::READY_FOR_PICKUP,
            'ready_idempotency_key' => 'ready-1',
        ]);
        $this->assertSame(0, PortalShipment::query()->count());
        $this->assertSame($movementCountBefore, InventoryMovement::query()->count());
        $this->assertSame($bucketsBefore, $this->balanceBuckets());

        Sanctum::actingAs($this->account, ['portal']);
        $this->flushHeaders();
        $this->getJson('/api/v1/portal/orders/'.$orderId)
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::READY_FOR_PICKUP)
            ->assertJsonPath('data.timeline.3.key', PortalOrderStatuses::READY_FOR_PICKUP)
            ->assertJsonPath('data.timeline.3.state', 'current');
    }

    public function test_ready_for_pickup_click_collect_same_handoff(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placePayAndPack('ready-cc', qty: 1, mode: 'click_collect');

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/ready',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'ready-cc-1']),
        )
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::READY_FOR_PICKUP)
            ->assertJsonPath('data.fulfilmentMode', 'click_collect');
    }

    public function test_collect_ready_for_pickup_sets_delivered_and_excludes_from_list(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placePayAndPack('collect-ok', qty: 1, mode: 'store_pickup');

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/ready',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'ready-c1']),
        )->assertOk();

        $movementCountBefore = InventoryMovement::query()->count();

        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/collect',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'collect-1']),
        )
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::DELIVERED)
            ->assertJsonPath('data.statusLabel', 'Collected')
            ->assertJsonPath('data.collectable', false)
            ->assertJsonPath('data.readyForPickup', false);

        $this->assertNotNull(
            PortalOrder::query()->whereKey($orderId)->first()?->collected_at,
        );
        $this->assertDatabaseHas('portal_orders', [
            'id' => $orderId,
            'status' => PortalOrderStatuses::DELIVERED,
            'collect_idempotency_key' => 'collect-1',
        ]);
        $this->assertSame(0, PortalShipment::query()->count());
        $this->assertSame($movementCountBefore, InventoryMovement::query()->count());

        $ids = collect(
            $this->getJson('/api/v1/fulfilment/orders', $this->storeHeaders())
                ->assertOk()
                ->json('data'),
        )->pluck('id')->all();
        $this->assertNotContains($orderId, $ids);

        $this->getJson('/api/v1/fulfilment/orders/'.$orderId, $this->storeHeaders())
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::DELIVERED);

        Sanctum::actingAs($this->account, ['portal']);
        $this->flushHeaders();
        $this->getJson('/api/v1/portal/orders/'.$orderId)
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::DELIVERED)
            ->assertJsonPath('data.timeline.4.key', PortalOrderStatuses::DELIVERED)
            ->assertJsonPath('data.timeline.4.state', 'current');
    }

    public function test_ready_and_collect_idempotent_replay_and_key_conflict(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placePayAndPack('pickup-idem', qty: 1, mode: 'store_pickup');

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $readyHeaders = array_merge($this->storeHeaders(), ['Idempotency-Key' => 'ready-replay']);

        $this->postJson('/api/v1/fulfilment/orders/'.$orderId.'/ready', [], $readyHeaders)
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::READY_FOR_PICKUP);

        $this->postJson('/api/v1/fulfilment/orders/'.$orderId.'/ready', [], $readyHeaders)
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::READY_FOR_PICKUP);

        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/ready',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'ready-other']),
        )
            ->assertStatus(422)
            ->assertJsonPath('message', 'Idempotency-Key conflicts with a previous ready-for-pickup for this order.');

        $collectHeaders = array_merge($this->storeHeaders(), ['Idempotency-Key' => 'collect-replay']);
        $this->postJson('/api/v1/fulfilment/orders/'.$orderId.'/collect', [], $collectHeaders)
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::DELIVERED);

        $this->postJson('/api/v1/fulfilment/orders/'.$orderId.'/collect', [], $collectHeaders)
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::DELIVERED);

        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/collect',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'collect-other']),
        )
            ->assertStatus(422)
            ->assertJsonPath('message', 'Idempotency-Key conflicts with a previous collect for this order.');

        $this->postJson('/api/v1/fulfilment/orders/'.$orderId.'/ready', [], $readyHeaders)
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Only packed (processing) store_pickup or click_collect orders can be marked ready for pickup.',
            );
    }

    public function test_ready_and_collect_reject_unpaid(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placeOrder('unpaid-ready', 1, 'store_pickup');

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/ready',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'unpaid-ready']),
        )
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only paid orders can be marked ready for pickup.');

        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/collect',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'unpaid-collect']),
        )
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only paid orders can be marked collected.');
    }

    public function test_ready_rejects_cancelled_pickup(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placePayAndPack('cancelled-ready', qty: 1, mode: 'store_pickup');

        PortalOrder::query()->whereKey($orderId)->update([
            'status' => PortalOrderStatuses::CANCELLED,
        ]);

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/ready',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'cancelled-ready']),
        )
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cancelled or terminal orders cannot be marked ready for pickup.');
    }

    public function test_ship_rejects_pickup_at_ready_for_pickup(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placePayAndPack('ship-at-rfp', qty: 1, mode: 'store_pickup');

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/ready',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'rfp-then-ship']),
        )
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::READY_FOR_PICKUP)
            ->assertJsonPath('data.readyForPickup', false)
            ->assertJsonPath('data.collectable', true)
            ->assertJsonPath('data.shippable', false);

        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/ship',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'ship-rfp']),
        )
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only home_delivery orders can be shipped.');

        $this->assertSame(0, PortalShipment::query()->count());
    }

    public function test_ready_and_collect_reject_home_delivery(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placePayAndPack('hd-reject', qty: 1, mode: 'home_delivery');

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();

        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/ready',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'no-hd-ready']),
        )
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only store_pickup or click_collect orders can be marked ready for pickup.');

        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/collect',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'no-hd-collect']),
        )
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only store_pickup or click_collect orders can be marked collected.');
    }

    public function test_ready_collect_reject_wrong_store_no_perm_and_portal_token(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placePayAndPack('pickup-scope', qty: 1, mode: 'store_pickup');

        Sanctum::actingAs($this->account, ['portal']);
        $this->flushHeaders();
        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/ready',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'portal-ready']),
        )->assertUnauthorized();

        $cashier = User::factory()->cashier()->create();
        $cashier->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($cashier, ['staff']);
        $this->flushHeaders();
        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/ready',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'no-pickup-perm']),
        )->assertForbidden();

        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/collect',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'no-collect-perm']),
        )->assertForbidden();

        $otherManager = User::factory()->storeManager()->create();
        $otherManager->stores()->attach($this->otherStore->id, ['is_default' => true]);
        Sanctum::actingAs($otherManager, ['staff']);
        $this->flushHeaders();
        $this->postJson('/api/v1/fulfilment/orders/'.$orderId.'/ready', [], [
            'X-Store-Id' => (string) $this->otherStore->id,
            'Idempotency-Key' => 'wrong-store',
        ])->assertNotFound();

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $this->postJson('/api/v1/fulfilment/orders/'.$orderId.'/ready', [], [
            'X-Store-Id' => (string) $this->otherStore->id,
            'Idempotency-Key' => 'no-access',
        ])->assertForbidden();
    }

    public function test_collect_rejects_when_not_ready_for_pickup(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placePayAndPack('collect-early', qty: 1, mode: 'store_pickup');

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/collect',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'too-early']),
        )
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only ready_for_pickup orders can be marked collected.');
    }

    public function test_ready_rejects_confirmed_unpacked_pickup(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placeOrder('ready-confirmed', 1, 'store_pickup');
        $this->postJson('/api/v1/portal/orders/'.$orderId.'/confirm-payment')->assertOk();

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/ready',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'too-early-ready']),
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Only packed (processing) store_pickup or click_collect orders can be marked ready for pickup.',
            );
    }

    public function test_ready_and_collect_require_idempotency_key(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placePayAndPack('key-req', qty: 1, mode: 'store_pickup');

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/ready',
            [],
            $this->storeHeaders(),
        )
            ->assertStatus(422)
            ->assertJsonPath('message', 'Idempotency-Key header is required.');

        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/ready',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'ready-for-collect-key']),
        )->assertOk();

        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/collect',
            [],
            $this->storeHeaders(),
        )
            ->assertStatus(422)
            ->assertJsonPath('message', 'Idempotency-Key header is required.');
    }

    public function test_list_includes_ready_for_pickup(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placePayAndPack('list-rfp', qty: 1, mode: 'click_collect');

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/ready',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'list-ready']),
        )->assertOk();

        $ids = collect(
            $this->getJson('/api/v1/fulfilment/orders', $this->storeHeaders())
                ->assertOk()
                ->json('data'),
        )->pluck('id')->all();

        $this->assertContains($orderId, $ids);
        $this->assertSame(
            PortalOrderStatuses::READY_FOR_PICKUP,
            PortalOrder::query()->findOrFail($orderId)->status,
        );
    }

    public function test_list_show_accessible_with_pickup_permission(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placePayAndPack('pickup-only-list', qty: 1, mode: 'store_pickup');

        $staff = User::factory()->create(['role' => 'delivery_staff']);
        $staff->stores()->attach($this->store->id, ['is_default' => true]);
        $this->assertTrue($staff->hasPermission('fulfilment.pickup'));

        Sanctum::actingAs($staff, ['staff']);
        $this->flushHeaders();
        $this->getJson('/api/v1/fulfilment/orders', $this->storeHeaders())->assertOk();
        $this->getJson('/api/v1/fulfilment/orders/'.$orderId, $this->storeHeaders())->assertOk();
    }

    public function test_delivery_staff_has_fulfilment_pickup(): void
    {
        $delivery = User::factory()->create(['role' => 'delivery_staff']);
        $this->assertTrue($delivery->hasPermission('fulfilment.pickup'));
        $this->assertTrue($delivery->hasPermission('fulfilment.pick'));
        $this->assertTrue($delivery->hasPermission('fulfilment.ship'));
    }

    public function test_ready_rejects_rfp_without_stored_idempotency_key(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placePayAndPack('ready-no-key', qty: 1, mode: 'store_pickup');

        PortalOrder::query()->whereKey($orderId)->update([
            'status' => PortalOrderStatuses::READY_FOR_PICKUP,
            'ready_idempotency_key' => null,
        ]);

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/ready',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'late-key']),
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Order was already marked ready for pickup; provide the original Idempotency-Key to replay.',
            );
    }

    /**
     * @return array<string, string>
     */
    private function storeHeaders(): array
    {
        return ['X-Store-Id' => (string) $this->store->id];
    }

    private function placePayAndPack(string $idempotencyKey, int $qty = 1, string $mode = 'store_pickup'): int
    {
        $orderId = $this->placeOrder($idempotencyKey, $qty, $mode);
        $this->postJson('/api/v1/portal/orders/'.$orderId.'/confirm-payment')->assertOk();

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/pack',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'pack-'.$idempotencyKey]),
        )
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::PROCESSING);

        return $orderId;
    }

    private function placeOrder(string $idempotencyKey, int $qty = 1, string $mode = 'store_pickup'): int
    {
        Sanctum::actingAs($this->account, ['portal']);
        $this->flushHeaders();
        $this->postJson('/api/v1/portal/cart/items', [
            'variantId' => $this->variant->id,
            'qty' => $qty,
        ])->assertCreated();

        $payload = [
            'fulfilmentMode' => $mode,
            'storeId' => $this->store->id,
            'paymentProvider' => 'manual',
        ];
        if ($mode === 'home_delivery') {
            $payload['addressId'] = $this->addressId;
        }

        return (int) $this->postJson('/api/v1/portal/checkout/place', $payload, [
            'Idempotency-Key' => $idempotencyKey,
        ])->assertCreated()
            ->json('data.id');
    }

    /**
     * @return array{available: string, reserved: string}
     */
    private function balanceBuckets(): array
    {
        $balance = InventoryBalance::query()
            ->where('organization_id', $this->organization->id)
            ->where('location_type', LocationType::Store->value)
            ->where('location_id', $this->store->id)
            ->where('product_variant_id', $this->variant->id)
            ->firstOrFail();

        return [
            'available' => (string) $balance->available,
            'reserved' => (string) $balance->reserved,
        ];
    }

    private function registerVerifiedPortalAccount(string $email): CustomerAccount
    {
        $register = $this->postJson('/api/v1/portal/auth/register', [
            'name' => 'Pickup Customer',
            'email' => $email,
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertCreated();

        $this->postJson('/api/v1/portal/auth/verify', [
            'email' => $email,
            'token' => $register->json('verificationToken'),
        ])->assertOk();

        return CustomerAccount::query()->where('email', $email)->firstOrFail();
    }
}
