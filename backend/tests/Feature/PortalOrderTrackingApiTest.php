<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\CustomerPortal\Models\CustomerAccount;
use Modules\CustomerPortal\Models\PortalOrder;
use Modules\CustomerPortal\Support\PortalOrderStatuses;
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

class PortalOrderTrackingApiTest extends TestCase
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
            'name' => 'Portal Orders Org',
        ]);
        config([
            'customer_portal.organization_id' => $this->organization->id,
            'customer_portal.delivery_fee' => '0.0000',
            'payments.portal_frontend_url' => 'http://localhost:5175',
            'payments.allowed_redirect_origins' => 'http://localhost:5175',
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
            'slug' => 'beverages-orders',
            'is_active' => true,
        ]);

        $product = Product::factory()->create([
            'organization_id' => $this->organization->id,
            'category_id' => $category->id,
            'name' => 'Peak Milk 400g',
            'sku' => 'SKU-ORD-1',
            'selling_price' => '1850.0000',
            'is_active' => true,
        ]);

        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->organization->id,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'SKU-ORD-1-D',
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
            quantity: '20.0000',
            unitCost: '1000.0000',
        ));

        $this->account = $this->registerVerifiedPortalAccount('orders@example.com');
    }

    public function test_list_own_orders_paginated_newest_first(): void
    {
        Sanctum::actingAs($this->account, ['portal']);

        $older = $this->placeOrder('list-older');
        $newer = $this->placeOrder('list-newer');

        $response = $this->getJson('/api/v1/portal/orders')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.currentPage', 1);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([(int) $newer, (int) $older], $ids);
        $this->assertArrayHasKey('statusLabel', $response->json('data.0'));
        $this->assertArrayHasKey('totals', $response->json('data.0'));
    }

    public function test_list_empty_for_account_without_orders(): void
    {
        $other = $this->registerVerifiedPortalAccount('empty-orders@example.com');
        Sanctum::actingAs($other, ['portal']);

        $this->getJson('/api/v1/portal/orders')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonCount(0, 'data');
    }

    public function test_show_own_order_includes_timeline_and_lines(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placeOrder('show-detail-1');

        $this->postJson('/api/v1/portal/orders/'.$orderId.'/confirm-payment')
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.statusLabel', 'Confirmed')
            ->assertJsonPath('data.paymentStatus', 'paid');

        $show = $this->getJson('/api/v1/portal/orders/'.$orderId)
            ->assertOk()
            ->assertJsonPath('data.id', $orderId)
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.statusLabel', 'Confirmed')
            ->assertJsonPath('data.invoiceAvailable', true);

        $this->assertNotEmpty($show->json('data.items'));
        $this->assertIsArray($show->json('data.timeline'));
        $this->assertGreaterThanOrEqual(4, count($show->json('data.timeline')));

        $timeline = $show->json('data.timeline');
        $this->assertSame('completed', $timeline[0]['state']);
        $this->assertSame('current', $timeline[1]['state']);
        $this->assertSame(PortalOrderStatuses::CONFIRMED, $timeline[1]['key']);
        $this->assertSame('upcoming', $timeline[2]['state']);
        $this->assertNotNull($show->json('data.paidAt'));
        $this->assertSame('Ikeja Store A', $show->json('data.store.name'));
    }

    public function test_show_pickup_timeline_uses_ready_for_pickup_step(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placeOrder('pickup-timeline', 'click_collect');

        PortalOrder::query()->where('id', $orderId)->update([
            'status' => PortalOrderStatuses::READY_FOR_PICKUP,
            'payment_status' => 'paid',
            'paid_at' => now(),
        ]);

        $keys = collect($this->getJson('/api/v1/portal/orders/'.$orderId)->assertOk()->json('data.timeline'))
            ->pluck('key')
            ->all();

        $this->assertContains(PortalOrderStatuses::READY_FOR_PICKUP, $keys);
        $this->assertNotContains(PortalOrderStatuses::OUT_FOR_DELIVERY, $keys);
    }

    public function test_show_delivery_timeline_uses_out_for_delivery_step(): void
    {
        Sanctum::actingAs($this->account, ['portal']);

        $this->postJson('/api/v1/portal/cart/items', [
            'variantId' => $this->variant->id,
            'qty' => 1,
        ])->assertCreated();

        // home_delivery needs an address — create via account API helpers in checkout tests pattern
        $addressId = \Modules\Customers\Models\CustomerAddress::query()->create([
            'organization_id' => $this->account->organization_id,
            'customer_id' => $this->account->customer_id,
            'type' => 'shipping',
            'line1' => '1 Delivery Rd',
            'city' => 'Lagos',
            'is_default' => true,
        ])->id;

        $orderId = $this->withHeader('Idempotency-Key', 'delivery-timeline')
            ->postJson('/api/v1/portal/checkout/place', [
                'fulfilmentMode' => 'home_delivery',
                'storeId' => $this->store->id,
                'addressId' => $addressId,
                'paymentProvider' => 'manual',
            ])->assertCreated()
                ->json('data.id');

        PortalOrder::query()->where('id', $orderId)->update([
            'status' => PortalOrderStatuses::OUT_FOR_DELIVERY,
            'payment_status' => 'paid',
            'paid_at' => now(),
        ]);

        $keys = collect($this->getJson('/api/v1/portal/orders/'.$orderId)->assertOk()->json('data.timeline'))
            ->pluck('key')
            ->all();

        $this->assertContains(PortalOrderStatuses::OUT_FOR_DELIVERY, $keys);
        $this->assertNotContains(PortalOrderStatuses::READY_FOR_PICKUP, $keys);
    }

    public function test_cancelled_order_timeline_ends_with_cancelled_current(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placeOrder('cancelled-timeline');

        PortalOrder::query()->where('id', $orderId)->update([
            'status' => PortalOrderStatuses::CANCELLED,
        ]);

        $timeline = $this->getJson('/api/v1/portal/orders/'.$orderId)
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.statusLabel', 'Cancelled')
            ->json('data.timeline');

        $this->assertNotEmpty($timeline);
        $last = $timeline[count($timeline) - 1];
        $this->assertSame(PortalOrderStatuses::CANCELLED, $last['key']);
        $this->assertSame('current', $last['state']);
    }

    public function test_returned_and_refunded_timelines_end_with_terminal_current(): void
    {
        Sanctum::actingAs($this->account, ['portal']);

        foreach ([PortalOrderStatuses::RETURNED, PortalOrderStatuses::REFUNDED] as $terminal) {
            $orderId = $this->placeOrder('terminal-'.$terminal);
            PortalOrder::query()->where('id', $orderId)->update([
                'status' => $terminal,
                'payment_status' => 'paid',
                'paid_at' => now(),
            ]);

            $timeline = $this->getJson('/api/v1/portal/orders/'.$orderId)
                ->assertOk()
                ->assertJsonPath('data.status', $terminal)
                ->json('data.timeline');

            $last = $timeline[count($timeline) - 1];
            $this->assertSame($terminal, $last['key']);
            $this->assertSame('current', $last['state']);
        }
    }

    public function test_paid_webhook_on_cancelled_order_is_noop(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placeOrder('cancelled-paid-noop');

        PortalOrder::query()->where('id', $orderId)->update([
            'status' => PortalOrderStatuses::CANCELLED,
            'payment_provider' => 'stripe',
        ]);
        $reference = PortalOrder::query()->findOrFail($orderId)->payment_reference;

        $this->postJson('/api/v1/webhooks/payments/stripe', [
            'id' => 'evt_cancelled_paid_noop',
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['client_reference_id' => $reference]],
        ])->assertOk();

        $order = PortalOrder::query()->findOrFail($orderId);
        $this->assertSame(PortalOrderStatuses::CANCELLED, $order->status);
        $this->assertSame('awaiting_payment', $order->payment_status);
        $this->assertNull($order->paid_at);
    }

    public function test_invoice_when_paid_returns_html_download(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placeOrder('invoice-paid');
        $this->postJson('/api/v1/portal/orders/'.$orderId.'/confirm-payment')->assertOk();

        $order = PortalOrder::query()->findOrFail($orderId);

        $response = $this->get('/api/v1/portal/orders/'.$orderId.'/invoice')
            ->assertOk();

        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString(
            'invoice-'.$order->order_number.'.html',
            (string) $response->headers->get('Content-Disposition'),
        );
        $response->assertSee($order->order_number, false);
        $response->assertSee('Peak Milk 400g', false);
        $response->assertSee('Portal Orders Org', false);
    }

    public function test_invoice_unpaid_rejected(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placeOrder('invoice-unpaid');

        $this->getJson('/api/v1/portal/orders/'.$orderId.'/invoice')
            ->assertStatus(422);
    }

    public function test_other_account_order_is_404_for_show_and_invoice(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placeOrder('other-404');
        $this->postJson('/api/v1/portal/orders/'.$orderId.'/confirm-payment')->assertOk();

        $other = $this->registerVerifiedPortalAccount('other-orders@example.com');
        Sanctum::actingAs($other, ['portal']);

        $this->getJson('/api/v1/portal/orders/'.$orderId)->assertNotFound();
        $this->getJson('/api/v1/portal/orders/'.$orderId.'/invoice')->assertNotFound();
    }

    public function test_unknown_order_404(): void
    {
        Sanctum::actingAs($this->account, ['portal']);

        $this->getJson('/api/v1/portal/orders/999999')->assertNotFound();
        $this->getJson('/api/v1/portal/orders/999999/invoice')->assertNotFound();
    }

    public function test_guest_and_unverified_rejected_from_orders(): void
    {
        $this->getJson('/api/v1/portal/orders')->assertUnauthorized();

        $register = $this->postJson('/api/v1/portal/auth/register', [
            'name' => 'Unverified Orders',
            'email' => 'unverified-orders@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertCreated();

        $unverified = CustomerAccount::query()->where('email', 'unverified-orders@example.com')->firstOrFail();
        $token = $unverified->createToken('portal', ['portal'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/portal/orders')
            ->assertForbidden();

        unset($register);
    }

    public function test_staff_token_rejected_on_portal_orders(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api', ['staff'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/portal/orders')
            ->assertUnauthorized();
    }

    private function placeOrder(string $idempotencyKey, string $fulfilmentMode = 'store_pickup'): int
    {
        $this->postJson('/api/v1/portal/cart/items', [
            'variantId' => $this->variant->id,
            'qty' => 1,
        ])->assertCreated();

        return (int) $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/portal/checkout/place', [
                'fulfilmentMode' => $fulfilmentMode,
                'storeId' => $this->store->id,
                'paymentProvider' => 'manual',
            ])->assertCreated()
                ->json('data.id');
    }

    private function registerVerifiedPortalAccount(string $email, string $name = 'Portal Orders User'): CustomerAccount
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
