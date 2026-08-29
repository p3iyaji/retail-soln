<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ObservabilityListenersTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_failure_emits_structured_log(): void
    {
        Log::spy();

        $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->shouldReceive('getQueue')->andReturn('default');
        $job->shouldReceive('resolveName')->andReturn('Tests\\Jobs\\ExampleFailingJob');

        Event::dispatch(new JobFailed('sync', $job, new RuntimeException('job failed for observability test')));

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'queue.job_failed'
                    && ($context['job'] ?? '') === 'Tests\\Jobs\\ExampleFailingJob'
                    && str_contains((string) ($context['exception'] ?? ''), 'job failed for observability test');
            });
    }

    public function test_observability_config_is_registered(): void
    {
        $this->assertIsArray(config('observability.slow_query'));
        $this->assertIsArray(config('observability.metrics'));
        $this->assertArrayHasKey('dsn', config('sentry'));
    }
}
