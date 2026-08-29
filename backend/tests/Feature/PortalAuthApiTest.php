<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Modules\CustomerPortal\Models\CustomerAccount;
use Modules\Customers\Enums\CustomerType;
use Modules\Customers\Models\Customer;
use Modules\Customers\Models\CustomerAddress;
use Modules\Organization\Models\Organization;
use Tests\TestCase;

class PortalAuthApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'name' => 'Portal Org',
        ]);
        config(['customer_portal.organization_id' => $this->organization->id]);
    }

    public function test_register_new_email_creates_customer_and_requires_verification(): void
    {
        $response = $this->postJson('/api/v1/portal/auth/register', [
            'name' => 'Ada Customer',
            'email' => 'ada@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ]);

        $response->assertCreated()
            ->assertJsonPath('verificationRequired', true)
            ->assertJsonPath('email', 'ada@example.com')
            ->assertJsonStructure(['verificationToken']);

        $this->assertDatabaseHas('customers', [
            'organization_id' => $this->organization->id,
            'email' => 'ada@example.com',
            'name' => 'Ada Customer',
            'type' => CustomerType::Registered->value,
        ]);

        $this->assertDatabaseHas('customer_accounts', [
            'email' => 'ada@example.com',
            'organization_id' => $this->organization->id,
        ]);

        $this->assertNull(CustomerAccount::query()->where('email', 'ada@example.com')->value('email_verified_at'));
    }

    public function test_register_duplicate_email_returns_422(): void
    {
        $this->registerVerifiedPortalAccount('dup@example.com');

        $this->postJson('/api/v1/portal/auth/register', [
            'name' => 'Other',
            'email' => 'dup@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_register_links_existing_active_crm_customer(): void
    {
        $customer = Customer::query()->create([
            'organization_id' => $this->organization->id,
            'type' => CustomerType::Registered,
            'name' => 'CRM Ada',
            'email' => 'crm@example.com',
            'account_number' => 'C-CRM-1',
            'credit_limit' => '0.0000',
            'balance' => '0.0000',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/portal/auth/register', [
            'name' => 'Portal Ada',
            'email' => 'crm@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'phone' => '+15550001111',
        ])->assertCreated()
            ->assertJsonPath('verificationRequired', true);

        $account = CustomerAccount::query()->where('email', 'crm@example.com')->firstOrFail();
        $this->assertSame($customer->id, $account->customer_id);
        $this->assertSame(1, Customer::query()->where('email', 'crm@example.com')->count());
        $this->assertSame('CRM Ada', $customer->fresh()->name);
        $this->assertNull($customer->fresh()->phone);
    }

    public function test_register_conflict_when_crm_customer_already_linked(): void
    {
        $customer = Customer::query()->create([
            'organization_id' => $this->organization->id,
            'type' => CustomerType::Registered,
            'name' => 'Linked CRM',
            'email' => 'crm-linked@example.com',
            'account_number' => 'C-LINKED',
            'credit_limit' => '0.0000',
            'balance' => '0.0000',
            'is_active' => true,
        ]);

        CustomerAccount::query()->create([
            'organization_id' => $this->organization->id,
            'customer_id' => $customer->id,
            'email' => 'different-portal@example.com',
            'password' => 'Password1!',
            'email_verified_at' => now(),
        ]);

        $this->postJson('/api/v1/portal/auth/register', [
            'name' => 'Again',
            'email' => 'crm-linked@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_register_rejects_multiple_active_crm_email_matches(): void
    {
        foreach (['C-MULTI-1', 'C-MULTI-2'] as $i => $accountNumber) {
            Customer::query()->create([
                'organization_id' => $this->organization->id,
                'type' => CustomerType::Registered,
                'name' => 'Dup '.$i,
                'email' => 'multi@example.com',
                'account_number' => $accountNumber,
                'credit_limit' => '0.0000',
                'balance' => '0.0000',
                'is_active' => true,
            ]);
        }

        $this->postJson('/api/v1/portal/auth/register', [
            'name' => 'Conflict',
            'email' => 'multi@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_unverified_login_requires_verification_without_token(): void
    {
        $this->postJson('/api/v1/portal/auth/register', [
            'name' => 'Pending',
            'email' => 'pending@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertCreated();

        $priorHash = CustomerAccount::query()->where('email', 'pending@example.com')->value('email_verification_token');

        $response = $this->postJson('/api/v1/portal/auth/login', [
            'email' => 'pending@example.com',
            'password' => 'Password1!',
        ])->assertOk()
            ->assertJsonPath('verificationRequired', true)
            ->assertJsonPath('email', 'pending@example.com')
            ->assertJsonMissingPath('token');

        $this->assertArrayNotHasKey('verificationToken', $response->json());
        $this->assertSame(
            $priorHash,
            CustomerAccount::query()->where('email', 'pending@example.com')->value('email_verification_token'),
        );
    }

    public function test_verify_already_verified_without_valid_token_does_not_issue_session(): void
    {
        $this->registerVerifiedPortalAccount('reverify@example.com');

        $this->postJson('/api/v1/portal/auth/verify', [
            'email' => 'reverify@example.com',
            'token' => 'any-garbage-token',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['token']);
    }

    public function test_login_rejects_inactive_or_missing_customer(): void
    {
        $account = $this->registerVerifiedPortalAccount('inactive@example.com');
        $account->customer->update(['is_active' => false]);

        $this->postJson('/api/v1/portal/auth/login', [
            'email' => 'inactive@example.com',
            'password' => 'Password1!',
        ])->assertUnauthorized()
            ->assertJson(['message' => 'Invalid credentials.']);
    }

    public function test_verify_then_login_issues_portal_token(): void
    {
        $register = $this->postJson('/api/v1/portal/auth/register', [
            'name' => 'Vera',
            'email' => 'vera@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ])->assertCreated();

        $token = $register->json('verificationToken');

        $verified = $this->postJson('/api/v1/portal/auth/verify', [
            'email' => 'vera@example.com',
            'token' => $token,
        ])->assertOk()
            ->assertJsonStructure(['token', 'tokenType', 'account' => ['id', 'email', 'emailVerified']]);

        $this->assertTrue($verified->json('account.emailVerified'));

        $login = $this->postJson('/api/v1/portal/auth/login', [
            'email' => 'vera@example.com',
            'password' => 'Password1!',
        ])->assertOk()
            ->assertJsonPath('tokenType', 'Bearer');

        $this->withToken($login->json('token'))
            ->getJson('/api/v1/portal/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'vera@example.com')
            ->assertJsonPath('data.customer.name', 'Vera');
    }

    public function test_login_invalid_credentials_returns_401(): void
    {
        $this->registerVerifiedPortalAccount('ok@example.com');

        $this->postJson('/api/v1/portal/auth/login', [
            'email' => 'ok@example.com',
            'password' => 'wrong-password',
        ])->assertUnauthorized()
            ->assertJson(['message' => 'Invalid credentials.']);
    }

    public function test_login_with_staff_email_only_returns_401(): void
    {
        User::factory()->create([
            'email' => 'staff-only@example.com',
            'password' => Hash::make('Password1!'),
        ]);

        $this->postJson('/api/v1/portal/auth/login', [
            'email' => 'staff-only@example.com',
            'password' => 'Password1!',
        ])->assertUnauthorized()
            ->assertJson(['message' => 'Invalid credentials.']);
    }

    public function test_portal_token_rejected_on_staff_me(): void
    {
        $account = $this->registerVerifiedPortalAccount('portal@example.com');
        $token = $account->createToken('portal', ['portal'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_staff_token_rejected_on_portal_me(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api', ['staff'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/portal/auth/me')
            ->assertUnauthorized();
    }

    public function test_logout_revokes_portal_token(): void
    {
        $account = $this->registerVerifiedPortalAccount('out@example.com');
        // Drop tokens issued by verify so we exercise logout on a known bearer.
        $account->tokens()->delete();
        $token = $account->createToken('portal', ['portal'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/portal/auth/logout')
            ->assertOk();

        $this->assertSame(0, $account->fresh()->tokens()->count());

        \Illuminate\Support\Facades\Auth::forgetGuards();

        $this->withToken($token)
            ->getJson('/api/v1/portal/auth/me')
            ->assertUnauthorized();
    }

    public function test_address_crud_is_scoped_to_own_customer(): void
    {
        $mine = $this->registerVerifiedPortalAccount('mine@example.com');
        $other = $this->registerVerifiedPortalAccount('other@example.com');

        Sanctum::actingAs($mine, ['portal']);

        $created = $this->postJson('/api/v1/portal/account/addresses', [
            'line1' => '1 Portal Way',
            'city' => 'Lagos',
            'type' => 'shipping',
            'isDefault' => true,
        ])->assertCreated()
            ->assertJsonPath('data.line1', '1 Portal Way');

        $addressId = $created->json('data.id');

        $this->getJson('/api/v1/portal/account/addresses')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->patchJson('/api/v1/portal/account/addresses/'.$addressId, [
            'city' => 'Abuja',
        ])->assertOk()
            ->assertJsonPath('data.city', 'Abuja');

        $foreign = CustomerAddress::query()->create([
            'organization_id' => $this->organization->id,
            'customer_id' => $other->customer_id,
            'type' => 'shipping',
            'line1' => 'Secret',
            'is_default' => true,
        ]);

        $this->patchJson('/api/v1/portal/account/addresses/'.$foreign->id, [
            'line1' => 'Hacked',
        ])->assertNotFound();

        $this->deleteJson('/api/v1/portal/account/addresses/'.$addressId)
            ->assertNoContent();

        $this->assertDatabaseMissing('customer_addresses', ['id' => $addressId]);
    }

    public function test_profile_update(): void
    {
        $account = $this->registerVerifiedPortalAccount('profile@example.com');
        Sanctum::actingAs($account, ['portal']);

        $this->patchJson('/api/v1/portal/account/profile', [
            'name' => 'Updated Name',
            'phone' => '+15559998888',
        ])->assertOk()
            ->assertJsonPath('data.customer.name', 'Updated Name')
            ->assertJsonPath('data.customer.phone', '+15559998888');
    }

    public function test_portal_auth_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/portal/auth/login', [
                'email' => 'ratelimit@example.com',
                'password' => 'wrong',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/v1/portal/auth/login', [
            'email' => 'ratelimit@example.com',
            'password' => 'wrong',
        ])->assertStatus(429);
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
