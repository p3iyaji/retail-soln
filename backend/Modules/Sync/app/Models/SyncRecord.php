<?php

namespace Modules\Sync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Sales\Models\Sale;
use Modules\Sync\Enums\SyncRecordStatus;

class SyncRecord extends Model
{
    protected $fillable = [
        'organization_id',
        'sync_batch_id',
        'uuid',
        'local_sequence',
        'payload_type',
        'status',
        'sale_id',
        'exception_id',
        'errors',
        'server_result',
    ];

    protected function casts(): array
    {
        return [
            'status' => SyncRecordStatus::class,
            'errors' => 'array',
            'server_result' => 'array',
            'local_sequence' => 'integer',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SyncBatch::class, 'sync_batch_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function exception(): BelongsTo
    {
        return $this->belongsTo(InventoryOversellException::class, 'exception_id');
    }
}
