<?php

namespace Modules\Audit\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'store_id',
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'before',
        'after',
        'ip_address',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Audit logs are append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('Audit logs are append-only and cannot be deleted.');
        });
    }
}
