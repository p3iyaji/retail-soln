<?php

namespace Modules\Sales\Enums;

enum SaleReturnReason: string
{
    case Defective = 'defective';
    case WrongItem = 'wrong_item';
    case ChangedMind = 'changed_mind';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
