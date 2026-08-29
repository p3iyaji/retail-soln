<?php

namespace Modules\Notifications\Channels;

use Illuminate\Support\Facades\Mail;
use Modules\Notifications\Contracts\NotificationChannel;
use Modules\Notifications\Mail\NotificationMessage;
use Modules\Notifications\Models\Notification;

/**
 * Laravel Mail transport. The mailer itself is swappable through
 * `config('mail.default')` (log/array in dev and tests, SMTP in production).
 */
class MailChannel implements NotificationChannel
{
    public function supports(Notification $notification): bool
    {
        $address = $notification->recipient_address;

        return is_string($address)
            && filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function send(Notification $notification): void
    {
        Mail::to($notification->recipient_address, $notification->recipient_name)
            ->send(new NotificationMessage($notification));
    }
}
