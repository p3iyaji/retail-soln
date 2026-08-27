<?php

namespace Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierAddress extends Model
{
    protected $fillable = [
        'organization_id',
        'supplier_id',
        'type',
        'line1',
        'line2',
        'city',
        'region',
        'postal_code',
        'country',
        'is_default',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
