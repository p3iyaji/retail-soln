<?php

namespace Modules\Sales\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Customers\Models\Customer;
use Modules\Organization\Models\Store;
use Modules\Sales\Enums\ProformaInvoiceStatus;

class ProformaInvoice extends Model
{
    protected $fillable = [
        'organization_id',
        'store_id',
        'customer_id',
        'number',
        'status',
        'subtotal',
        'discount_total',
        'tax_total',
        'total',
        'valid_until',
        'notes',
        'created_by',
        'issued_by',
        'issued_at',
        'converted_sale_id',
        'converted_invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'total' => 'decimal:4',
            'valid_until' => 'date',
            'status' => ProformaInvoiceStatus::class,
            'issued_at' => 'datetime',
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

    public function items(): HasMany
    {
        return $this->hasMany(ProformaInvoiceItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function convertedSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'converted_sale_id');
    }

    public function convertedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'converted_invoice_id');
    }
}
