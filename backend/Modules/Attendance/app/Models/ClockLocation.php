<?php

namespace Modules\Attendance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Organization\Models\Organization;
use Modules\Organization\Models\Store;

class ClockLocation extends Model
{
    protected $fillable = [
        'organization_id',
        'store_id',
        'name',
        'address',
        'latitude',
        'longitude',
        'radius_meters',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'radius_meters' => 'integer',
            'is_active' => 'boolean',
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

    public function assignments(): HasMany
    {
        return $this->hasMany(ClockLocationAssignment::class, 'location_id');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(ClockShift::class, 'location_id');
    }

    public function requiresGeofence(): bool
    {
        return $this->latitude !== null
            && $this->longitude !== null
            && $this->radius_meters !== null;
    }

    public function viewerManages(User $user): bool
    {
        if (! $user->hasPermission('attendance.manage')) {
            return false;
        }

        if ($this->store_id === null) {
            return $user->isOrgWide();
        }

        return $user->canAccessStore((int) $this->store_id);
    }
}
