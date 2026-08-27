<?php

namespace Modules\Inventory\Enums;

enum MovementType: string
{
    case Adjustment = 'adjustment';
    case Purchase = 'purchase';
    case PurchaseReturn = 'purchase_return';
    case Sale = 'sale';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case CountVariance = 'count_variance';
    case Return = 'return';
    case Damage = 'damage';
    case Reserve = 'reserve';
    case Release = 'release';
}
