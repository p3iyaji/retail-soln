<?php

namespace App\Providers;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class ObservabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerSlowQueryLogging();
        $this->registerQueueFailureLogging();
    }

    private function registerSlowQueryLogging(): void
    {
        if (! config('observability.slow_query.enabled', true)) {
            return;
        }

        $threshold = (int) config('observability.slow_query.threshold_ms', 500);

        DB::listen(function ($query) use ($threshold): void {
            if ($query->time < $threshold) {
                return;
            }

            Log::warning('db.slow_query', [
                'duration_ms' => $query->time,
                'connection' => $query->connectionName,
                'sql' => Str::limit($query->sql, 500),
            ]);
        });
    }

    private function registerQueueFailureLogging(): void
    {
        Queue::failing(function (JobFailed $event): void {
            Log::error('queue.job_failed', [
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'job' => $event->job->resolveName(),
                'exception' => $event->exception->getMessage(),
            ]);

            if (app()->bound('sentry') && config('sentry.dsn')) {
                \Sentry\captureException($event->exception);
            }
        });
    }
}
