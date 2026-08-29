<?php

namespace Tests\Feature;

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiTransportSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_responses_carry_baseline_security_headers(): void
    {
        $response = $this->getJson('/api/v1/health')->assertOk();

        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        $this->assertNull($response->headers->get('Strict-Transport-Security'));
    }

    public function test_hsts_is_emitted_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $response = $this->getJson('/api/v1/health')->assertOk();

        $this->assertSame(
            'max-age=31536000; includeSubDomains',
            $response->headers->get('Strict-Transport-Security'),
        );
    }

    public function test_production_boot_forces_https_urls(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        (new AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('https://', URL::to('/api/v1/health'));
    }

    public function test_api_requests_are_throttled_while_health_stays_reachable(): void
    {
        config(['security.rate_limits.api' => 2]);
        Sanctum::actingAs(User::factory()->create(), ['staff']);

        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->getJson('/api/v1/auth/me')->assertStatus(429);

        $this->getJson('/api/v1/health')->assertOk();
    }
}
