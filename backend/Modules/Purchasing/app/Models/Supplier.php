<?php

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Products\Models\Product;

class Supplier extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(SupplierContact::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(SupplierAddress::class);
    }

    public function productMappings(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
