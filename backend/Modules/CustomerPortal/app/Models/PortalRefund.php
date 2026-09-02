<?php

namespace Modules\CustomerPortal\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortalRefund extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'organization_id',
        'portal_return_request_id',
        'portal_order_id',
        'provider',
        'method',
        'amount',
        'status',
        'reference',
        'gateway_reference',
        'processed_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
        ];
    }

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(PortalReturnRequest::class, 'portal_return_request_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PortalOrder::class, 'portal_order_id');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
