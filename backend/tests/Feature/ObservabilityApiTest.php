<?php

namespace Tests\Feature;

use App\Support\Observability\PrometheusRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ObservabilityApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PrometheusRegistry::reset();
    }

    public function test_api_responses_include_request_correlation_header(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk();
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
    }

    public function test_api_propagates_incoming_request_id(): void
    {
        $response = $this->withHeader('X-Request-Id', 'corr-test-123')
            ->getJson('/api/v1/health');

        $response->assertOk();
        $this->assertSame('corr-test-123', $response->headers->get('X-Request-Id'));
    }

    public function test_ready_endpoint_reports_dependency_health(): void
    {
        $this->getJson('/api/v1/health/ready')
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('checks.database', true)
            ->assertJsonPath('checks.redis', true);
    }

    public function test_ready_endpoint_returns_503_when_redis_unavailable(): void
    {
        Redis::shouldReceive('connection')->andThrow(new \RuntimeException('redis down'));

        $this->getJson('/api/v1/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.redis', false);
    }

    public function test_metrics_endpoint_is_hidden_when_disabled(): void
    {
        config(['observability.metrics.enabled' => false]);

        $this->getJson('/api/v1/metrics')->assertNotFound();
    }

    public function test_metrics_endpoint_requires_token_when_configured(): void
    {
        config([
            'observability.metrics.enabled' => true,
            'observability.metrics.token' => 'metrics-secret',
        ]);

        $this->get('/api/v1/metrics')->assertUnauthorized();

        $this->withToken('metrics-secret')
            ->get('/api/v1/metrics')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
    }

    public function test_metrics_records_http_requests_when_enabled(): void
    {
        config([
            'observability.metrics.enabled' => true,
            'observability.metrics.token' => '',
        ]);

        $this->getJson('/api/v1/health')->assertOk();

        $body = $this->withToken('')
            ->get('/api/v1/metrics')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('http_requests_total', (string) $body);
    }
}
