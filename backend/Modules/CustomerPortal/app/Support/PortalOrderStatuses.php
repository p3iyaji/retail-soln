<?php

namespace Modules\CustomerPortal\Support;

final class PortalOrderStatuses
{
    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const PROCESSING = 'processing';

    public const READY_FOR_PICKUP = 'ready_for_pickup';

    public const OUT_FOR_DELIVERY = 'out_for_delivery';

    public const DELIVERED = 'delivered';

    public const CANCELLED = 'cancelled';

    public const RETURNED = 'returned';

    public const REFUNDED = 'refunded';

    /** @var list<string> */
    public const ALL = [
        self::PENDING,
        self::CONFIRMED,
        self::PROCESSING,
        self::READY_FOR_PICKUP,
        self::OUT_FOR_DELIVERY,
        self::DELIVERED,
        self::CANCELLED,
        self::RETURNED,
        self::REFUNDED,
    ];

    /** @var list<string> */
    public const TERMINAL = [
        self::CANCELLED,
        self::RETURNED,
        self::REFUNDED,
    ];

    public static function label(string $status): string
    {
        return match ($status) {
            self::PENDING => 'Pending',
            self::CONFIRMED => 'Confirmed',
            self::PROCESSING => 'Processing',
            self::READY_FOR_PICKUP => 'Ready for Pickup',
            self::OUT_FOR_DELIVERY => 'Out for Delivery',
            self::DELIVERED => 'Delivered',
            self::CANCELLED => 'Cancelled',
            self::RETURNED => 'Returned',
            self::REFUNDED => 'Refunded',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }
}
