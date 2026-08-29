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
    ];

    protected function casts(): array
    {
        return [
            'request_fingerprint' => 'array',
        ];
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
