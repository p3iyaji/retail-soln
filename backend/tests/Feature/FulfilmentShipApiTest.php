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

class FulfilmentShipApiTest extends TestCase
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
            'name' => 'Ship Store',
            'is_active' => true,
        ]);
        $this->otherStore = Store::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Other Store',
            'is_active' => true,
        ]);

        $category = Category::query()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Ship Cat',
            'slug' => 'ship-cat',
            'is_active' => true,
        ]);

        $product = Product::factory()->create([
            'organization_id' => $this->organization->id,
            'category_id' => $category->id,
            'name' => 'Shippable Widget',
            'sku' => 'SKU-SHIP-1',
            'selling_price' => '1500.0000',
            'is_active' => true,
        ]);

        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->organization->id,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'SKU-SHIP-1-D',
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

        $this->account = $this->registerVerifiedPortalAccount('ship@example.com');

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

    public function test_ship_processing_home_delivery_sets_out_for_delivery_and_stores_shipment(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placePayAndPack('ship-ok', qty: 2, mode: 'home_delivery');

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $this->getJson('/api/v1/fulfilment/orders/'.$orderId, $this->storeHeaders())
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::PROCESSING)
            ->assertJsonPath('data.shippable', true)
            ->assertJsonPath('data.deliverable', false);

        $movementCountBefore = InventoryMovement::query()->count();
        $bucketsBefore = $this->balanceBuckets();

        $shipped = $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/ship',
            [
                'carrierName' => 'Swift Couriers',
                'trackingNumber' => 'TRK-100',
            ],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'ship-1']),
        )
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::OUT_FOR_DELIVERY)
            ->assertJsonPath('data.shippable', false)
            ->assertJsonPath('data.deliverable', true)
            ->assertJsonPath('data.shipment.carrierName', 'Swift Couriers')
            ->assertJsonPath('data.shipment.trackingNumber', 'TRK-100');

        $this->assertNotEmpty($shipped->json('data.shipment.shippedAt'));
        $this->assertDatabaseHas('portal_shipments', [
            'portal_order_id' => $orderId,
            'carrier_name' => 'Swift Couriers',
            'tracking_number' => 'TRK-100',
            'ship_idempotency_key' => 'ship-1',
        ]);
        $this->assertSame(1, PortalShipment::query()->where('portal_order_id', $orderId)->count());
        $this->assertSame($movementCountBefore, InventoryMovement::query()->count());
        $this->assertSame($bucketsBefore, $this->balanceBuckets());

        Sanctum::actingAs($this->account, ['portal']);
        $this->flushHeaders();
        $this->getJson('/api/v1/portal/orders/'.$orderId)
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::OUT_FOR_DELIVERY)
            ->assertJsonPath('data.timeline.3.key', PortalOrderStatuses::OUT_FOR_DELIVERY)
            ->assertJsonPath('data.timeline.3.state', 'current');
    }

    public function test_deliver_out_for_delivery_sets_delivered_and_excludes_from_list(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placePayAndPack('deliver-ok', qty: 1, mode: 'home_delivery');

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/ship',
            ['carrierName' => 'Local'],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'ship-d1']),
        )->assertOk();

        $movementCountBefore = InventoryMovement::query()->count();

        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/deliver',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'deliver-1']),
        )
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::DELIVERED)
            ->assertJsonPath('data.deliverable', false);

        $this->assertNotNull(PortalShipment::query()->where('portal_order_id', $orderId)->first()?->delivered_at);
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

    public function test_ship_and_deliver_idempotent_replay_and_key_conflict(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placePayAndPack('ship-idem', qty: 1, mode: 'home_delivery');

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $shipHeaders = array_merge($this->storeHeaders(), ['Idempotency-Key' => 'ship-replay']);

        $this->postJson('/api/v1/fulfilment/orders/'.$orderId.'/ship', [
            'carrierName' => 'A',
        ], $shipHeaders)
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::OUT_FOR_DELIVERY);

        $this->postJson('/api/v1/fulfilment/orders/'.$orderId.'/ship', [
            'carrierName' => 'B',
        ], $shipHeaders)
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::OUT_FOR_DELIVERY)
            ->assertJsonPath('data.shipment.carrierName', 'A');

        $this->assertSame(1, PortalShipment::query()->where('portal_order_id', $orderId)->count());

        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/ship',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'ship-other']),
        )
            ->assertStatus(422)
            ->assertJsonPath('message', 'Idempotency-Key conflicts with a previous ship for this order.');

        $deliverHeaders = array_merge($this->storeHeaders(), ['Idempotency-Key' => 'deliver-replay']);
        $this->postJson('/api/v1/fulfilment/orders/'.$orderId.'/deliver', [], $deliverHeaders)
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::DELIVERED);

        $this->postJson('/api/v1/fulfilment/orders/'.$orderId.'/deliver', [], $deliverHeaders)
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::DELIVERED);

        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/deliver',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'deliver-other']),
        )
            ->assertStatus(422)
            ->assertJsonPath('message', 'Idempotency-Key conflicts with a previous deliver for this order.');
    }

    public function test_ship_rejects_pickup_and_click_collect(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $pickupId = $this->placePayAndPack('ship-pickup', qty: 1, mode: 'store_pickup');
        $ccId = $this->placePayAndPack('ship-cc', qty: 1, mode: 'click_collect');

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();

        $this->postJson(
            '/api/v1/fulfilment/orders/'.$pickupId.'/ship',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'no-pickup']),
        )
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only home_delivery orders can be shipped.');

        $this->postJson(
            '/api/v1/fulfilment/orders/'.$ccId.'/ship',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'no-cc']),
        )
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only home_delivery orders can be shipped.');

        $this->assertSame(0, PortalShipment::query()->count());
    }

    public function test_ship_rejects_wrong_store_no_perm_and_portal_token(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placePayAndPack('ship-scope', qty: 1, mode: 'home_delivery');

        Sanctum::actingAs($this->account, ['portal']);
        $this->flushHeaders();
        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/ship',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'portal-ship']),
        )->assertUnauthorized();

        $cashier = User::factory()->cashier()->create();
        $cashier->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($cashier, ['staff']);
        $this->flushHeaders();
        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/ship',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'no-ship-perm']),
        )->assertForbidden();

        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/deliver',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'no-deliver-perm']),
        )->assertForbidden();

        $otherManager = User::factory()->storeManager()->create();
        $otherManager->stores()->attach($this->otherStore->id, ['is_default' => true]);
        Sanctum::actingAs($otherManager, ['staff']);
        $this->flushHeaders();
        $this->postJson('/api/v1/fulfilment/orders/'.$orderId.'/ship', [], [
            'X-Store-Id' => (string) $this->otherStore->id,
            'Idempotency-Key' => 'wrong-store',
        ])->assertNotFound();

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $this->postJson('/api/v1/fulfilment/orders/'.$orderId.'/ship', [], [
            'X-Store-Id' => (string) $this->otherStore->id,
            'Idempotency-Key' => 'no-access',
        ])->assertForbidden();
    }

    public function test_deliver_rejects_when_not_out_for_delivery(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placePayAndPack('deliver-early', qty: 1, mode: 'home_delivery');

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/deliver',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'too-early']),
        )
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only out_for_delivery orders can be marked delivered.');
    }

    public function test_ship_rejects_confirmed_unpacked_home_delivery(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placeOrder('ship-confirmed', 1, 'home_delivery');
        $this->postJson('/api/v1/portal/orders/'.$orderId.'/confirm-payment')->assertOk();

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/ship',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'too-early-ship']),
        )
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only packed (processing) home_delivery orders can be shipped.');
    }

    public function test_deliver_requires_idempotency_key(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placePayAndPack('deliver-key', qty: 1, mode: 'home_delivery');

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/ship',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'ship-for-deliver-key']),
        )->assertOk();

        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/deliver',
            [],
            $this->storeHeaders(),
        )
            ->assertStatus(422)
            ->assertJsonPath('message', 'Idempotency-Key header is required.');
    }

    public function test_deliver_rejects_pickup_and_click_collect_even_if_ofd(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $pickupId = $this->placePayAndPack('deliver-pickup', qty: 1, mode: 'store_pickup');
        $ccId = $this->placePayAndPack('deliver-cc', qty: 1, mode: 'click_collect');

        PortalOrder::query()->whereKey($pickupId)->update([
            'status' => PortalOrderStatuses::OUT_FOR_DELIVERY,
        ]);
        PortalOrder::query()->whereKey($ccId)->update([
            'status' => PortalOrderStatuses::OUT_FOR_DELIVERY,
        ]);

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();

        $this->postJson(
            '/api/v1/fulfilment/orders/'.$pickupId.'/deliver',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'deliver-pickup']),
        )
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only home_delivery orders can be marked delivered.');

        $this->postJson(
            '/api/v1/fulfilment/orders/'.$ccId.'/deliver',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'deliver-cc']),
        )
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only home_delivery orders can be marked delivered.');
    }

    public function test_ship_rejects_ofd_without_stored_idempotency_key(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placePayAndPack('ship-no-key', qty: 1, mode: 'home_delivery');

        PortalOrder::query()->whereKey($orderId)->update([
            'status' => PortalOrderStatuses::OUT_FOR_DELIVERY,
        ]);
        PortalShipment::query()->create([
            'organization_id' => $this->organization->id,
            'portal_order_id' => $orderId,
            'carrier_name' => 'Ghost',
            'shipped_at' => now(),
            'ship_idempotency_key' => null,
        ]);

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/ship',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'late-key']),
        )
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Order was already shipped; provide the original Idempotency-Key to replay.',
            );
    }

    public function test_list_includes_out_for_delivery_and_requires_idempotency_key(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placePayAndPack('list-ofd', qty: 1, mode: 'home_delivery');

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/ship',
            [],
            $this->storeHeaders(),
        )
            ->assertStatus(422)
            ->assertJsonPath('message', 'Idempotency-Key header is required.');

        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/ship',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'list-ship']),
        )->assertOk();

        $ids = collect(
            $this->getJson('/api/v1/fulfilment/orders', $this->storeHeaders())
                ->assertOk()
                ->json('data'),
        )->pluck('id')->all();

        $this->assertContains($orderId, $ids);
        $this->assertSame(
            PortalOrderStatuses::OUT_FOR_DELIVERY,
            PortalOrder::query()->findOrFail($orderId)->status,
        );
    }

    public function test_delivery_staff_has_fulfilment_ship(): void
    {
        $delivery = User::factory()->create(['role' => 'delivery_staff']);
        $this->assertTrue($delivery->hasPermission('fulfilment.ship'));
        $this->assertTrue($delivery->hasPermission('fulfilment.pick'));
    }

    /**
     * @return array<string, string>
     */
    private function storeHeaders(): array
    {
        return ['X-Store-Id' => (string) $this->store->id];
    }

    private function placePayAndPack(string $idempotencyKey, int $qty = 1, string $mode = 'home_delivery'): int
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

    private function placeOrder(string $idempotencyKey, int $qty = 1, string $mode = 'home_delivery'): int
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
            'name' => 'Ship Customer',
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
