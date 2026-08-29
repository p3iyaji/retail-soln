<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Modules\CustomerPortal\Models\CustomerAccount;
use Modules\CustomerPortal\Models\PortalCartItem;
use Modules\CustomerPortal\Models\PortalOrder;
use Modules\Customers\Models\CustomerAddress;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;
use Modules\Products\Models\Category;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Tests\TestCase;

class PortalCartCheckoutApiTest extends TestCase
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
            'name' => 'Portal Checkout Org',
        ]);
        config([
            'customer_portal.organization_id' => $this->organization->id,
            'customer_portal.delivery_fee' => '500.0000',
            'payments.portal_frontend_url' => 'http://localhost:5175',
            'payments.allowed_redirect_origins' => 'http://localhost:5175,http://127.0.0.1:5175',
            'app.url' => 'http://localhost',
        ]);

        $this->store = Store::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Ikeja Store A',
            'is_active' => true,
        ]);

        $category = Category::query()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Beverages',
            'slug' => 'beverages',
            'is_active' => true,
        ]);

        $product = Product::factory()->create([
            'organization_id' => $this->organization->id,
            'category_id' => $category->id,
            'name' => 'Peak Milk 400g',
            'sku' => 'SKU-1042',
            'selling_price' => '1850.0000',
            'is_active' => true,
        ]);

        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->organization->id,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'SKU-1042-D',
            'price' => '1850.0000',
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
            unitCost: '1000.0000',
        ));

        $this->account = $this->registerVerifiedPortalAccount('shopper@example.com');
    }

    public function test_add_to_cart_upserts_line_and_refreshes_totals(): void
    {
        Sanctum::actingAs($this->account, ['portal']);

        $first = $this->postJson('/api/v1/portal/cart/items', [
            'variantId' => $this->variant->id,
            'qty' => 2,
        ])->assertCreated();

        $this->assertCount(1, $first->json('data.lines'));
        $this->assertSame('2.0000', $first->json('data.lines.0.qty'));
        $this->assertNotEmpty($first->json('data.totals.grandTotal'));

        $second = $this->postJson('/api/v1/portal/cart/items', [
            'variantId' => $this->variant->id,
            'qty' => 1,
        ])->assertCreated();

        $this->assertSame('3.0000', $second->json('data.lines.0.qty'));
        $this->assertSame(1, PortalCartItem::query()->count());
    }

    public function test_add_inactive_or_foreign_variant_rejected(): void
    {
        Sanctum::actingAs($this->account, ['portal']);

        $this->postJson('/api/v1/portal/cart/items', [
            'variantId' => 999999,
            'qty' => 1,
        ])->assertNotFound();

        $otherOrg = Organization::factory()->create();
        $foreignProduct = Product::factory()->create([
            'organization_id' => $otherOrg->id,
            'name' => 'Foreign',
            'sku' => 'F-1',
            'is_active' => true,
        ]);
        $foreignVariant = ProductVariant::query()->create([
            'organization_id' => $otherOrg->id,
            'product_id' => $foreignProduct->id,
            'name' => 'Default',
            'sku' => 'F-1-D',
            'price' => '10.0000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->postJson('/api/v1/portal/cart/items', [
            'variantId' => $foreignVariant->id,
            'qty' => 1,
        ])->assertNotFound();
    }

    public function test_update_and_remove_cart_line(): void
    {
        Sanctum::actingAs($this->account, ['portal']);

        $lineId = $this->postJson('/api/v1/portal/cart/items', [
            'variantId' => $this->variant->id,
            'qty' => 2,
        ])->assertCreated()->json('data.lines.0.id');

        $this->patchJson('/api/v1/portal/cart/items/'.$lineId, [
            'qty' => 5,
        ])->assertOk()
            ->assertJsonPath('data.lines.0.qty', '5.0000');

        $this->patchJson('/api/v1/portal/cart/items/'.$lineId, [
            'qty' => 0,
        ])->assertOk()
            ->assertJsonCount(0, 'data.lines');

        $this->assertDatabaseMissing('portal_cart_items', ['id' => $lineId]);
    }

    public function test_checkout_preview_returns_server_totals(): void
    {
        Sanctum::actingAs($this->account, ['portal']);

        $this->postJson('/api/v1/portal/cart/items', [
            'variantId' => $this->variant->id,
            'qty' => 2,
        ])->assertCreated();

        $address = $this->createAddress($this->account);

        $preview = $this->postJson('/api/v1/portal/checkout/preview', [
            'fulfilmentMode' => 'home_delivery',
            'addressId' => $address->id,
            'storeId' => $this->store->id,
        ])->assertOk();

        $this->assertSame('500.0000', $preview->json('data.totals.deliveryFee'));
        $this->assertTrue(
            bccomp((string) $preview->json('data.totals.grandTotal'), (string) $preview->json('data.totals.subtotal'), 4) > 0
            || bccomp((string) $preview->json('data.totals.deliveryFee'), '0', 4) > 0
        );
    }

    public function test_checkout_preview_empty_cart_422(): void
    {
        Sanctum::actingAs($this->account, ['portal']);

        $this->postJson('/api/v1/portal/checkout/preview', [
            'fulfilmentMode' => 'store_pickup',
            'storeId' => $this->store->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['cart']);
    }

    public function test_place_order_manual_creates_pending_awaiting_payment(): void
    {
        Sanctum::actingAs($this->account, ['portal']);

        $this->postJson('/api/v1/portal/cart/items', [
            'variantId' => $this->variant->id,
            'qty' => 2,
        ])->assertCreated();

        $response = $this->withHeader('Idempotency-Key', 'place-order-key-1')
            ->postJson('/api/v1/portal/checkout/place', [
                'fulfilmentMode' => 'click_collect',
                'storeId' => $this->store->id,
                'paymentProvider' => 'manual',
                'contactPhone' => '+2348010000000',
            ])->assertCreated();

        $response->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.paymentStatus', 'awaiting_payment')
            ->assertJsonPath('data.payment.provider', 'manual');

        $orderId = $response->json('data.id');
        $this->assertSame(0, PortalCartItem::query()->count());

        $this->postJson('/api/v1/portal/orders/'.$orderId.'/confirm-payment')
            ->assertOk()
            ->assertJsonPath('data.paymentStatus', 'paid')
            ->assertJsonPath('data.status', 'confirmed');
    }

    public function test_place_order_idempotency_returns_same_order(): void
    {
        Sanctum::actingAs($this->account, ['portal']);

        $this->postJson('/api/v1/portal/cart/items', [
            'variantId' => $this->variant->id,
            'qty' => 1,
        ])->assertCreated();

        $payload = [
            'fulfilmentMode' => 'store_pickup',
            'storeId' => $this->store->id,
            'paymentProvider' => 'manual',
        ];

        $first = $this->withHeader('Idempotency-Key', 'idem-same-1')
            ->postJson('/api/v1/portal/checkout/place', $payload)
            ->assertCreated();

        $second = $this->withHeader('Idempotency-Key', 'idem-same-1')
            ->postJson('/api/v1/portal/checkout/place', $payload)
            ->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, PortalOrder::query()->count());
    }

    public function test_insufficient_stock_rejects_without_creating_order(): void
    {
        Sanctum::actingAs($this->account, ['portal']);

        $this->postJson('/api/v1/portal/cart/items', [
            'variantId' => $this->variant->id,
            'qty' => 50,
        ])->assertCreated();

        $this->withHeader('Idempotency-Key', 'oversell-1')
            ->postJson('/api/v1/portal/checkout/place', [
                'fulfilmentMode' => 'store_pickup',
                'storeId' => $this->store->id,
                'paymentProvider' => 'manual',
            ])->assertStatus(422)
                ->assertJsonValidationErrors(['stock']);

        $this->assertSame(0, PortalOrder::query()->count());
        $this->assertSame(1, PortalCartItem::query()->count());
    }

    public function test_unverified_and_guest_rejected_from_cart(): void
    {
        $this->postJson('/api/v1/portal/cart/items', [
            'variantId' => $this->variant->id,
            'qty' => 1,
        ])->assertUnauthorized();

        $register = $this->postJson('/api/v1/portal/auth/register', [
            'name' => 'Unverified',
            'email' => 'unverified@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertCreated();

        // Unverified accounts do not receive a login token; force a token for the matrix case.
        $unverified = CustomerAccount::query()->where('email', 'unverified@example.com')->firstOrFail();
        $this->assertNull($unverified->email_verified_at);
        $token = $unverified->createToken('portal', ['portal'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/portal/cart/items', [
                'variantId' => $this->variant->id,
                'qty' => 1,
            ])->assertForbidden();

        unset($register);
    }

    public function test_payment_webhook_replay_is_idempotent(): void
    {
        Sanctum::actingAs($this->account, ['portal']);

        $this->postJson('/api/v1/portal/cart/items', [
            'variantId' => $this->variant->id,
            'qty' => 1,
        ])->assertCreated();

        $order = $this->withHeader('Idempotency-Key', 'pay-wh-1')
            ->postJson('/api/v1/portal/checkout/place', [
                'fulfilmentMode' => 'store_pickup',
                'storeId' => $this->store->id,
                'paymentProvider' => 'stripe',
            ])->assertCreated();

        $reference = $order->json('data.paymentReference');
        $payload = [
            'id' => 'evt_test_replay_1',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'client_reference_id' => $reference,
                ],
            ],
        ];

        $this->postJson('/api/v1/webhooks/payments/stripe', $payload)
            ->assertOk()
            ->assertJsonPath('created', true)
            ->assertJsonPath('paymentStatus', 'paid');

        $this->postJson('/api/v1/webhooks/payments/stripe', $payload)
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('paymentStatus', 'paid');

        $this->assertSame('paid', PortalOrder::query()->find($order->json('data.id'))->payment_status);
        $this->assertSame(1, \App\Models\PaymentWebhookEvent::query()->count());
    }

    public function test_staff_token_rejected_on_portal_cart(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('Password1!'),
        ]);
        $token = $user->createToken('api', ['staff'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/portal/cart')
            ->assertUnauthorized();
    }

    public function test_portal_token_still_blocked_from_pos_sales(): void
    {
        $token = $this->account->createToken('portal', ['portal'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/pos/sales', [])
            ->assertUnauthorized();
    }

    public function test_lists_active_stores_for_checkout(): void
    {
        Store::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Inactive',
            'is_active' => false,
        ]);

        $this->getJson('/api/v1/portal/stores')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Ikeja Store A')
            ->assertJsonCount(1, 'data');
    }

    public function test_place_home_delivery_includes_delivery_fee(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $address = $this->createAddress($this->account);

        $this->postJson('/api/v1/portal/cart/items', [
            'variantId' => $this->variant->id,
            'qty' => 1,
        ])->assertCreated();

        $this->withHeader('Idempotency-Key', 'delivery-fee-1')
            ->postJson('/api/v1/portal/checkout/place', [
                'fulfilmentMode' => 'home_delivery',
                'addressId' => $address->id,
                'storeId' => $this->store->id,
                'paymentProvider' => 'manual',
            ])->assertCreated()
                ->assertJsonPath('data.totals.deliveryFee', '500.0000')
                ->assertJsonPath('data.fulfilmentMode', 'home_delivery');
    }

    public function test_bad_stripe_signature_rejected_when_secret_set(): void
    {
        config(['payments.stripe.webhook_secret' => 'whsec_test_secret']);

        Sanctum::actingAs($this->account, ['portal']);
        $this->postJson('/api/v1/portal/cart/items', [
            'variantId' => $this->variant->id,
            'qty' => 1,
        ])->assertCreated();

        $order = $this->withHeader('Idempotency-Key', 'stripe-sig-1')
            ->postJson('/api/v1/portal/checkout/place', [
                'fulfilmentMode' => 'store_pickup',
                'storeId' => $this->store->id,
                'paymentProvider' => 'stripe',
            ])->assertCreated();

        $this->withHeader('Stripe-Signature', 't=1,v1=badsignature')
            ->postJson('/api/v1/webhooks/payments/stripe', [
                'id' => 'evt_bad_sig',
                'type' => 'checkout.session.completed',
                'data' => [
                    'object' => [
                        'client_reference_id' => $order->json('data.paymentReference'),
                    ],
                ],
            ])->assertStatus(400);
    }

    public function test_idempotency_key_fingerprint_conflict_returns_422(): void
    {
        Sanctum::actingAs($this->account, ['portal']);

        $this->postJson('/api/v1/portal/cart/items', [
            'variantId' => $this->variant->id,
            'qty' => 1,
        ])->assertCreated();

        $this->withHeader('Idempotency-Key', 'fp-conflict-1')
            ->postJson('/api/v1/portal/checkout/place', [
                'fulfilmentMode' => 'store_pickup',
                'storeId' => $this->store->id,
                'paymentProvider' => 'manual',
                'contactPhone' => '+234111',
            ])->assertCreated();

        // Cart cleared — re-add for a second place attempt with same key but different phone.
        $this->postJson('/api/v1/portal/cart/items', [
            'variantId' => $this->variant->id,
            'qty' => 1,
        ])->assertCreated();

        $this->withHeader('Idempotency-Key', 'fp-conflict-1')
            ->postJson('/api/v1/portal/checkout/place', [
                'fulfilmentMode' => 'store_pickup',
                'storeId' => $this->store->id,
                'paymentProvider' => 'manual',
                'contactPhone' => '+234222',
            ])->assertStatus(422)
                ->assertJsonValidationErrors(['idempotencyKey']);
    }

    public function test_show_order_owner_ok_other_account_404(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $this->postJson('/api/v1/portal/cart/items', [
            'variantId' => $this->variant->id,
            'qty' => 1,
        ])->assertCreated();

        $orderId = $this->withHeader('Idempotency-Key', 'show-order-1')
            ->postJson('/api/v1/portal/checkout/place', [
                'fulfilmentMode' => 'store_pickup',
                'storeId' => $this->store->id,
                'paymentProvider' => 'manual',
            ])->assertCreated()
                ->json('data.id');

        $this->getJson('/api/v1/portal/orders/'.$orderId)
            ->assertOk()
            ->assertJsonPath('data.id', $orderId);

        $other = $this->registerVerifiedPortalAccount('other-shopper@example.com');
        Sanctum::actingAs($other, ['portal']);

        $this->getJson('/api/v1/portal/orders/'.$orderId)
            ->assertNotFound();
    }

    public function test_paid_then_failed_webhook_leaves_paid(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $this->postJson('/api/v1/portal/cart/items', [
            'variantId' => $this->variant->id,
            'qty' => 1,
        ])->assertCreated();

        $order = $this->withHeader('Idempotency-Key', 'paid-terminal-1')
            ->postJson('/api/v1/portal/checkout/place', [
                'fulfilmentMode' => 'store_pickup',
                'storeId' => $this->store->id,
                'paymentProvider' => 'stripe',
            ])->assertCreated();

        $reference = $order->json('data.paymentReference');
        $orderId = $order->json('data.id');

        $this->postJson('/api/v1/webhooks/payments/stripe', [
            'id' => 'evt_paid_first',
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['client_reference_id' => $reference]],
        ])->assertOk()->assertJsonPath('paymentStatus', 'paid');

        $this->postJson('/api/v1/webhooks/payments/stripe', [
            'id' => 'evt_failed_later',
            'type' => 'payment_intent.payment_failed',
            'data' => ['object' => ['client_reference_id' => $reference]],
        ])->assertOk()->assertJsonPath('paymentStatus', 'paid');

        $this->assertSame('paid', PortalOrder::query()->find($orderId)->payment_status);
    }

    public function test_stripe_idempotent_replay_returns_redirect_url(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $this->postJson('/api/v1/portal/cart/items', [
            'variantId' => $this->variant->id,
            'qty' => 1,
        ])->assertCreated();

        $payload = [
            'fulfilmentMode' => 'store_pickup',
            'storeId' => $this->store->id,
            'paymentProvider' => 'stripe',
        ];

        $first = $this->withHeader('Idempotency-Key', 'stripe-replay-url')
            ->postJson('/api/v1/portal/checkout/place', $payload)
            ->assertCreated();

        $this->assertNotEmpty($first->json('data.payment.redirectUrl'));
        $this->assertStringContainsString('orderId='.$first->json('data.id'), (string) $first->json('data.payment.redirectUrl'));

        $second = $this->withHeader('Idempotency-Key', 'stripe-replay-url')
            ->postJson('/api/v1/portal/checkout/place', $payload)
            ->assertOk();

        $this->assertNotEmpty($second->json('data.payment.redirectUrl'));
        $this->assertSame($first->json('data.payment.redirectUrl'), $second->json('data.payment.redirectUrl'));
    }

    public function test_manual_webhook_requires_secret(): void
    {
        config(['payments.manual.webhook_secret' => 'manual-secret-xyz']);

        Sanctum::actingAs($this->account, ['portal']);
        $this->postJson('/api/v1/portal/cart/items', [
            'variantId' => $this->variant->id,
            'qty' => 1,
        ])->assertCreated();

        $order = $this->withHeader('Idempotency-Key', 'manual-wh-1')
            ->postJson('/api/v1/portal/checkout/place', [
                'fulfilmentMode' => 'store_pickup',
                'storeId' => $this->store->id,
                'paymentProvider' => 'manual',
            ])->assertCreated();

        $this->postJson('/api/v1/webhooks/payments/manual', [
            'event_id' => 'man_1',
            'reference' => $order->json('data.paymentReference'),
            'status' => 'paid',
        ])->assertStatus(400);

        $this->withHeader('X-Manual-Webhook-Secret', 'manual-secret-xyz')
            ->postJson('/api/v1/webhooks/payments/manual', [
                'event_id' => 'man_1',
                'reference' => $order->json('data.paymentReference'),
                'status' => 'paid',
            ])->assertOk()
                ->assertJsonPath('paymentStatus', 'paid');
    }

    public function test_place_rejects_inactive_parent_product(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $this->postJson('/api/v1/portal/cart/items', [
            'variantId' => $this->variant->id,
            'qty' => 1,
        ])->assertCreated();

        $this->variant->product->update(['is_active' => false]);

        $this->withHeader('Idempotency-Key', 'inactive-product-1')
            ->postJson('/api/v1/portal/checkout/place', [
                'fulfilmentMode' => 'store_pickup',
                'storeId' => $this->store->id,
                'paymentProvider' => 'manual',
            ])->assertStatus(422)
                ->assertJsonValidationErrors(['cart']);
    }

    public function test_get_cart_removes_inactive_variant_lines(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $this->postJson('/api/v1/portal/cart/items', [
            'variantId' => $this->variant->id,
            'qty' => 1,
        ])->assertCreated();

        $this->variant->update(['is_active' => false]);

        $this->getJson('/api/v1/portal/cart')
            ->assertOk()
            ->assertJsonCount(0, 'data.lines')
            ->assertJsonPath('data.removedLines.0.reason', 'inactive_variant');

        $this->assertSame(0, PortalCartItem::query()->count());
    }

    private function createAddress(CustomerAccount $account): CustomerAddress
    {
        return CustomerAddress::query()->create([
            'organization_id' => $account->organization_id,
            'customer_id' => $account->customer_id,
            'type' => 'shipping',
            'line1' => '12 Portal Lane',
            'city' => 'Lagos',
            'is_default' => true,
        ]);
    }

    private function registerVerifiedPortalAccount(string $email, string $name = 'Portal User'): CustomerAccount
    {
        $register = $this->postJson('/api/v1/portal/auth/register', [
            'name' => $name,
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
