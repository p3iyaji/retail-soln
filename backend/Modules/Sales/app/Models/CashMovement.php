<?php

namespace Modules\Sales\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Sales\Enums\CashMovementType;

class CashMovement extends Model
{
    protected $fillable = [
        'organization_id',
        'session_id',
        'type',
        'amount',
        'reference_type',
        'reference_id',
        'user_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'type' => CashMovementType::class,
        ];
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
