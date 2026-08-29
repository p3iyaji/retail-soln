<?php

namespace Modules\CustomerPortal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Customers\Models\Customer;
use Modules\Customers\Models\CustomerAddress;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;

class PortalOrder extends Model
{
    protected $fillable = [
        'organization_id',
        'customer_account_id',
        'customer_id',
        'uuid',
        'order_number',
        'status',
        'payment_status',
        'fulfilment_mode',
        'store_id',
        'customer_address_id',
        'contact_phone',
        'subtotal',
        'discount_total',
        'tax_total',
        'delivery_fee',
        'total',
        'payment_provider',
        'payment_reference',
        'payment_redirect_url',
        'paid_at',
        'packed_at',
        'pack_idempotency_key',
        'ready_idempotency_key',
        'collect_idempotency_key',
        'collected_at',
        'idempotency_key',
        'request_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'delivery_fee' => 'decimal:4',
            'total' => 'decimal:4',
            'paid_at' => 'datetime',
            'packed_at' => 'datetime',
            'collected_at' => 'datetime',
            'request_fingerprint' => 'array',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PortalOrderItem::class);
    }

    public function returnRequests(): HasMany
    {
        return $this->hasMany(PortalReturnRequest::class);
    }

    public function shipment(): HasOne
    {
        return $this->hasOne(PortalShipment::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class, 'customer_account_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(CustomerAddress::class, 'customer_address_id');
    }
}
