<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Fortify;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_sanctum_token_for_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'cashier@example.com',
            'password' => Hash::make('secret'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'cashier@example.com',
            'password' => 'secret',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'token',
                'tokenType',
                'user' => ['id', 'email', 'roles', 'storeAccess'],
            ])
            ->assertJsonPath('tokenType', 'Bearer')
            ->assertJsonPath('user.email', $user->email);

        $this->assertNotEmpty($user->fresh()?->tokens);
    }

    public function test_login_returns_401_for_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'cashier@example.com',
            'password' => Hash::make('secret'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'cashier@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertUnauthorized()
            ->assertJson([
                'message' => 'Invalid credentials.',
            ]);
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create([
            'email' => 'limited@example.com',
            'password' => Hash::make('secret'),
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'limited@example.com',
                'password' => 'wrong-password',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'limited@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_logout_revokes_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);

        Auth::forgetGuards();

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_me_returns_authenticated_user_payload(): void
    {
        $user = User::factory()->orgAdmin()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.roles', ['org_admin'])
            ->assertJsonPath('data.storeAccess', []);
    }

    public function test_org_admin_can_enable_and_confirm_two_factor_authentication(): void
    {
        $user = User::factory()->orgAdmin()->create();
        Sanctum::actingAs($user);

        $enableResponse = $this->postJson('/api/v1/auth/two-factor/enable');

        $enableResponse->assertOk()
            ->assertJsonStructure(['qrCodeSvg', 'secretKey']);

        $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $this->postJson('/api/v1/auth/two-factor/confirm', [
            'code' => $code,
        ])->assertOk();

        $this->assertTrue($user->fresh()->hasEnabledTwoFactorAuthentication());
    }

    public function test_cashier_cannot_enable_two_factor_authentication(): void
    {
        $user = User::factory()->create(['role' => 'cashier']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/two-factor/enable')
            ->assertForbidden();
    }

    public function test_login_requires_two_factor_challenge_when_enabled(): void
    {
        $user = User::factory()->orgAdmin()->create([
            'email' => 'admin@example.com',
            'password' => Hash::make('secret'),
        ]);

        Sanctum::actingAs($user);
        $this->postJson('/api/v1/auth/two-factor/enable')->assertOk();

        $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        $this->postJson('/api/v1/auth/two-factor/confirm', ['code' => $code])->assertOk();

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'secret',
        ]);

        $loginResponse->assertOk()
            ->assertJson([
                'twoFactorRequired' => true,
            ])
            ->assertJsonStructure(['challengeToken']);

        $challengeToken = $loginResponse->json('challengeToken');
        $recoveryCode = $user->fresh()->recoveryCodes()[0];

        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'challengeToken' => $challengeToken,
            'code' => $recoveryCode,
        ])->assertOk()
            ->assertJsonStructure(['token', 'user']);
    }
}
