<?php

namespace Modules\CustomerPortal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Organization\Models\Organization;

class Carrier extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'tracking_url_template',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(PortalShipment::class);
    }
}
