<?php

namespace Modules\Authentication\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Authentication\Models\Permission;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Support\PermissionMatrix;

class PermissionMatrixSeeder extends Seeder
{
    public function run(): void
    {
        $roleModels = [];
        foreach (PermissionMatrix::roles() as $abbr => $meta) {
            $roleModels[$abbr] = Role::query()->updateOrCreate(
                ['code' => $meta['code']],
                ['name' => $meta['name']],
            );
        }

        foreach (PermissionMatrix::permissions() as $name => $definition) {
            $permission = Permission::query()->updateOrCreate(
                ['name' => $name],
                ['scope' => $definition['scope']],
            );

            $roleIds = collect($definition['roles'])
                ->map(fn (string $abbr) => $roleModels[$abbr]->id)
                ->all();

            $permission->roles()->sync($roleIds);
        }
    }
}
