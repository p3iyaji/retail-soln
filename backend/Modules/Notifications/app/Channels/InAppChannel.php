<?php

namespace Modules\Notifications\Channels;

use Modules\Notifications\Contracts\NotificationChannel;
use Modules\Notifications\Models\Notification;

/**
 * The persisted row is the delivery: the inbox reads it back. Delivery only
 * flips the row to sent so unread/read state is meaningful.
 */
class InAppChannel implements NotificationChannel
{
    public function supports(Notification $notification): bool
    {
        return $notification->recipient_id !== null;
    }

    public function send(Notification $notification): void
    {
        // No transport: the row itself is what the recipient reads.
    }
}
