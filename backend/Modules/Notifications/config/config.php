<?php

use Modules\Notifications\Channels\InAppChannel;
use Modules\Notifications\Channels\LogSmsChannel;
use Modules\Notifications\Channels\MailChannel;

return [
    'name' => 'Notifications',

    /*
    |--------------------------------------------------------------------------
    | Channel adapters
    |--------------------------------------------------------------------------
    |
    | Channel key => adapter class. Adapters implement
    | Modules\Notifications\Contracts\NotificationChannel and are resolved from
    | the container, so a deployment can swap the mail or SMS transport without
    | touching domain code (AD-7).
    |
    */
    'channels' => [
        'in_app' => InAppChannel::class,
        'email' => MailChannel::class,
        'sms' => LogSmsChannel::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Channels per notification type
    |--------------------------------------------------------------------------
    |
    | Triggers name a type; this map decides which channels are attempted.
    | A channel is skipped when the recipient has no address for it (for
    | example an email channel for a staff account without an email).
    |
    */
    'type_channels' => [
        'inventory.low_stock' => ['in_app'],
        'portal_order.paid' => ['email', 'in_app'],
        'portal_order.ready_for_pickup' => ['email', 'in_app'],
        'portal_order.shipped' => ['email', 'in_app'],
    ],

    'default_channels' => ['in_app'],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Delivery always runs on a queued job dispatched after commit. Null uses
    | the default queue of the configured connection.
    |
    */
    'queue' => env('NOTIFICATIONS_QUEUE'),

    /*
    |--------------------------------------------------------------------------
    | Low-stock digest
    |--------------------------------------------------------------------------
    |
    | `inventory:check-low-stock` runs hourly. A location is only re-notified
    | once this many minutes have passed since its last low-stock notification,
    | so a persistent shortfall does not fill inboxes every hour.
    |
    */
    'low_stock' => [
        'cooldown_minutes' => (int) env('NOTIFICATIONS_LOW_STOCK_COOLDOWN', 720),
        'sample_limit' => 10,
    ],
];
