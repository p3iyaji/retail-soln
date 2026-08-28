<?php

namespace Modules\Sales\Enums;

enum CashMovementType: string
{
    case CashIn = 'cash_in';
    case CashOut = 'cash_out';
    case SaleCash = 'sale_cash';
    case RefundCash = 'refund_cash';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
