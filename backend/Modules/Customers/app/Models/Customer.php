<?php

namespace Modules\Customers\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Customers\Enums\CustomerType;

class Customer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'customer_group_id',
        'type',
        'name',
        'phone',
        'email',
        'account_number',
        'credit_limit',
        'balance',
        'loyalty_ref',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
            'is_walk_in' => 'boolean',
            'is_active' => 'boolean',
            'credit_limit' => 'decimal:4',
            'balance' => 'decimal:4',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(\Modules\Organization\Models\Organization::class);
    }
}
