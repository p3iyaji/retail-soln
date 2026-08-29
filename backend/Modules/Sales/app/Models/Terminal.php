<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Organization\Models\Store;
use Modules\Sales\Enums\TerminalStatus;

class Terminal extends Model
{
    protected $fillable = [
        'organization_id',
        'store_id',
        'uuid',
        'name',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => TerminalStatus::class,
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CashierSession::class, 'terminal_id');
    }
}
