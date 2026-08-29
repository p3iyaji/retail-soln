<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
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
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;
use Modules\Products\Models\Category;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Tests\TestCase;

class PortalStockReservationApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Store $store;

    private ProductVariant $variant;

    private CustomerAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'name' => 'Portal Reservation Org',
        ]);
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
            'name' => 'Reservation Store',
            'is_active' => true,
        ]);

        $category = Category::query()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Reservation Cat',
            'slug' => 'reservation-cat',
            'is_active' => true,
        ]);

        $product = Product::factory()->create([
            'organization_id' => $this->organization->id,
            'category_id' => $category->id,
            'name' => 'Holdable Widget',
            'sku' => 'SKU-RSV-1',
            'selling_price' => '1500.0000',
            'is_active' => true,
        ]);

        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->organization->id,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'SKU-RSV-1-D',
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
            quantity: '10.0000',
            unitCost: '500.0000',
        ));

        $this->account = $this->registerVerifiedPortalAccount('reserve@example.com');
    }

    public function test_place_with_stock_reserves_available_to_reserved(): void
    {
        Sanctum::actingAs($this->account, ['portal']);

        $orderId = $this->placeOrder('place-ok', 2);

        $this->assertSame([
            'available' => '8.0000',
            'reserved' => '2.0000',
        ], $this->balanceBuckets());

        $this->assertDatabaseHas('portal_orders', [
            'id' => $orderId,
            'status' => PortalOrderStatuses::PENDING,
            'payment_status' => 'awaiting_payment',
        ]);

        $this->assertSame(2, InventoryMovement::query()
            ->where('type', MovementType::Reserve->value)
            ->where('reference_type', 'portal_order')
            ->where('reference_id', $orderId)
            ->count());
    }

    public function test_place_oversell_creates_no_order_and_unchanged_balances(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $before = $this->balanceBuckets();

        $this->postJson('/api/v1/portal/cart/items', [
            'variantId' => $this->variant->id,
            'qty' => 15,
        ])->assertCreated();

        $this->withHeader('Idempotency-Key', 'place-oversell')
            ->postJson('/api/v1/portal/checkout/place', [
                'fulfilmentMode' => 'store_pickup',
                'storeId' => $this->store->id,
                'paymentProvider' => 'manual',
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('portal_orders', 0);
        $this->assertSame($before, $this->balanceBuckets());
        $this->assertSame(0, InventoryMovement::query()
            ->where('type', MovementType::Reserve->value)
            ->count());
    }

    public function test_idempotent_place_replay_does_not_double_reserve(): void
    {
        Sanctum::actingAs($this->account, ['portal']);

        $body = [
            'fulfilmentMode' => 'store_pickup',
            'storeId' => $this->store->id,
            'paymentProvider' => 'manual',
        ];

        $this->postJson('/api/v1/portal/cart/items', [
            'variantId' => $this->variant->id,
            'qty' => 1,
        ])->assertCreated();

        $first = $this->withHeader('Idempotency-Key', 'place-idem')
            ->postJson('/api/v1/portal/checkout/place', $body)
            ->assertCreated();

        $second = $this->withHeader('Idempotency-Key', 'place-idem')
            ->postJson('/api/v1/portal/checkout/place', $body)
            ->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame([
            'available' => '9.0000',
            'reserved' => '1.0000',
        ], $this->balanceBuckets());
        $this->assertSame(2, InventoryMovement::query()
            ->where('type', MovementType::Reserve->value)
            ->where('reference_id', $first->json('data.id'))
            ->count());
    }

    public function test_cancel_awaiting_payment_releases_reserved(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placeOrder('cancel-unpaid', 3);

        $this->postJson('/api/v1/portal/orders/'.$orderId.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::CANCELLED)
            ->assertJsonPath('data.cancellable', false);

        $this->assertSame([
            'available' => '10.0000',
            'reserved' => '0.0000',
        ], $this->balanceBuckets());

        // Idempotent cancel
        $this->postJson('/api/v1/portal/orders/'.$orderId.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::CANCELLED);

        $this->assertSame([
            'available' => '10.0000',
            'reserved' => '0.0000',
        ], $this->balanceBuckets());
        $this->assertSame(2, InventoryMovement::query()
            ->where('type', MovementType::Release->value)
            ->where('reference_id', $orderId)
            ->count());
    }

    public function test_unpaid_ttl_expire_releases_reserved_and_leaves_paid_alone(): void
    {
        Sanctum::actingAs($this->account, ['portal']);

        $expireId = $this->placeOrder('ttl-expire', 2);
        $paidId = $this->placeOrder('ttl-paid', 1);
        $this->postJson('/api/v1/portal/orders/'.$paidId.'/confirm-payment')->assertOk();

        PortalOrder::query()->where('id', $expireId)->update([
            'created_at' => Carbon::now()->subMinutes(90),
        ]);

        $this->artisan('portal:expire-unpaid-reservations')
            ->assertSuccessful()
            ->expectsOutputToContain('Expired 1 unpaid');

        $this->assertDatabaseHas('portal_orders', [
            'id' => $expireId,
            'status' => PortalOrderStatuses::CANCELLED,
            'payment_status' => 'awaiting_payment',
        ]);
        $this->assertDatabaseHas('portal_orders', [
            'id' => $paidId,
            'status' => PortalOrderStatuses::CONFIRMED,
            'payment_status' => 'paid',
        ]);

        // expire released 2; paid still holds 1 reserved
        $this->assertSame([
            'available' => '9.0000',
            'reserved' => '1.0000',
        ], $this->balanceBuckets());
    }

    public function test_pay_success_keeps_reserved_unchanged(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placeOrder('pay-keeps', 2);

        $this->assertSame([
            'available' => '8.0000',
            'reserved' => '2.0000',
        ], $this->balanceBuckets());

        $this->postJson('/api/v1/portal/orders/'.$orderId.'/confirm-payment')
            ->assertOk()
            ->assertJsonPath('data.paymentStatus', 'paid')
            ->assertJsonPath('data.status', PortalOrderStatuses::CONFIRMED);

        $this->assertSame([
            'available' => '8.0000',
            'reserved' => '2.0000',
        ], $this->balanceBuckets());

        $this->assertSame(0, InventoryMovement::query()
            ->where('type', MovementType::Release->value)
            ->where('reference_id', $orderId)
            ->count());
        $this->assertSame(0, InventoryMovement::query()
            ->where('type', MovementType::Sale->value)
            ->where('reference_id', $orderId)
            ->count());
        $this->assertSame(0, InventoryMovement::query()
            ->where('bucket', BalanceBucket::Reserved->value)
            ->where('type', MovementType::Sale->value)
            ->count());
    }

    public function test_cancel_rejects_paid_order(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placeOrder('cancel-paid', 1);
        $this->postJson('/api/v1/portal/orders/'.$orderId.'/confirm-payment')->assertOk();

        $this->postJson('/api/v1/portal/orders/'.$orderId.'/cancel')
            ->assertStatus(422);

        $this->assertSame([
            'available' => '9.0000',
            'reserved' => '1.0000',
        ], $this->balanceBuckets());
    }

    public function test_failed_payment_can_cancel_and_release(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placeOrder('cancel-failed', 2);

        PortalOrder::query()->where('id', $orderId)->update([
            'payment_status' => 'failed',
        ]);

        $this->postJson('/api/v1/portal/orders/'.$orderId.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::CANCELLED);

        $this->assertSame([
            'available' => '10.0000',
            'reserved' => '0.0000',
        ], $this->balanceBuckets());
    }

    private function placeOrder(string $idempotencyKey, int $qty = 1): int
    {
        $this->postJson('/api/v1/portal/cart/items', [
            'variantId' => $this->variant->id,
            'qty' => $qty,
        ])->assertCreated();

        return (int) $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/portal/checkout/place', [
                'fulfilmentMode' => 'store_pickup',
                'storeId' => $this->store->id,
                'paymentProvider' => 'manual',
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
            ->first();

        return [
            'available' => $balance ? (string) $balance->available : '0.0000',
            'reserved' => $balance ? (string) $balance->reserved : '0.0000',
        ];
    }

    private function registerVerifiedPortalAccount(string $email): CustomerAccount
    {
        $register = $this->postJson('/api/v1/portal/auth/register', [
            'name' => 'Reserve User',
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
