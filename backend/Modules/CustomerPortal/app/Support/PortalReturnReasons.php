<?php

namespace Modules\CustomerPortal\Support;

/**
 * Aligns with POS SaleReturnReason vocabulary without coupling to Sales.
 */
final class PortalReturnReasons
{
    public const DEFECTIVE = 'defective';

    public const WRONG_ITEM = 'wrong_item';

    public const CHANGED_MIND = 'changed_mind';

    public const OTHER = 'other';

    /** @var list<string> */
    public const ALL = [
        self::DEFECTIVE,
        self::WRONG_ITEM,
        self::CHANGED_MIND,
        self::OTHER,
    ];

    public static function label(string $reason): string
    {
        return match ($reason) {
            self::DEFECTIVE => 'Defective',
            self::WRONG_ITEM => 'Wrong item',
            self::CHANGED_MIND => 'Changed mind',
            self::OTHER => 'Other',
            default => ucfirst(str_replace('_', ' ', $reason)),
        };
    }
}
