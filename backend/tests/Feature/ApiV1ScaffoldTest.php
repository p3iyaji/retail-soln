<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiV1ScaffoldTest extends TestCase
{
    use RefreshDatabase;
    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'service' => 'retail-soln',
            ]);
    }

    public function test_protected_route_requires_sanctum_token(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertUnauthorized();
    }

    public function test_protected_route_accepts_sanctum_user(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_organization_module_health_is_registered(): void
    {
        $response = $this->getJson('/api/v1/organization/health');

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'module' => 'organization',
            ]);
    }

    public function test_authentication_module_health_is_registered(): void
    {
        $response = $this->getJson('/api/v1/authentication/health');

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'module' => 'authentication',
            ]);
    }
}
