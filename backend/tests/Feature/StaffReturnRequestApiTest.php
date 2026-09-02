<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\CustomerPortal\Models\CustomerAccount;
use Modules\CustomerPortal\Models\PortalOrder;
use Modules\CustomerPortal\Support\PortalOrderStatuses;
use Modules\CustomerPortal\Support\PortalReturnRequestStatuses;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Models\InventoryBalance;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\Category;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Tests\TestCase;

class StaffReturnRequestApiTest extends TestCase
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
            'payments.portal_frontend_url' => 'http://localhost:5175',
            'payments.allowed_redirect_origins' => 'http://localhost:5175',
            'app.url' => 'http://localhost',
        ]);

        $this->store = Store::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Returns Store',
            'is_active' => true,
        ]);
        $this->otherStore = Store::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Other Store',
            'is_active' => true,
        ]);

        $category = Category::query()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Returns Cat',
            'slug' => 'staff-returns-cat',
            'is_active' => true,
        ]);

        $product = Product::factory()->create([
            'organization_id' => $this->organization->id,
            'category_id' => $category->id,
            'name' => 'Returnable Widget',
            'sku' => 'SKU-STAFF-RET',
            'selling_price' => '2500.0000',
            'is_active' => true,
        ]);

        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->organization->id,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'SKU-STAFF-RET-D',
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

        $this->account = $this->registerVerifiedPortalAccount('staff-returns@example.com');

        $this->manager = User::factory()->storeManager()->create();
        $this->manager->stores()->attach($this->store->id, ['is_default' => true]);
    }

    public function test_staff_lists_and_shows_store_return_requests(): void
    {
        $requestId = $this->createReturnRequest('staff-list');

        Sanctum::actingAs($this->manager, ['staff']);
        $this->getJson('/api/v1/fulfilment/return-requests', $this->storeHeaders())
            ->assertOk()
            ->assertJsonPath('meta.openCount', 1)
            ->assertJsonPath('data.0.id', $requestId)
            ->assertJsonPath('data.0.status', PortalReturnRequestStatuses::REQUESTED);

        $this->getJson('/api/v1/fulfilment/return-requests/'.$requestId, $this->storeHeaders())
            ->assertOk()
            ->assertJsonPath('data.id', $requestId)
            ->assertJsonPath('data.allowedStatuses', [
                PortalReturnRequestStatuses::UNDER_REVIEW,
                PortalReturnRequestStatuses::APPROVED,
                PortalReturnRequestStatuses::REJECTED,
            ]);
    }

    public function test_staff_cannot_see_other_store_return_requests(): void
    {
        $requestId = $this->createReturnRequest('staff-other-store');

        Sanctum::actingAs($this->manager, ['staff']);
        $this->getJson('/api/v1/fulfilment/return-requests/'.$requestId, [
            'X-Store-Id' => (string) $this->otherStore->id,
        ])->assertForbidden();
    }

    public function test_staff_updates_return_request_status(): void
    {
        $requestId = $this->createReturnRequest('staff-update');

        Sanctum::actingAs($this->manager, ['staff']);
        $this->patchJson(
            '/api/v1/fulfilment/return-requests/'.$requestId,
            ['status' => PortalReturnRequestStatuses::UNDER_REVIEW],
            $this->storeHeaders(),
        )
            ->assertOk()
            ->assertJsonPath('data.status', PortalReturnRequestStatuses::UNDER_REVIEW);

        $this->patchJson(
            '/api/v1/fulfilment/return-requests/'.$requestId,
            ['status' => PortalReturnRequestStatuses::APPROVED],
            $this->storeHeaders(),
        )
            ->assertOk()
            ->assertJsonPath('data.status', PortalReturnRequestStatuses::APPROVED);
    }

    public function test_complete_return_posts_stock_and_manual_refund(): void
    {
        $requestId = $this->createReturnRequest('staff-complete');
        $beforeStock = $this->stockQty();

        Sanctum::actingAs($this->manager, ['staff']);
        $detail = $this->getJson('/api/v1/fulfilment/return-requests/'.$requestId, $this->storeHeaders())
            ->assertOk()
            ->json('data');

        $this->patchJson(
            '/api/v1/fulfilment/return-requests/'.$requestId,
            ['status' => PortalReturnRequestStatuses::APPROVED],
            $this->storeHeaders(),
        )->assertOk();

        $this->postJson(
            '/api/v1/fulfilment/return-requests/'.$requestId.'/complete',
            [
                'refunds' => [
                    [
                        'provider' => 'manual',
                        'method' => 'bank',
                        'amount' => $detail['refundTotal'],
                        'reference' => 'BANK-REF-1',
                    ],
                ],
            ],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'complete-1']),
        )
            ->assertCreated()
            ->assertJsonPath('data.status', PortalReturnRequestStatuses::COMPLETED)
            ->assertJsonPath('data.refunds.0.provider', 'manual')
            ->assertJsonPath('data.refunds.0.status', 'completed');

        $this->assertSame(
            bcadd($beforeStock, '1.0000', 4),
            $this->stockQty(),
        );

        $this->assertDatabaseHas('portal_return_requests', [
            'id' => $requestId,
            'status' => PortalReturnRequestStatuses::COMPLETED,
            'complete_idempotency_key' => 'complete-1',
        ]);
    }

    public function test_complete_idempotency_replay(): void
    {
        $requestId = $this->createReturnRequest('staff-complete-idem');

        Sanctum::actingAs($this->manager, ['staff']);
        $detail = $this->getJson('/api/v1/fulfilment/return-requests/'.$requestId, $this->storeHeaders())
            ->json('data');

        $this->patchJson(
            '/api/v1/fulfilment/return-requests/'.$requestId,
            ['status' => PortalReturnRequestStatuses::APPROVED],
            $this->storeHeaders(),
        )->assertOk();

        $headers = array_merge($this->storeHeaders(), ['Idempotency-Key' => 'complete-idem']);
        $body = [
            'refunds' => [
                [
                    'provider' => 'manual',
                    'method' => 'cash',
                    'amount' => $detail['refundTotal'],
                ],
            ],
        ];

        $this->postJson('/api/v1/fulfilment/return-requests/'.$requestId.'/complete', $body, $headers)
            ->assertCreated();
        $this->postJson('/api/v1/fulfilment/return-requests/'.$requestId.'/complete', $body, $headers)
            ->assertOk();

        $this->assertDatabaseCount('portal_refunds', 1);
    }

    public function test_complete_rejects_wrong_refund_total(): void
    {
        $requestId = $this->createReturnRequest('staff-complete-bad-total');

        Sanctum::actingAs($this->manager, ['staff']);
        $this->patchJson(
            '/api/v1/fulfilment/return-requests/'.$requestId,
            ['status' => PortalReturnRequestStatuses::APPROVED],
            $this->storeHeaders(),
        )->assertOk();

        $this->postJson(
            '/api/v1/fulfilment/return-requests/'.$requestId.'/complete',
            [
                'refunds' => [
                    ['provider' => 'manual', 'method' => 'cash', 'amount' => '0.0100'],
                ],
            ],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'complete-bad']),
        )->assertUnprocessable();
    }

    public function test_invalid_status_transition_rejected(): void
    {
        $requestId = $this->createReturnRequest('staff-invalid');

        Sanctum::actingAs($this->manager, ['staff']);
        $this->patchJson(
            '/api/v1/fulfilment/return-requests/'.$requestId,
            ['status' => PortalReturnRequestStatuses::COMPLETED],
            $this->storeHeaders(),
        )->assertUnprocessable();
    }

    public function test_portal_token_rejected_on_staff_return_requests(): void
    {
        $this->createReturnRequest('staff-portal-token');

        Sanctum::actingAs($this->account, ['portal']);
        $this->getJson('/api/v1/fulfilment/return-requests', $this->storeHeaders())
            ->assertUnauthorized();
    }

    public function test_open_only_filter(): void
    {
        $openId = $this->createReturnRequest('staff-open');
        $closedId = $this->createReturnRequest('staff-closed');

        Sanctum::actingAs($this->manager, ['staff']);
        $this->patchJson(
            '/api/v1/fulfilment/return-requests/'.$closedId,
            ['status' => PortalReturnRequestStatuses::REJECTED],
            $this->storeHeaders(),
        )->assertOk();

        $ids = collect(
            $this->getJson('/api/v1/fulfilment/return-requests?openOnly=1', $this->storeHeaders())
                ->assertOk()
                ->json('data'),
        )->pluck('id')->all();

        $this->assertContains($openId, $ids);
        $this->assertNotContains($closedId, $ids);
    }

    private function createReturnRequest(string $suffix): int
    {
        Sanctum::actingAs($this->account, ['portal']);
        $order = $this->deliveredPaidOrder('order-'.$suffix);
        $itemId = $order->items->first()->id;

        return (int) $this->withHeader('Idempotency-Key', 'return-'.$suffix)
            ->postJson('/api/v1/portal/return-requests', [
                'orderId' => $order->id,
                'reason' => 'defective',
                'notes' => 'Staff test',
                'items' => [['orderItemId' => $itemId, 'qty' => 1]],
            ])
            ->assertCreated()
            ->json('data.id');
    }

    private function deliveredPaidOrder(string $idempotencyKey): PortalOrder
    {
        $orderId = $this->placeOrder($idempotencyKey);
        $this->postJson('/api/v1/portal/orders/'.$orderId.'/confirm-payment')->assertOk();

        PortalOrder::query()->where('id', $orderId)->update([
            'status' => PortalOrderStatuses::DELIVERED,
        ]);

        return PortalOrder::query()->with('items')->findOrFail($orderId);
    }

    private function placeOrder(string $idempotencyKey): int
    {
        $this->postJson('/api/v1/portal/cart/items', [
            'variantId' => $this->variant->id,
            'qty' => 1,
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
     * @return array<string, string>
     */
    private function storeHeaders(): array
    {
        return ['X-Store-Id' => (string) $this->store->id];
    }

    private function stockQty(): string
    {
        $balance = InventoryBalance::query()
            ->where('organization_id', $this->organization->id)
            ->where('location_type', LocationType::Store->value)
            ->where('location_id', $this->store->id)
            ->where('product_variant_id', $this->variant->id)
            ->first();

        return $balance ? (string) $balance->available : '0.0000';
    }

    private function registerVerifiedPortalAccount(string $email): CustomerAccount
    {
        $register = $this->postJson('/api/v1/portal/auth/register', [
            'name' => 'Staff Returns Customer',
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
