<?php

namespace Modules\Customers\Enums;

enum CustomerType: string
{
    case WalkIn = 'walk_in';
    case Registered = 'registered';
    case Business = 'business';
    case Corporate = 'corporate';
}
