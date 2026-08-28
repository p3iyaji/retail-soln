<?php

namespace Modules\Sales\Enums;

enum SaleStatus: string
{
    case Held = 'held';
    case Completed = 'completed';
    case Voided = 'voided';
}
