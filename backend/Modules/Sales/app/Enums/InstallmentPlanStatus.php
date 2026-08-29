<?php

namespace Modules\Sales\Enums;

enum InstallmentPlanStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
