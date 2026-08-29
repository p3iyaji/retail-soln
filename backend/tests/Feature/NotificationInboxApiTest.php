<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Modules\Authentication\Database\Seeders\PermissionMatrixSeeder;
use Modules\CustomerPortal\Models\CustomerAccount;
use Modules\Customers\Enums\CustomerType;
use Modules\Customers\Models\Customer;
use Modules\Notifications\Channels\MailChannel;
use Modules\Notifications\Contracts\NotificationChannel;
use Modules\Notifications\Data\NotificationRecipient;
use Modules\Notifications\Mail\NotificationMessage;
use Modules\Notifications\Models\Notification;
use Modules\Notifications\Services\NotificationService;
use Modules\Notifications\Support\NotificationStatuses;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;
use Modules\Organization\Services\OrganizationService;
use RuntimeException;
use Tests\TestCase;

class NotificationInboxApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Store $store;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionMatrixSeeder::class);

        $this->organization = app(OrganizationService::class)->current();
        $this->store = Store::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Inbox Store',
        ]);

        $this->manager = User::factory()->storeManager()->create();
        $this->manager->stores()->attach($this->store->id, ['is_default' => true]);
    }

    private function notifications(): NotificationService
    {
        return app(NotificationService::class);
    }

    /**
     * @param  list<string>|null  $channels
     * @return list<Notification>
     */
    private function notifyStaff(User $user, string $subject = 'Heads up', ?array $channels = ['in_app']): array
    {
        return $this->notifications()->notify(
            type: 'test.staff',
            organizationId: (int) $this->organization->id,
            recipients: [NotificationRecipient::staff((int) $user->id, $user->email, $user->name)],
            subject: $subject,
            body: 'Something needs your attention.',
            storeId: (int) $this->store->id,
            payload: ['detail' => 'value'],
            channels: $channels,
        );
    }

    public function test_store_manager_lists_own_inbox_with_unread_count(): void
    {
        $this->notifyStaff($this->manager, 'First alert');
        $this->notifyStaff($this->manager, 'Second alert');

        $other = User::factory()->storeManager()->create();
        $other->stores()->attach($this->store->id, ['is_default' => true]);
        $this->notifyStaff($other, 'Not yours');

        Sanctum::actingAs($this->manager, ['staff']);

        $response = $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.unreadCount', 2)
            ->assertJsonPath('data.0.subject', 'Second alert')
            ->assertJsonPath('data.0.status', NotificationStatuses::SENT)
            ->assertJsonPath('data.0.readAt', null)
            ->assertJsonPath('data.0.payload.detail', 'value');

        $subjects = collect($response->json('data'))->pluck('subject')->all();
        $this->assertNotContains('Not yours', $subjects);
    }

    public function test_mark_read_sets_read_at_and_is_idempotent(): void
    {
        $notification = $this->notifyStaff($this->manager)[0];

        Sanctum::actingAs($this->manager, ['staff']);

        $this->patchJson('/api/v1/notifications/'.$notification->id.'/read')
            ->assertOk()
            ->assertJsonPath('data.id', $notification->id);

        $readAt = Notification::query()->findOrFail($notification->id)->read_at;
        $this->assertNotNull($readAt);

        $this->patchJson('/api/v1/notifications/'.$notification->id.'/read')->assertOk();
        $this->assertTrue(
            $readAt->equalTo(Notification::query()->findOrFail($notification->id)->read_at),
        );

        $this->getJson('/api/v1/notifications?status=unread')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson('/api/v1/notifications?status=read')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_mark_read_rejects_another_users_notification(): void
    {
        $other = User::factory()->storeManager()->create();
        $other->stores()->attach($this->store->id, ['is_default' => true]);
        $notification = $this->notifyStaff($other)[0];

        Sanctum::actingAs($this->manager, ['staff']);

        $this->patchJson('/api/v1/notifications/'.$notification->id.'/read')->assertNotFound();
        $this->assertNull(Notification::query()->findOrFail($notification->id)->read_at);
    }

    public function test_mark_read_rejects_another_organizations_notification(): void
    {
        $otherOrg = Organization::query()->create([
            'name' => 'Other Org',
            'default_currency' => 'NGN',
            'timezone' => 'UTC',
        ]);

        $notification = Notification::query()->create([
            'organization_id' => $otherOrg->id,
            'type' => 'test.staff',
            'channel' => 'in_app',
            'status' => NotificationStatuses::SENT,
            'recipient_type' => NotificationRecipient::STAFF_USER,
            'recipient_id' => $this->manager->id,
            'subject' => 'Cross-org',
            'body' => 'Should not be visible',
        ]);

        Sanctum::actingAs($this->manager, ['staff']);

        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('meta.total', 0);
        $this->patchJson('/api/v1/notifications/'.$notification->id.'/read')->assertNotFound();
    }

    public function test_inbox_requires_notifications_view_permission(): void
    {
        $cashier = User::factory()->cashier()->create();
        $cashier->stores()->attach($this->store->id, ['is_default' => true]);
        $notification = $this->notifyStaff($cashier)[0];

        Sanctum::actingAs($cashier, ['staff']);

        $this->getJson('/api/v1/notifications')->assertForbidden();
        $this->patchJson('/api/v1/notifications/'.$notification->id.'/read')->assertForbidden();
    }

    public function test_inbox_rejects_guests_and_portal_tokens(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();

        $customer = Customer::query()->create([
            'organization_id' => $this->organization->id,
            'type' => CustomerType::Registered,
            'name' => 'Portal Shopper',
            'account_number' => 'CUST-INBOX-1',
            'is_active' => true,
        ]);

        $account = CustomerAccount::query()->create([
            'organization_id' => $this->organization->id,
            'customer_id' => $customer->id,
            'email' => 'inbox-shopper@example.com',
            'password' => 'password',
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($account, ['portal']);
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
    }

    public function test_notifications_view_is_seeded_for_expected_roles(): void
    {
        $this->assertTrue(User::factory()->orgAdmin()->create()->hasPermission('notifications.view'));
        $this->assertTrue(User::factory()->storeManager()->create()->hasPermission('notifications.view'));
        $this->assertTrue(User::factory()->inventoryManager()->create()->hasPermission('notifications.view'));
        $this->assertFalse(User::factory()->cashier()->create()->hasPermission('notifications.view'));
    }

    public function test_email_channel_sends_a_mailable_and_marks_the_row_sent(): void
    {
        Mail::fake();

        $notification = $this->notifyStaff($this->manager, 'Emailed alert', channels: ['email'])[0];

        Mail::assertSent(
            NotificationMessage::class,
            fn (NotificationMessage $mail) => $mail->hasTo($this->manager->email)
                && $mail->notification->is($notification),
        );

        $this->assertSame(
            NotificationStatuses::SENT,
            Notification::query()->findOrFail($notification->id)->status,
        );
    }

    public function test_delivery_is_deferred_until_the_transaction_commits(): void
    {
        Mail::fake();

        DB::transaction(function (): void {
            $this->notifyStaff($this->manager, 'Committed alert', channels: ['email']);
            Mail::assertNothingSent();
        });

        Mail::assertSent(NotificationMessage::class);
    }

    public function test_rolled_back_work_leaves_no_notification_or_delivery(): void
    {
        Mail::fake();

        try {
            DB::transaction(function (): void {
                $this->notifyStaff($this->manager, 'Doomed alert', channels: ['email']);
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            // expected
        }

        Mail::assertNothingSent();
        $this->assertSame(0, Notification::query()->count());
    }

    public function test_email_channel_is_skipped_when_the_recipient_has_no_address(): void
    {
        Mail::fake();

        $created = $this->notifications()->notify(
            type: 'test.staff',
            organizationId: (int) $this->organization->id,
            recipients: [new NotificationRecipient(NotificationRecipient::STAFF_USER, (int) $this->manager->id)],
            subject: 'No address',
            body: 'Nowhere to send this.',
            channels: ['email'],
        );

        $this->assertSame([], $created);
        Mail::assertNothingSent();
        $this->assertSame(0, Notification::query()->count());
    }

    public function test_sms_channel_stub_records_the_row_without_sending_mail(): void
    {
        Mail::fake();

        $created = $this->notifications()->notify(
            type: 'test.customer',
            organizationId: (int) $this->organization->id,
            recipients: [NotificationRecipient::customerAccount(1, 'shopper@example.com', '+2348000000000', 'Shopper')],
            subject: 'Texted alert',
            body: 'Your order is ready.',
            channels: ['sms'],
        );

        $this->assertCount(1, $created);
        Mail::assertNothingSent();

        $row = Notification::query()->findOrFail($created[0]->id);
        $this->assertSame('sms', $row->channel);
        $this->assertSame('+2348000000000', $row->recipient_address);
        $this->assertSame(NotificationStatuses::SENT, $row->status);
    }

    public function test_transport_failure_marks_the_row_failed_without_throwing(): void
    {
        $this->app->bind(MailChannel::class, fn () => new class implements NotificationChannel
        {
            public function supports(Notification $notification): bool
            {
                return true;
            }

            public function send(Notification $notification): void
            {
                throw new RuntimeException('smtp is down');
            }
        });

        $created = $this->notifyStaff($this->manager, 'Broken transport', channels: ['email']);

        $row = Notification::query()->findOrFail($created[0]->id);
        $this->assertSame(NotificationStatuses::FAILED, $row->status);
        $this->assertStringContainsString('smtp is down', (string) $row->error);
        $this->assertNotNull($row->failed_at);
    }
}
