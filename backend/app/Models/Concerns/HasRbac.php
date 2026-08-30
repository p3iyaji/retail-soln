<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Support\PermissionMatrix;
use Modules\Organization\Models\Store;

trait HasRbac
{
    public function stores(): BelongsToMany
    {
        return $this->belongsToMany(Store::class, 'store_user')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    public function roleModel(): ?Role
    {
        if ($this->role === null || $this->role === '') {
            return null;
        }

        return Role::query()->where('code', $this->role)->first();
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissionNames(), true);
    }

    /**
     * @return list<string>
     */
    public function permissionNames(): array
    {
        if ($this->role === null || $this->role === '') {
            return [];
        }

        $role = $this->roleModel();
        if ($role !== null) {
            $fromDb = $role->permissions()->pluck('name')->all();
            if ($fromDb !== []) {
                return array_values($fromDb);
            }
        }

        return PermissionMatrix::permissionNamesForRoleCode($this->role);
    }

    public function isOrgWide(): bool
    {
        return in_array($this->role, PermissionMatrix::orgWideRoleCodes(), true);
    }

    public function canAccessStore(int $storeId): bool
    {
        if ($this->isOrgWide()) {
            return true;
        }

        return $this->stores()->where('stores.id', $storeId)->exists();
    }

    /**
     * @return Collection<int, int>
     */
    public function accessibleStoreIds(): Collection
    {
        if ($this->isOrgWide()) {
            return Store::query()->pluck('id');
        }

        return $this->stores()->pluck('stores.id');
    }

    /**
     * @return list<array{id: int, name: string, isDefault: bool}>
     */
    public function storeAccessPayload(): array
    {
        return $this->stores()
            ->get(['stores.id', 'stores.name', 'store_user.is_default'])
            ->map(fn (Store $store) => [
                'id' => $store->id,
                'name' => $store->name,
                'isDefault' => (bool) $store->pivot->is_default,
            ])
            ->values()
            ->all();
    }
}
