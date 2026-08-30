<?php

namespace Modules\Authentication\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Authentication\Support\PermissionMatrix;

class Role extends Model
{
    protected $fillable = [
        'code',
        'name',
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    /**
     * @return list<string>
     */
    public function resolvedPermissionNames(): array
    {
        $fromDb = $this->relationLoaded('permissions')
            ? $this->permissions->pluck('name')->all()
            : $this->permissions()->pluck('name')->all();

        if ($fromDb !== []) {
            return array_values($fromDb);
        }

        return PermissionMatrix::permissionNamesForRoleCode($this->code);
    }
}
