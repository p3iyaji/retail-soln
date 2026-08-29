<?php

namespace Modules\Notifications\Support;

/**
 * Trigger types wired in Epic 8.3. Types are free-form strings on the table;
 * these constants keep the wired triggers and the channel map in step.
 */
final class NotificationTypes
{
    public const LOW_STOCK = 'inventory.low_stock';

    public const PORTAL_ORDER_PAID = 'portal_order.paid';

    public const PORTAL_ORDER_READY_FOR_PICKUP = 'portal_order.ready_for_pickup';

    public const PORTAL_ORDER_SHIPPED = 'portal_order.shipped';
}
