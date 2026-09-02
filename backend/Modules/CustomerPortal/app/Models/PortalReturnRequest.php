<?php

namespace Modules\CustomerPortal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PortalReturnRequest extends Model
{
    protected $fillable = [
        'organization_id',
        'customer_account_id',
        'portal_order_id',
        'status',
        'reason',
        'notes',
        'idempotency_key',
        'request_fingerprint',
        'refund_total',
        'completed_at',
        'completed_by',
        'complete_idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'request_fingerprint' => 'array',
            'refund_total' => 'decimal:4',
            'completed_at' => 'datetime',
        ];
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PortalRefund::class, 'portal_return_request_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'completed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PortalReturnRequestItem::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PortalOrder::class, 'portal_order_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class, 'customer_account_id');
    }
}
