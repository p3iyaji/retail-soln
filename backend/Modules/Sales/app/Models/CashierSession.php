<?php

namespace Modules\Sales\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Organization\Models\Store;
use Modules\Sales\Enums\CashierSessionStatus;

class CashierSession extends Model
{
    protected $fillable = [
        'organization_id',
        'store_id',
        'terminal_id',
        'user_id',
        'status',
        'opening_float',
        'expected_cash',
        'closing_cash',
        'variance',
        'opened_at',
        'closed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'opening_float' => 'decimal:4',
            'expected_cash' => 'decimal:4',
            'closing_cash' => 'decimal:4',
            'variance' => 'decimal:4',
            'status' => CashierSessionStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class, 'session_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'session_id');
    }
}
