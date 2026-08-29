<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Customers\Models\Customer;
use Modules\Organization\Models\Store;
use Modules\Sales\Enums\InstallmentPlanMode;
use Modules\Sales\Enums\InstallmentPlanStatus;

class InstallmentPlan extends Model
{
    protected $fillable = [
        'organization_id',
        'store_id',
        'customer_id',
        'sale_id',
        'invoice_id',
        'principal',
        'fee_total',
        'mode',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'principal' => 'decimal:4',
            'fee_total' => 'decimal:4',
            'mode' => InstallmentPlanMode::class,
            'status' => InstallmentPlanStatus::class,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class)->orderBy('sequence');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InstallmentPayment::class);
    }
}
