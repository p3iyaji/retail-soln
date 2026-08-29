<?php

namespace Modules\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Notifications\Support\NotificationStatuses;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;

/**
 * One row per (notification, channel). Never carries money or stock state —
 * amounts inside `payload` are display copies of already-committed facts.
 */
class Notification extends Model
{
    protected $fillable = [
        'organization_id',
        'store_id',
        'type',
        'channel',
        'status',
        'recipient_type',
        'recipient_id',
        'recipient_address',
        'recipient_name',
        'subject',
        'body',
        'payload',
        'related_type',
        'related_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function markSent(): void
    {
        $this->forceFill([
            'status' => NotificationStatuses::SENT,
            'sent_at' => now(),
            'failed_at' => null,
            'error' => null,
            'attempts' => $this->attempts + 1,
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => NotificationStatuses::FAILED,
            'failed_at' => now(),
            'error' => mb_substr($error, 0, 1000),
            'attempts' => $this->attempts + 1,
        ])->save();
    }
}
