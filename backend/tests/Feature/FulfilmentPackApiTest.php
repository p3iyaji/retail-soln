<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\CustomerPortal\Models\CustomerAccount;
use Modules\CustomerPortal\Models\PortalOrder;
use Modules\CustomerPortal\Support\PortalOrderStatuses;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\BalanceBucket;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Models\InventoryBalance;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Inventory\Services\StockReservationService;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\Category;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Tests\TestCase;

class FulfilmentPackApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Store $store;

    private Store $otherStore;

    private ProductVariant $variant;

    private CustomerAccount $account;

    private User $manager;

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
            'name' => 'Pack Store',
            'is_active' => true,
        ]);
        $this->otherStore = Store::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Other Store',
            'is_active' => true,
        ]);

        $category = Category::query()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Pack Cat',
            'slug' => 'pack-cat',
            'is_active' => true,
        ]);

        $product = Product::factory()->create([
            'organization_id' => $this->organization->id,
            'category_id' => $category->id,
            'name' => 'Packable Widget',
            'sku' => 'SKU-PACK-1',
            'selling_price' => '1500.0000',
            'is_active' => true,
        ]);

        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->organization->id,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'SKU-PACK-1-D',
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
            quantity: '20.0000',
            unitCost: '500.0000',
        ));

        $this->account = $this->registerVerifiedPortalAccount('pack@example.com');

        $this->manager = User::factory()->storeManager()->create();
        $this->manager->stores()->attach($this->store->id, ['is_default' => true]);
    }

    public function test_list_store_orders_returns_paid_confirmed_and_processing(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $confirmedId = $this->placeAndPay('list-confirmed', 2);
        $processingId = $this->placeAndPay('list-processing', 1);
        PortalOrder::query()->whereKey($processingId)->update([
            'status' => PortalOrderStatuses::PROCESSING,
            'packed_at' => now(),
        ]);

        $pendingId = $this->placeOrder('list-pending', 1);

        Sanctum::actingAs($this->manager, ['staff']);
        $res = $this->getJson('/api/v1/fulfilment/orders', $this->storeHeaders())
            ->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($confirmedId, $ids);
        $this->assertContains($processingId, $ids);
        $this->assertNotContains($pendingId, $ids);
    }

    public function test_pack_complete_consumes_reserved_and_sets_processing(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placeAndPay('pack-ok', 3);

        $this->assertSame([
            'available' => '17.0000',
            'reserved' => '3.0000',
        ], $this->balanceBuckets());

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $packed = $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/pack',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'pack-1']),
        )
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::PROCESSING)
            ->assertJsonPath('data.packable', false);

        $this->assertNotEmpty($packed->json('data.packedAt'));
        $this->assertNotNull(PortalOrder::query()->findOrFail($orderId)->packed_at);

        $this->assertSame([
            'available' => '17.0000',
            'reserved' => '0.0000',
        ], $this->balanceBuckets());

        $this->assertSame(1, InventoryMovement::query()
            ->where('type', MovementType::Sale->value)
            ->where('bucket', BalanceBucket::Reserved->value)
            ->where('reference_type', 'portal_order')
            ->where('reference_id', $orderId)
            ->where('quantity', '-3.0000')
            ->count());

        $this->assertDatabaseHas('portal_orders', [
            'id' => $orderId,
            'status' => PortalOrderStatuses::PROCESSING,
            'pack_idempotency_key' => 'pack-1',
        ]);

        Sanctum::actingAs($this->account, ['portal']);
        $this->flushHeaders();
        $this->getJson('/api/v1/portal/orders/'.$orderId)
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::PROCESSING)
            ->assertJsonPath('data.timeline.2.key', PortalOrderStatuses::PROCESSING)
            ->assertJsonPath('data.timeline.2.state', 'current')
            ->assertJsonPath('data.timeline.2.detail', 'Picked & packed');
    }

    public function test_pack_replay_same_idempotency_key_does_not_double_consume(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placeAndPay('pack-idem', 2);

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $headers = array_merge($this->storeHeaders(), ['Idempotency-Key' => 'pack-replay']);

        $this->postJson('/api/v1/fulfilment/orders/'.$orderId.'/pack', [], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::PROCESSING);

        $this->postJson('/api/v1/fulfilment/orders/'.$orderId.'/pack', [], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::PROCESSING);

        $this->assertSame([
            'available' => '18.0000',
            'reserved' => '0.0000',
        ], $this->balanceBuckets());

        $this->assertSame(1, InventoryMovement::query()
            ->where('type', MovementType::Sale->value)
            ->where('bucket', BalanceBucket::Reserved->value)
            ->where('reference_id', $orderId)
            ->count());
    }

    public function test_show_returns_order_and_404_for_wrong_store(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placeAndPay('show-ok', 1);

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $this->getJson('/api/v1/fulfilment/orders/'.$orderId, $this->storeHeaders())
            ->assertOk()
            ->assertJsonPath('data.id', $orderId)
            ->assertJsonPath('data.status', PortalOrderStatuses::CONFIRMED);

        $otherManager = User::factory()->storeManager()->create();
        $otherManager->stores()->attach($this->otherStore->id, ['is_default' => true]);
        Sanctum::actingAs($otherManager, ['staff']);
        $this->flushHeaders();
        $this->getJson('/api/v1/fulfilment/orders/'.$orderId, [
            'X-Store-Id' => (string) $this->otherStore->id,
        ])->assertNotFound();
    }

    public function test_pack_requires_idempotency_key_and_rejects_different_key_after_success(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placeAndPay('pack-keys', 1);

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $this->postJson('/api/v1/fulfilment/orders/'.$orderId.'/pack', [], $this->storeHeaders())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Idempotency-Key header is required.');

        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/pack',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'pack-key-a']),
        )
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::PROCESSING);

        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/pack',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'pack-key-b']),
        )
            ->assertStatus(422)
            ->assertJsonPath('message', 'Idempotency-Key conflicts with a previous pack for this order.');
    }

    public function test_delivery_staff_has_fulfilment_pick(): void
    {
        $delivery = User::factory()->create(['role' => 'delivery_staff']);
        $this->assertTrue($delivery->hasPermission('fulfilment.pick'));
    }

    public function test_pack_rejects_wrong_store_and_missing_permission(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placeAndPay('pack-scope', 1);

        $cashier = User::factory()->cashier()->create();
        $cashier->stores()->attach($this->store->id, ['is_default' => true]);
        Sanctum::actingAs($cashier, ['staff']);
        $this->flushHeaders();
        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/pack',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'no-perm']),
        )->assertForbidden();

        $otherManager = User::factory()->storeManager()->create();
        $otherManager->stores()->attach($this->otherStore->id, ['is_default' => true]);
        Sanctum::actingAs($otherManager, ['staff']);
        $this->flushHeaders();
        $this->postJson('/api/v1/fulfilment/orders/'.$orderId.'/pack', [], [
            'X-Store-Id' => (string) $this->otherStore->id,
            'Idempotency-Key' => 'wrong-store',
        ])->assertNotFound();

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $this->postJson('/api/v1/fulfilment/orders/'.$orderId.'/pack', [], [
            'X-Store-Id' => (string) $this->otherStore->id,
            'Idempotency-Key' => 'no-access',
        ])->assertForbidden();
    }

    public function test_pack_rejects_unpaid_and_cancelled(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $unpaidId = $this->placeOrder('pack-unpaid', 1);
        $cancelledId = $this->placeOrder('pack-cancel', 1);
        $this->flushHeaders();
        $this->postJson('/api/v1/portal/orders/'.$cancelledId.'/cancel')->assertOk();

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $this->postJson(
            '/api/v1/fulfilment/orders/'.$unpaidId.'/pack',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'unpaid']),
        )->assertNotFound();

        $this->postJson(
            '/api/v1/fulfilment/orders/'.$cancelledId.'/pack',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'cancelled']),
        )->assertNotFound();
    }

    public function test_portal_token_rejected_on_fulfilment_apis(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placeAndPay('portal-reject', 1);

        $this->flushHeaders();
        $this->getJson('/api/v1/fulfilment/orders', $this->storeHeaders())
            ->assertUnauthorized();

        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/pack',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'portal-pack']),
        )->assertUnauthorized();
    }

    public function test_pack_insufficient_reserved_returns_422(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placeAndPay('pack-short', 2);

        app(StockReservationService::class)->release([
            'organizationId' => $this->organization->id,
            'locationId' => $this->store->id,
            'lines' => [
                ['productVariantId' => $this->variant->id, 'quantity' => '2.0000'],
            ],
            'referenceType' => 'portal_order',
            'referenceId' => $orderId,
        ]);

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/pack',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'short']),
        )
            ->assertStatus(422)
            ->assertJsonPath('message', 'Insufficient reserved stock to consume.');

        $this->assertDatabaseHas('portal_orders', [
            'id' => $orderId,
            'status' => PortalOrderStatuses::CONFIRMED,
        ]);
    }

    public function test_stock_reservation_consume_is_idempotent(): void
    {
        $reservations = app(StockReservationService::class);
        $payload = [
            'organizationId' => $this->organization->id,
            'locationId' => $this->store->id,
            'lines' => [
                ['productVariantId' => $this->variant->id, 'quantity' => '4.0000'],
            ],
            'referenceType' => 'portal_order',
            'referenceId' => 9001,
        ];

        $reservations->reserve($payload);
        $reservations->consume($payload);
        $reservations->consume($payload);

        $this->assertSame([
            'available' => '16.0000',
            'reserved' => '0.0000',
        ], $this->balanceBuckets());

        $this->assertSame(1, InventoryMovement::query()
            ->where('type', MovementType::Sale->value)
            ->where('bucket', BalanceBucket::Reserved->value)
            ->where('reference_id', 9001)
            ->count());
    }

    /**
     * @return array<string, string>
     */
    private function storeHeaders(): array
    {
        return ['X-Store-Id' => (string) $this->store->id];
    }

    private function placeAndPay(string $idempotencyKey, int $qty = 1): int
    {
        $orderId = $this->placeOrder($idempotencyKey, $qty);
        $this->postJson('/api/v1/portal/orders/'.$orderId.'/confirm-payment')->assertOk();

        return $orderId;
    }

    private function placeOrder(string $idempotencyKey, int $qty = 1): int
    {
        $this->flushHeaders();
        $this->postJson('/api/v1/portal/cart/items', [
            'variantId' => $this->variant->id,
            'qty' => $qty,
        ])->assertCreated();

        return (int) $this->postJson('/api/v1/portal/checkout/place', [
            'fulfilmentMode' => 'store_pickup',
            'storeId' => $this->store->id,
            'paymentProvider' => 'manual',
        ], [
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
            'name' => 'Pack Customer',
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
