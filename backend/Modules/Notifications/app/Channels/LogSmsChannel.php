<?php

namespace Modules\Notifications\Channels;

use Illuminate\Support\Facades\Log;
use Modules\Notifications\Contracts\NotificationChannel;
use Modules\Notifications\Models\Notification;

/**
 * Stub SMS transport. No provider is wired in MVP — swap the `sms` entry in
 * config/notifications.php for a real adapter when credentials exist.
 */
class LogSmsChannel implements NotificationChannel
{
    public function supports(Notification $notification): bool
    {
        return $notification->recipient_address !== null && $notification->recipient_address !== '';
    }

    public function send(Notification $notification): void
    {
        Log::info('notifications.sms_stub', [
            'notificationId' => $notification->id,
            'type' => $notification->type,
            'to' => $notification->recipient_address,
            'subject' => $notification->subject,
        ]);
    }
}
