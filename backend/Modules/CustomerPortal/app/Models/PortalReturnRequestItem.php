<?php

namespace Modules\CustomerPortal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalReturnRequestItem extends Model
{
    protected $fillable = [
        'portal_return_request_id',
        'portal_order_item_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
        ];
    }

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(PortalReturnRequest::class, 'portal_return_request_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(PortalOrderItem::class, 'portal_order_item_id');
    }
}
