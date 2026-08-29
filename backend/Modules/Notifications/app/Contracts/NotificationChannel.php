<?php

namespace Modules\Notifications\Contracts;

use Modules\Notifications\Models\Notification;

interface NotificationChannel
{
    /**
     * True when this notification has everything the transport needs
     * (for example an email address). Unsupported rows are never created.
     */
    public function supports(Notification $notification): bool;

    /**
     * Deliver the notification. Throwing marks the row failed; the
     * originating business transaction is already committed either way.
     */
    public function send(Notification $notification): void;
}
