<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\CustomerPortal\Models\CustomerAccount;
use Modules\CustomerPortal\Support\PortalOrderStatuses;
use Modules\Inventory\Data\PostMovementData;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;
use Modules\Inventory\Services\InventoryLedgerService;
use Modules\Notifications\Data\NotificationRecipient;
use Modules\Notifications\Mail\NotificationMessage;
use Modules\Notifications\Models\Notification;
use Modules\Notifications\Support\NotificationChannels;
use Modules\Notifications\Support\NotificationStatuses;
use Modules\Notifications\Support\NotificationTypes;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use Modules\Products\Models\Product;
use Modules\Products\Models\ProductVariant;
use Tests\TestCase;

class NotificationTriggersTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Store $store;

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
            'payments.portal_frontend_url' => 'http://localhost:5175',
            'payments.allowed_redirect_origins' => 'http://localhost:5175',
            'app.url' => 'http://localhost',
        ]);

        $this->store = Store::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Notify Store',
            'is_active' => true,
        ]);

        $product = Product::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Notify Widget',
            'sku' => 'SKU-NOTIFY-1',
            'selling_price' => '1500.0000',
            'reorder_level' => '5.0000',
            'is_active' => true,
        ]);

        $this->variant = ProductVariant::query()->create([
            'organization_id' => $this->organization->id,
            'product_id' => $product->id,
            'name' => 'Default',
            'sku' => 'SKU-NOTIFY-1-D',
            'price' => '1500.0000',
            'is_default' => true,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->seedStock('30.0000');

        $this->account = $this->registerVerifiedPortalAccount('notify-shopper@example.com');

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

    public function test_paid_portal_order_queues_customer_notifications_without_failing_the_pay_call(): void
    {
        Mail::fake();

        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placeOrder('notify-paid', 1, 'store_pickup');

        $this->postJson('/api/v1/portal/orders/'.$orderId.'/confirm-payment')
            ->assertOk()
            ->assertJsonPath('data.paymentStatus', 'paid');

        $rows = Notification::query()
            ->where('type', NotificationTypes::PORTAL_ORDER_PAID)
            ->where('related_type', 'portal_order')
            ->where('related_id', $orderId)
            ->get();

        $this->assertEqualsCanonicalizing(
            [NotificationChannels::EMAIL, NotificationChannels::IN_APP],
            $rows->pluck('channel')->all(),
        );

        foreach ($rows as $row) {
            $this->assertSame(NotificationStatuses::SENT, $row->status);
            $this->assertSame(NotificationRecipient::CUSTOMER_ACCOUNT, $row->recipient_type);
            $this->assertSame((int) $this->account->id, (int) $row->recipient_id);
            $this->assertSame((int) $this->store->id, (int) $row->store_id);
        }

        Mail::assertSent(
            NotificationMessage::class,
            fn (NotificationMessage $mail) => $mail->hasTo($this->account->email)
                && $mail->notification->type === NotificationTypes::PORTAL_ORDER_PAID,
        );
    }

    public function test_paid_notification_is_not_repeated_for_a_replayed_payment(): void
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placeOrder('notify-paid-replay', 1, 'store_pickup');

        $this->postJson('/api/v1/portal/orders/'.$orderId.'/confirm-payment')->assertOk();
        $this->postJson('/api/v1/portal/orders/'.$orderId.'/confirm-payment')->assertOk();

        $this->assertSame(
            2,
            Notification::query()->where('type', NotificationTypes::PORTAL_ORDER_PAID)->count(),
        );
    }

    public function test_ready_for_pickup_queues_a_customer_notification(): void
    {
        Mail::fake();

        $orderId = $this->placePayAndPack('notify-ready', 'store_pickup');

        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/ready',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'notify-ready-1']),
        )
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::READY_FOR_PICKUP);

        $rows = Notification::query()
            ->where('type', NotificationTypes::PORTAL_ORDER_READY_FOR_PICKUP)
            ->where('related_id', $orderId)
            ->get();

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            [NotificationChannels::EMAIL, NotificationChannels::IN_APP],
            $rows->pluck('channel')->all(),
        );

        Mail::assertSent(
            NotificationMessage::class,
            fn (NotificationMessage $mail) => $mail->notification->type === NotificationTypes::PORTAL_ORDER_READY_FOR_PICKUP,
        );

        // Replaying the same idempotency key must not re-notify.
        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/ready',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'notify-ready-1']),
        )->assertOk();

        $this->assertSame(
            2,
            Notification::query()
                ->where('type', NotificationTypes::PORTAL_ORDER_READY_FOR_PICKUP)
                ->count(),
        );
    }

    public function test_shipping_queues_a_customer_notification_with_tracking(): void
    {
        Mail::fake();

        $orderId = $this->placePayAndPack('notify-ship', 'home_delivery');

        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/ship',
            ['carrierName' => 'Swift Logistics', 'trackingNumber' => 'TRK-42'],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'notify-ship-1']),
        )
            ->assertOk()
            ->assertJsonPath('data.status', PortalOrderStatuses::OUT_FOR_DELIVERY);

        $row = Notification::query()
            ->where('type', NotificationTypes::PORTAL_ORDER_SHIPPED)
            ->where('channel', NotificationChannels::EMAIL)
            ->firstOrFail();

        $this->assertSame('Swift Logistics', $row->payload['carrier'] ?? null);
        $this->assertSame('TRK-42', $row->payload['tracking'] ?? null);

        Mail::assertSent(
            NotificationMessage::class,
            fn (NotificationMessage $mail) => $mail->notification->type === NotificationTypes::PORTAL_ORDER_SHIPPED,
        );
    }

    public function test_low_stock_command_creates_staff_in_app_notifications(): void
    {
        $this->drainToLowStock();

        $this->artisan('inventory:check-low-stock')->assertExitCode(0);

        $rows = Notification::query()
            ->where('type', NotificationTypes::LOW_STOCK)
            ->get();

        $this->assertGreaterThan(0, $rows->count());
        $this->assertTrue($rows->every(fn (Notification $row) => $row->channel === NotificationChannels::IN_APP));

        $managerRow = $rows->firstWhere('recipient_id', $this->manager->id);
        $this->assertNotNull($managerRow);
        $this->assertSame(NotificationRecipient::STAFF_USER, $managerRow->recipient_type);
        $this->assertSame((int) $this->store->id, (int) $managerRow->store_id);
        $this->assertStringContainsString('SKU-NOTIFY-1-D', (string) ($managerRow->payload['skus'] ?? ''));

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('meta.unreadCount', 1)
            ->assertJsonPath('data.0.type', NotificationTypes::LOW_STOCK);
    }

    public function test_low_stock_command_skips_roles_without_inventory_view(): void
    {
        $accountant = User::factory()->create(['role' => 'accountant']);
        $accountant->stores()->attach($this->store->id, ['is_default' => true]);
        $this->assertFalse($accountant->hasPermission('inventory.view'));

        $this->drainToLowStock();
        $this->artisan('inventory:check-low-stock')->assertExitCode(0);

        $this->assertSame(
            0,
            Notification::query()
                ->where('type', NotificationTypes::LOW_STOCK)
                ->where('recipient_id', $accountant->id)
                ->count(),
        );
    }

    public function test_low_stock_command_creates_nothing_when_no_alerts_exist(): void
    {
        $this->artisan('inventory:check-low-stock')->assertExitCode(0);

        $this->assertSame(0, Notification::query()->count());
    }

    public function test_low_stock_notifications_are_suppressed_within_the_cooldown_window(): void
    {
        $this->drainToLowStock();

        $this->artisan('inventory:check-low-stock')->assertExitCode(0);
        $first = Notification::query()->where('type', NotificationTypes::LOW_STOCK)->count();
        $this->assertGreaterThan(0, $first);

        $this->artisan('inventory:check-low-stock')->assertExitCode(0);
        $this->assertSame(
            $first,
            Notification::query()->where('type', NotificationTypes::LOW_STOCK)->count(),
        );

        config(['notifications.low_stock.cooldown_minutes' => 0]);
        $this->artisan('inventory:check-low-stock')->assertExitCode(0);
        $this->assertSame(
            $first * 2,
            Notification::query()->where('type', NotificationTypes::LOW_STOCK)->count(),
        );
    }

    /**
     * @return array<string, string>
     */
    private function storeHeaders(): array
    {
        return ['X-Store-Id' => (string) $this->store->id];
    }

    private function seedStock(string $qty): void
    {
        app(InventoryLedgerService::class)->post(new PostMovementData(
            organizationId: $this->organization->id,
            locationType: LocationType::Store,
            locationId: $this->store->id,
            productVariantId: $this->variant->id,
            type: MovementType::Purchase,
            quantity: $qty,
            unitCost: '500.0000',
        ));
    }

    private function drainToLowStock(): void
    {
        app(InventoryLedgerService::class)->post(new PostMovementData(
            organizationId: $this->organization->id,
            locationType: LocationType::Store,
            locationId: $this->store->id,
            productVariantId: $this->variant->id,
            type: MovementType::Adjustment,
            quantity: '-28.0000',
            notes: 'Drain to low stock',
        ));
    }

    private function placePayAndPack(string $idempotencyKey, string $mode): int
    {
        Sanctum::actingAs($this->account, ['portal']);
        $orderId = $this->placeOrder($idempotencyKey, 1, $mode);
        $this->postJson('/api/v1/portal/orders/'.$orderId.'/confirm-payment')->assertOk();

        Sanctum::actingAs($this->manager, ['staff']);
        $this->flushHeaders();
        $this->postJson(
            '/api/v1/fulfilment/orders/'.$orderId.'/pack',
            [],
            array_merge($this->storeHeaders(), ['Idempotency-Key' => 'pack-'.$idempotencyKey]),
        )->assertOk();

        return $orderId;
    }

    private function placeOrder(string $idempotencyKey, int $qty, string $mode): int
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
        ])->assertCreated()->json('data.id');
    }

    private function registerVerifiedPortalAccount(string $email): CustomerAccount
    {
        $register = $this->postJson('/api/v1/portal/auth/register', [
            'name' => 'Notify Customer',
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
