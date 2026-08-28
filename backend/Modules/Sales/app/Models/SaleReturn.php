<?php

namespace Modules\Sales\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Organization\Models\Store;
use Modules\Sales\Enums\SaleReturnReason;

class SaleReturn extends Model
{
    protected $fillable = [
        'organization_id',
        'store_id',
        'sale_id',
        'uuid',
        'processed_by',
        'session_id',
        'reason',
        'total',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:4',
            'reason' => SaleReturnReason::class,
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function processedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function cashierSession(): BelongsTo
    {
        return $this->belongsTo(CashierSession::class, 'session_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
