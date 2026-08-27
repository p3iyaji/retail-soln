<?php

namespace Modules\Products\Models;

/**
 * Thin alias so existing Products relations/imports keep resolving against the
 * same `suppliers` table owned by the Purchasing module.
 */
class Supplier extends \Modules\Purchasing\Models\Supplier
{
}
