<?php

namespace Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockCount extends Model
{
    protected $fillable = [
        'organization_id',
        'location_type',
        'location_id',
        'status',
        'user_id',
        'notes',
        'counted_at',
    ];

    protected function casts(): array
    {
        return [
            'counted_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockCountItem::class);
    }
}
