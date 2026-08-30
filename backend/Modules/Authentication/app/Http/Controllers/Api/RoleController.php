<?php

namespace Modules\Authentication\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Http\Requests\UpdateRoleRequest;
use Modules\Authentication\Models\Permission;
use Modules\Authentication\Models\Role;

class RoleController
{
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('roles.view')) {
            abort(403, 'This action is unauthorized.');
        }

        $roles = Role::query()
            ->with('permissions:id,name,scope')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => $this->rolePayload($role));

        return response()->json([
            'data' => $roles,
        ]);
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->assertCanEditRole($actor, $role);

        if ($request->has('name')) {
            $role->name = $request->validated('name');
            $role->save();
        }

        if ($request->has('permissions')) {
            /** @var list<string> $names */
            $names = array_values($request->validated('permissions'));
            $this->assertPermissionPolicy($role, $names);

            $permissionIds = Permission::query()
                ->whereIn('name', $names)
                ->pluck('id')
                ->all();

            $role->permissions()->sync($permissionIds);
            $role->unsetRelation('permissions');
        }

        $role->load('permissions:id,name,scope');

        return response()->json([
            'data' => $this->rolePayload($role),
        ]);
    }

    private function assertCanEditRole(User $actor, Role $role): void
    {
        if ($role->code === 'super_admin' && $actor->role !== 'super_admin') {
            abort(403, 'Only Super Admin may edit the Super Admin role.');
        }
    }

    /**
     * @param  list<string>  $names
     */
    private function assertPermissionPolicy(Role $role, array $names): void
    {
        if ($role->code === 'org_admin') {
            foreach (['roles.view', 'roles.manage'] as $required) {
                if (! in_array($required, $names, true)) {
                    throw ValidationException::withMessages([
                        'permissions' => ["Organization Admin must retain {$required}."],
                    ]);
                }
            }
        }
    }

    /**
     * @return array{id: int, code: string, name: string, permissions: list<string>}
     */
    private function rolePayload(Role $role): array
    {
        return [
            'id' => $role->id,
            'code' => $role->code,
            'name' => $role->name,
            'permissions' => $role->resolvedPermissionNames(),
        ];
    }
}
