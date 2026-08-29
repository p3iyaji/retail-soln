<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\CustomerPortal\Models\CustomerAccount;
use Modules\CustomerPortal\Models\PortalOrder;
use Modules\CustomerPortal\Models\PortalReturnRequest;
use Modules\CustomerPortal\Support\PortalOrderStatuses;
use Modules\CustomerPortal\Support\PortalReturnRequestStatuses;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Models\InventoryBalance;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;
use Modules\Products\Models\Category;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Tests\TestCase;

class PortalReturnRequestApiTest extends TestCase
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
            'name' => 'Portal Returns Org',
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
            'name' => 'Returns Store',
            'is_active' => true,
        ]);

        $category = Category::query()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Returns Cat',
            'slug' => 'returns-cat',
            'is_active' => true,
        ]);

        $product = Product::factory()->create([
            'organization_id' => $this->organization->id,
            'category_id' => $category->id,
            'name' => 'Returnable Widget',
            'sku' => 'SKU-RET-1',
            'selling_price' => '2500.0000',
            'is_active' => true,
        ]);

        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->organization->id,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'SKU-RET-1-D',
            'price' => '2500.0000',
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
            quantity: '50.0000',
            unitCost: '1000.0000',
        ));

        $this->account = $this->registerVerifiedPortalAccount('returns@example.com');
    }

    public function test_create_eligible_return_request_and_list_show(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $order = $this->deliveredPaidOrder('create-eligible');

        $stockBefore = $this->stockQty();
        $balancesBefore = $this->balanceSnapshot();

        $itemId = (int) $order->items()->firstOrFail()->id;

        $created = $this->withHeader('Idempotency-Key', 'ret-create-1')
            ->postJson('/api/v1/portal/return-requests', [
                'orderId' => $order->id,
                'reason' => 'defective',
                'notes' => 'Box was crushed',
                'items' => [
                    ['orderItemId' => $itemId, 'qty' => 1],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', PortalReturnRequestStatuses::REQUESTED)
            ->assertJsonPath('data.reason', 'defective')
            ->assertJsonPath('data.orderId', $order->id);

        $this->assertSame($stockBefore, $this->stockQty());
        $this->assertSame($balancesBefore, $this->balanceSnapshot());
        $this->assertDatabaseCount('portal_return_requests', 1);
        $this->assertDatabaseCount('sale_returns', 0);

        $requestId = (int) $created->json('data.id');

        $this->getJson('/api/v1/portal/return-requests')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $requestId);

        $this->getJson('/api/v1/portal/return-requests/'.$requestId)
            ->assertOk()
            ->assertJsonPath('data.id', $requestId)
            ->assertJsonPath('data.statusLabel', 'Requested')
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.refunds', []);

        $show = $this->getJson('/api/v1/portal/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.returnEligible', false)
            ->assertJsonPath('data.activeReturnRequest.id', $requestId)
            ->assertJsonPath('data.returnRequest.status', PortalReturnRequestStatuses::REQUESTED)
            ->assertJsonPath('data.refunds', []);

        $this->assertSame('0.0000', $show->json('data.items.0.returnableQty'));
    }

    public function test_idempotency_replay_returns_same_request(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $order = $this->deliveredPaidOrder('idem-same');
        $itemId = (int) $order->items()->firstOrFail()->id;

        $body = [
            'orderId' => $order->id,
            'reason' => 'wrong_item',
            'notes' => null,
            'items' => [
                ['orderItemId' => $itemId, 'qty' => '1.0000'],
            ],
        ];

        $first = $this->withHeader('Idempotency-Key', 'ret-idem-1')
            ->postJson('/api/v1/portal/return-requests', $body)
            ->assertCreated();

        $second = $this->withHeader('Idempotency-Key', 'ret-idem-1')
            ->postJson('/api/v1/portal/return-requests', $body)
            ->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('portal_return_requests', 1);
    }

    public function test_idempotency_conflict_on_different_body(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $order = $this->deliveredPaidOrder('idem-conflict');
        $itemId = (int) $order->items()->firstOrFail()->id;

        $this->withHeader('Idempotency-Key', 'ret-idem-conflict')
            ->postJson('/api/v1/portal/return-requests', [
                'orderId' => $order->id,
                'reason' => 'defective',
                'items' => [['orderItemId' => $itemId, 'qty' => 1]],
            ])
            ->assertCreated();

        // Reject existing so a different body could otherwise create — still conflict on key.
        PortalReturnRequest::query()->update(['status' => PortalReturnRequestStatuses::REJECTED]);

        $this->withHeader('Idempotency-Key', 'ret-idem-conflict')
            ->postJson('/api/v1/portal/return-requests', [
                'orderId' => $order->id,
                'reason' => 'changed_mind',
                'items' => [['orderItemId' => $itemId, 'qty' => 1]],
            ])
            ->assertStatus(422);
    }

    public function test_create_ineligible_unpaid_not_delivered_terminal(): void
    {
        Sanctum::actingAs($this->account, ['portal']);

        $unpaidId = $this->placeOrder('inelig-unpaid');
        $unpaid = PortalOrder::query()->with('items')->findOrFail($unpaidId);
        $itemId = (int) $unpaid->items->first()->id;

        $this->withHeader('Idempotency-Key', 'ret-unpaid')
            ->postJson('/api/v1/portal/return-requests', [
                'orderId' => $unpaidId,
                'reason' => 'other',
                'items' => [['orderItemId' => $itemId, 'qty' => 1]],
            ])
            ->assertStatus(422);

        $confirmed = $this->deliveredPaidOrder('inelig-confirmed');
        PortalOrder::query()->where('id', $confirmed->id)->update([
            'status' => PortalOrderStatuses::CONFIRMED,
        ]);
        $confirmedItem = (int) $confirmed->items()->firstOrFail()->id;

        $this->withHeader('Idempotency-Key', 'ret-not-delivered')
            ->postJson('/api/v1/portal/return-requests', [
                'orderId' => $confirmed->id,
                'reason' => 'other',
                'items' => [['orderItemId' => $confirmedItem, 'qty' => 1]],
            ])
            ->assertStatus(422);

        foreach ([PortalOrderStatuses::CANCELLED, PortalOrderStatuses::RETURNED, PortalOrderStatuses::REFUNDED] as $terminal) {
            $order = $this->deliveredPaidOrder('inelig-'.$terminal);
            PortalOrder::query()->where('id', $order->id)->update(['status' => $terminal]);
            $lineId = (int) $order->items()->firstOrFail()->id;

            $this->withHeader('Idempotency-Key', 'ret-'.$terminal)
                ->postJson('/api/v1/portal/return-requests', [
                    'orderId' => $order->id,
                    'reason' => 'other',
                    'items' => [['orderItemId' => $lineId, 'qty' => 1]],
                ])
                ->assertStatus(422);
        }

        $this->assertDatabaseCount('portal_return_requests', 0);
    }

    public function test_duplicate_open_request_rejected(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $order = $this->deliveredPaidOrder('dup-open');
        $itemId = (int) $order->items()->firstOrFail()->id;

        $this->withHeader('Idempotency-Key', 'ret-dup-1')
            ->postJson('/api/v1/portal/return-requests', [
                'orderId' => $order->id,
                'reason' => 'defective',
                'items' => [['orderItemId' => $itemId, 'qty' => 1]],
            ])
            ->assertCreated();

        $this->withHeader('Idempotency-Key', 'ret-dup-2')
            ->postJson('/api/v1/portal/return-requests', [
                'orderId' => $order->id,
                'reason' => 'changed_mind',
                'items' => [['orderItemId' => $itemId, 'qty' => 1]],
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('portal_return_requests', 1);
    }

    public function test_over_qty_rejected(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $order = $this->deliveredPaidOrder('over-qty', qty: 2);
        $itemId = (int) $order->items()->firstOrFail()->id;

        $this->withHeader('Idempotency-Key', 'ret-over')
            ->postJson('/api/v1/portal/return-requests', [
                'orderId' => $order->id,
                'reason' => 'other',
                'items' => [['orderItemId' => $itemId, 'qty' => 3]],
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('portal_return_requests', 0);
    }

    public function test_missing_idempotency_key_rejected(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $order = $this->deliveredPaidOrder('no-idem');
        $itemId = (int) $order->items()->firstOrFail()->id;

        $this->flushHeaders();
        Sanctum::actingAs($this->account, ['portal']);

        $this->postJson('/api/v1/portal/return-requests', [
            'orderId' => $order->id,
            'reason' => 'other',
            'items' => [['orderItemId' => $itemId, 'qty' => 1]],
        ])->assertStatus(422);
    }

    public function test_other_account_request_is_404(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $order = $this->deliveredPaidOrder('other-ret');
        $itemId = (int) $order->items()->firstOrFail()->id;

        $requestId = (int) $this->withHeader('Idempotency-Key', 'ret-other-owner')
            ->postJson('/api/v1/portal/return-requests', [
                'orderId' => $order->id,
                'reason' => 'defective',
                'items' => [['orderItemId' => $itemId, 'qty' => 1]],
            ])
            ->assertCreated()
            ->json('data.id');

        $other = $this->registerVerifiedPortalAccount('other-returns@example.com');
        Sanctum::actingAs($other, ['portal']);

        $this->getJson('/api/v1/portal/return-requests/'.$requestId)->assertNotFound();
        $this->getJson('/api/v1/portal/return-requests')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_guest_and_unverified_rejected(): void
    {
        $this->getJson('/api/v1/portal/return-requests')->assertUnauthorized();
        $this->postJson('/api/v1/portal/return-requests', [])->assertUnauthorized();

        $register = $this->postJson('/api/v1/portal/auth/register', [
            'name' => 'Unverified Returns',
            'email' => 'unverified-returns@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertCreated();

        $unverified = CustomerAccount::query()->where('email', 'unverified-returns@example.com')->firstOrFail();
        $token = $unverified->createToken('portal', ['portal'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/portal/return-requests')
            ->assertForbidden();

        unset($register);
    }

    public function test_staff_token_rejected_on_portal_return_requests(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api', ['staff'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/portal/return-requests')
            ->assertUnauthorized();
    }

    public function test_order_detail_eligible_before_request(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $order = $this->deliveredPaidOrder('eligible-flag');

        $this->getJson('/api/v1/portal/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.returnEligible', true)
            ->assertJsonPath('data.activeReturnRequest', null)
            ->assertJsonPath('data.items.0.returnableQty', '1.0000');
    }

    public function test_whitespace_idempotency_key_rejected(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $order = $this->deliveredPaidOrder('ws-idem');
        $itemId = (int) $order->items()->firstOrFail()->id;

        $this->withHeader('Idempotency-Key', '   ')
            ->postJson('/api/v1/portal/return-requests', [
                'orderId' => $order->id,
                'reason' => 'other',
                'items' => [['orderItemId' => $itemId, 'qty' => 1]],
            ])
            ->assertStatus(422);
    }

    public function test_rejected_request_restores_eligibility_and_summary(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $order = $this->deliveredPaidOrder('after-reject');
        $itemId = (int) $order->items()->firstOrFail()->id;
        $balancesBefore = $this->balanceSnapshot();

        $requestId = (int) $this->withHeader('Idempotency-Key', 'ret-then-reject')
            ->postJson('/api/v1/portal/return-requests', [
                'orderId' => $order->id,
                'reason' => 'defective',
                'items' => [['orderItemId' => $itemId, 'qty' => 1]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertSame($balancesBefore, $this->balanceSnapshot());

        PortalReturnRequest::query()->where('id', $requestId)->update([
            'status' => PortalReturnRequestStatuses::REJECTED,
        ]);

        $show = $this->getJson('/api/v1/portal/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.returnEligible', true)
            ->assertJsonPath('data.activeReturnRequest', null)
            ->assertJsonPath('data.returnRequest.id', $requestId)
            ->assertJsonPath('data.returnRequest.status', PortalReturnRequestStatuses::REJECTED)
            ->assertJsonPath('data.items.0.returnableQty', '1.0000');

        unset($show);

        $this->withHeader('Idempotency-Key', 'ret-after-reject')
            ->postJson('/api/v1/portal/return-requests', [
                'orderId' => $order->id,
                'reason' => 'changed_mind',
                'items' => [['orderItemId' => $itemId, 'qty' => 1]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.reason', 'changed_mind');

        $this->assertSame($balancesBefore, $this->balanceSnapshot());
        $this->assertDatabaseCount('portal_return_requests', 2);
        $this->assertDatabaseCount('sale_returns', 0);
    }

    public function test_under_review_blocks_second_create(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $order = $this->deliveredPaidOrder('under-review-block');
        $itemId = (int) $order->items()->firstOrFail()->id;

        $requestId = (int) $this->withHeader('Idempotency-Key', 'ret-under-review')
            ->postJson('/api/v1/portal/return-requests', [
                'orderId' => $order->id,
                'reason' => 'other',
                'items' => [['orderItemId' => $itemId, 'qty' => 1]],
            ])
            ->assertCreated()
            ->json('data.id');

        PortalReturnRequest::query()->where('id', $requestId)->update([
            'status' => PortalReturnRequestStatuses::UNDER_REVIEW,
        ]);

        $this->getJson('/api/v1/portal/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.returnEligible', false)
            ->assertJsonPath('data.activeReturnRequest.status', PortalReturnRequestStatuses::UNDER_REVIEW);

        $this->withHeader('Idempotency-Key', 'ret-under-review-2')
            ->postJson('/api/v1/portal/return-requests', [
                'orderId' => $order->id,
                'reason' => 'defective',
                'items' => [['orderItemId' => $itemId, 'qty' => 1]],
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('portal_return_requests', 1);
    }

    public function test_partial_qty_approved_then_remaining_returnable(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $order = $this->deliveredPaidOrder('partial-approved', qty: 3);
        $itemId = (int) $order->items()->firstOrFail()->id;
        $balancesBefore = $this->balanceSnapshot();

        $this->withHeader('Idempotency-Key', 'ret-partial-1')
            ->postJson('/api/v1/portal/return-requests', [
                'orderId' => $order->id,
                'reason' => 'wrong_item',
                'items' => [['orderItemId' => $itemId, 'qty' => '1.0000']],
            ])
            ->assertCreated();

        PortalReturnRequest::query()->where('portal_order_id', $order->id)->update([
            'status' => PortalReturnRequestStatuses::APPROVED,
        ]);

        $show = $this->getJson('/api/v1/portal/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.returnEligible', true)
            ->assertJsonPath('data.activeReturnRequest', null)
            ->assertJsonPath('data.returnRequest.status', PortalReturnRequestStatuses::APPROVED)
            ->assertJsonPath('data.items.0.returnableQty', '2.0000');

        unset($show);

        $this->withHeader('Idempotency-Key', 'ret-partial-over')
            ->postJson('/api/v1/portal/return-requests', [
                'orderId' => $order->id,
                'reason' => 'other',
                'items' => [['orderItemId' => $itemId, 'qty' => 3]],
            ])
            ->assertStatus(422);

        $this->withHeader('Idempotency-Key', 'ret-partial-2')
            ->postJson('/api/v1/portal/return-requests', [
                'orderId' => $order->id,
                'reason' => 'other',
                'items' => [['orderItemId' => $itemId, 'qty' => 2]],
            ])
            ->assertCreated();

        $this->assertSame($balancesBefore, $this->balanceSnapshot());
        $this->assertDatabaseCount('sale_returns', 0);
    }

    private function deliveredPaidOrder(string $idempotencyKey, int $qty = 1): PortalOrder
    {
        $orderId = $this->placeOrder($idempotencyKey, $qty);
        $this->postJson('/api/v1/portal/orders/'.$orderId.'/confirm-payment')->assertOk();

        PortalOrder::query()->where('id', $orderId)->update([
            'status' => PortalOrderStatuses::DELIVERED,
        ]);

        return PortalOrder::query()->with('items')->findOrFail($orderId);
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
     * @return array{available: string, reserved: string, damaged: string, in_transit: string}
     */
    private function balanceSnapshot(): array
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
            'damaged' => $balance ? (string) $balance->damaged : '0.0000',
            'in_transit' => $balance ? (string) $balance->in_transit : '0.0000',
        ];
    }

    private function stockQty(): string
    {
        return $this->balanceSnapshot()['available'];
    }

    private function registerVerifiedPortalAccount(string $email, string $name = 'Portal Returns User'): CustomerAccount
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
