<?php

namespace Modules\Sales\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallmentPayment extends Model
{
    protected $fillable = [
        'organization_id',
        'installment_plan_id',
        'installment_id',
        'session_id',
        'user_id',
        'method',
        'provider',
        'amount',
        'reference',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(InstallmentPlan::class, 'installment_plan_id');
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(Installment::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CashierSession::class, 'session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
