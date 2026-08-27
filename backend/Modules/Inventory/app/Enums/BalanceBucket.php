<?php

namespace Modules\Inventory\Enums;

enum BalanceBucket: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Damaged = 'damaged';
    case InTransit = 'in_transit';
}
