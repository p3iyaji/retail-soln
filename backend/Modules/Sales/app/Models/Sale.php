<?php

namespace Modules\Sales\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Customers\Models\Customer;
use Modules\Organization\Models\Store;
use Modules\Sales\Enums\SaleStatus;

class Sale extends Model
{
    protected $fillable = [
        'organization_id',
        'store_id',
        'uuid',
        'customer_id',
        'cashier_id',
        'subtotal',
        'discount_total',
        'tax_total',
        'total',
        'status',
        'idempotency_key',
        'terminal_id',
        'session_id',
        'notes',
        'voided_at',
        'voided_by',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'total' => 'decimal:4',
            'status' => SaleStatus::class,
            'voided_at' => 'datetime',
        ];
    }

    public function voidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function cashierSession(): BelongsTo
    {
        return $this->belongsTo(CashierSession::class, 'session_id');
    }

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function saleReturns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }
}
