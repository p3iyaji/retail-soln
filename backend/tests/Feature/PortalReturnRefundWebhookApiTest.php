<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\CustomerPortal\Models\CustomerAccount;
use Modules\CustomerPortal\Models\PortalOrder;
use Modules\CustomerPortal\Models\PortalRefund;
use Modules\CustomerPortal\Models\PortalReturnRequest;
use Modules\CustomerPortal\Support\PortalOrderStatuses;
use Modules\CustomerPortal\Support\PortalReturnRequestStatuses;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\Category;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Tests\TestCase;

class PortalReturnRefundWebhookApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Store $store;

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
            'payments.portal_frontend_url' => 'http://localhost:5175',
            'payments.allowed_redirect_origins' => 'http://localhost:5175',
            'payments.stripe.webhook_secret' => '',
            'app.url' => 'http://localhost',
        ]);

        $this->store = Store::factory()->create([
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        $category = Category::query()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Refund Cat',
            'slug' => 'refund-cat',
            'is_active' => true,
        ]);

        $product = Product::factory()->create([
            'organization_id' => $this->organization->id,
            'category_id' => $category->id,
            'name' => 'Refund Widget',
            'sku' => 'SKU-REF-WEB',
            'selling_price' => '3000.0000',
            'is_active' => true,
        ]);

        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->organization->id,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'SKU-REF-WEB-D',
            'price' => '3000.0000',
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

        $this->account = $this->registerVerifiedPortalAccount('refund-webhook@example.com');
        $this->manager = User::factory()->storeManager()->create();
        $this->manager->stores()->attach($this->store->id, ['is_default' => true]);
    }

    public function test_stripe_refund_webhook_marks_portal_refund_completed(): void
    {
        $refund = $this->completeWithGatewayRefund('stripe', 'stripe_refund_wh_1');

        $this->postJson('/api/v1/webhooks/payments/stripe', [
            'id' => 'evt_refund_1',
            'type' => 'charge.refunded',
            'data' => [
                'object' => [
                    'id' => $refund->gateway_reference,
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('refundId', $refund->id)
            ->assertJsonPath('refundStatus', PortalRefund::STATUS_COMPLETED);

        $this->assertSame(PortalRefund::STATUS_COMPLETED, $refund->fresh()->status);
    }

    public function test_paystack_refund_webhook_marks_portal_refund_completed(): void
    {
        $refund = $this->completeWithGatewayRefund('paystack', 'paystack_refund_wh_1');

        $this->postJson('/api/v1/webhooks/payments/paystack', [
            'id' => 'evt_ps_1',
            'event' => 'refund.processed',
            'data' => [
                'reference' => $refund->gateway_reference,
            ],
        ])->assertOk()
            ->assertJsonPath('refundStatus', PortalRefund::STATUS_COMPLETED);
    }

    private function completeWithGatewayRefund(string $provider, string $gatewayReference): PortalRefund
    {
        Sanctum::actingAs($this->account, ['portal']);
        $order = $this->deliveredPaidOrder('gw-order', $provider);
        $itemId = $order->items->first()->id;

        $requestId = (int) $this->withHeader('Idempotency-Key', 'return-'.$provider)
            ->postJson('/api/v1/portal/return-requests', [
                'orderId' => $order->id,
                'reason' => 'defective',
                'items' => [['orderItemId' => $itemId, 'qty' => 1]],
            ])->assertCreated()
                ->json('data.id');

        Sanctum::actingAs($this->manager, ['staff']);
        $detail = $this->getJson('/api/v1/fulfilment/return-requests/'.$requestId, $this->storeHeaders())
            ->json('data');

        $this->patchJson(
            '/api/v1/fulfilment/return-requests/'.$requestId,
            ['status' => PortalReturnRequestStatuses::APPROVED],
            $this->storeHeaders(),
        )->assertOk();

        PortalReturnRequest::query()->whereKey($requestId)->update([
            'status' => PortalReturnRequestStatuses::APPROVED,
        ]);

        $this->postJson(
            '/api/v1/fulfilment/return-requests/'.$requestId.'/complete',
            [
                'refunds' => [
                    [
                        'provider' => $provider,
                        'amount' => $detail['refundTotal'],
                    ],
                ],
            ],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'complete-'.$provider]),
        )->assertCreated();

        $refund = PortalRefund::query()->where('portal_return_request_id', $requestId)->firstOrFail();
        $refund->gateway_reference = $gatewayReference;
        $refund->status = PortalRefund::STATUS_PENDING;
        $refund->save();

        return $refund->fresh();
    }

    private function deliveredPaidOrder(string $idempotencyKey, string $provider): PortalOrder
    {
        $this->postJson('/api/v1/portal/cart/items', [
            'variantId' => $this->variant->id,
            'qty' => 1,
        ])->assertCreated();

        $orderId = (int) $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson('/api/v1/portal/checkout/place', [
                'fulfilmentMode' => 'store_pickup',
                'storeId' => $this->store->id,
                'paymentProvider' => $provider,
            ])->assertCreated()
                ->json('data.id');

        if ($provider === 'manual') {
            $this->postJson('/api/v1/portal/orders/'.$orderId.'/confirm-payment')->assertOk();
        } else {
            PortalOrder::query()->whereKey($orderId)->update(['payment_status' => 'paid', 'paid_at' => now()]);
        }

        PortalOrder::query()->where('id', $orderId)->update([
            'status' => PortalOrderStatuses::DELIVERED,
            'payment_provider' => $provider,
            'payment_reference' => $provider.'_pay_ref_123',
        ]);

        return PortalOrder::query()->with('items')->findOrFail($orderId);
    }

    /**
     * @return array<string, string>
     */
    private function storeHeaders(): array
    {
        return ['X-Store-Id' => (string) $this->store->id];
    }

    private function registerVerifiedPortalAccount(string $email): CustomerAccount
    {
        $register = $this->postJson('/api/v1/portal/auth/register', [
            'name' => 'Refund Webhook Customer',
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
