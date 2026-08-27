<?php

namespace Modules\Products\Models;

use Illuminate\Database\Eloquent\Model;

class ProductImport extends Model
{
    protected $fillable = [
        'organization_id',
        'user_id',
        'status',
        'original_filename',
        'total_rows',
        'success_count',
        'error_count',
        'errors',
        'async',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'async' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
