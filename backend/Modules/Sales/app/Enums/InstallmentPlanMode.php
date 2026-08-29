<?php

namespace Modules\Sales\Enums;

enum InstallmentPlanMode: string
{
    case PayOverTime = 'pay_over_time';
    case Layaway = 'layaway';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
