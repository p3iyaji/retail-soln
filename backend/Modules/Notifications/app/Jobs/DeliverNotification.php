<?php

namespace Modules\Notifications\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Notifications\Models\Notification;
use Modules\Notifications\Services\NotificationChannelRegistry;
use Modules\Notifications\Support\NotificationStatuses;
use Throwable;

class DeliverNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $notificationId)
    {
        // Never deliver against a business transaction that may still roll back (AD-7).
        $this->afterCommit();
    }

    public function handle(NotificationChannelRegistry $channels): void
    {
        $notification = Notification::query()->find($this->notificationId);

        if ($notification === null || $notification->status === NotificationStatuses::SENT) {
            return;
        }

        try {
            $channels->get($notification->channel)->send($notification);
            $notification->markSent();
        } catch (Throwable $e) {
            // Delivery is best-effort: record the failure instead of rethrowing so a
            // sync-queue deployment can never surface a transport error to the
            // checkout / fulfilment / low-stock caller.
            $notification->markFailed($e->getMessage());

            Log::warning('notifications.delivery_failed', [
                'notificationId' => $notification->id,
                'type' => $notification->type,
                'channel' => $notification->channel,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
