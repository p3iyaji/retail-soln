<?php

namespace Modules\Purchasing\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Purchasing\Enums\SupplierInvoiceStatus;

class SupplierInvoice extends Model
{
    protected $fillable = [
        'organization_id',
        'supplier_id',
        'purchase_order_id',
        'goods_receipt_id',
        'number',
        'status',
        'total_amount',
        'notes',
        'created_by',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'status' => SupplierInvoiceStatus::class,
            'total_amount' => 'decimal:4',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function amountPaid(): string
    {
        $paid = '0.0000';
        $payments = $this->relationLoaded('payments')
            ? $this->payments
            : $this->payments()->get();

        foreach ($payments as $payment) {
            $paid = bcadd($paid, (string) $payment->amount, 4);
        }

        return $paid;
    }

    public function amountRemaining(): string
    {
        $remaining = bcsub((string) $this->total_amount, $this->amountPaid(), 4);

        return bccomp($remaining, '0', 4) < 0 ? '0.0000' : $remaining;
    }
}
