<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Sales\Enums\InstallmentStatus;

class Installment extends Model
{
    protected $fillable = [
        'organization_id',
        'installment_plan_id',
        'sequence',
        'amount',
        'paid_amount',
        'due_at',
        'status',
        'paid_at',
        'payment_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'paid_amount' => 'decimal:4',
            'due_at' => 'date',
            'status' => InstallmentStatus::class,
            'paid_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(InstallmentPlan::class, 'installment_plan_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InstallmentPayment::class);
    }
}
