<?php

namespace Modules\CustomerPortal\Support;

final class PortalReturnRequestStatuses
{
    public const REQUESTED = 'requested';

    public const UNDER_REVIEW = 'under_review';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const COMPLETED = 'completed';

    /** @var list<string> */
    public const ALL = [
        self::REQUESTED,
        self::UNDER_REVIEW,
        self::APPROVED,
        self::REJECTED,
        self::COMPLETED,
    ];

    /** Open requests that block a new request on the same order. */
    /** @var list<string> */
    public const OPEN = [
        self::REQUESTED,
        self::UNDER_REVIEW,
    ];

    /** Statuses whose line qtys count against remaining returnable qty. */
    /** @var list<string> */
    public const COUNTS_TOWARD_RETURNED_QTY = [
        self::REQUESTED,
        self::UNDER_REVIEW,
        self::APPROVED,
        self::COMPLETED,
    ];

    public static function label(string $status): string
    {
        return match ($status) {
            self::REQUESTED => 'Requested',
            self::UNDER_REVIEW => 'Under review',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
            self::COMPLETED => 'Completed',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    public static function isOpen(string $status): bool
    {
        return in_array($status, self::OPEN, true);
    }
}
