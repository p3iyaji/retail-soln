<?php

namespace Modules\Customers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerGroup extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'code',
    ];

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(\Modules\Organization\Models\Organization::class);
    }
}
