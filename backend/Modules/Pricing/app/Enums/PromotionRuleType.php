<?php

namespace Modules\Pricing\Enums;

enum PromotionRuleType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';
    case Bogo = 'bogo';
}
