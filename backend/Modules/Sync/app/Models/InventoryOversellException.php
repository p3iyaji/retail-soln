<?php

namespace Modules\Sync\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Organization\Models\Store;
use Modules\Products\Models\ProductVariant;
use Modules\Sales\Models\Sale;
use Modules\Sync\Enums\OversellExceptionStatus;

class InventoryOversellException extends Model
{
    protected $fillable = [
        'organization_id',
        'store_id',
        'sale_id',
        'product_variant_id',
        'qty_deficit',
        'status',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OversellExceptionStatus::class,
            'approved_at' => 'datetime',
            'qty_deficit' => 'decimal:4',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
