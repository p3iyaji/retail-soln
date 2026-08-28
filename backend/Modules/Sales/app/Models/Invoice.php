<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = [
        'organization_id',
        'sale_id',
        'invoice_number',
        'status',
        'subtotal',
        'tax_total',
        'total',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'total' => 'decimal:4',
            'issued_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }
}
