<?php

namespace Modules\Sales\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Bank = 'bank';
    case Mobile = 'mobile';
    case StoreCredit = 'store_credit';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
