<?php

namespace Modules\Products\Policies;

use App\Models\User;
use Modules\Products\Models\Product;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('products.view');
    }

    public function view(User $user, Product $product): bool
    {
        return $user->hasPermission('products.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('products.manage');
    }

    public function update(User $user, Product $product): bool
    {
        return $user->hasPermission('products.manage');
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->hasPermission('products.manage');
    }
}
