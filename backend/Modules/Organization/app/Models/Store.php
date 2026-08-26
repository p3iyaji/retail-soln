<?php

namespace Modules\Organization\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Organization\Database\Factories\StoreFactory;

class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'postal_code',
        'country',
        'currency',
        'tax_config',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'tax_config' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): StoreFactory
    {
        return StoreFactory::new();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }
}
