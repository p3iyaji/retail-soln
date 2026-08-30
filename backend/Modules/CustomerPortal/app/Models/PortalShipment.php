<?php

namespace Modules\CustomerPortal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Organization\Models\Organization;

class PortalShipment extends Model
{
    protected $fillable = [
        'organization_id',
        'portal_order_id',
        'carrier_id',
        'carrier_name',
        'tracking_number',
        'shipped_at',
        'delivered_at',
        'ship_idempotency_key',
        'deliver_idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PortalOrder::class, 'portal_order_id');
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
