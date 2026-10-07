<?php

namespace Modules\Attendance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClockShift extends Model
{
    protected $fillable = [
        'user_id',
        'location_id',
        'hourly_rate',
        'clock_in_at',
        'clock_out_at',
        'duration_minutes',
        'gross_pay',
        'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'hourly_rate' => 'decimal:4',
            'clock_in_at' => 'datetime',
            'clock_out_at' => 'datetime',
            'duration_minutes' => 'integer',
            'gross_pay' => 'decimal:4',
            'voided_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(ClockLocation::class, 'location_id');
    }
}
