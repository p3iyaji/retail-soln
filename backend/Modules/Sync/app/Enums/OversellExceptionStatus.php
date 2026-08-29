<?php

namespace Modules\Sync\Enums;

enum OversellExceptionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Resolved = 'resolved';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
