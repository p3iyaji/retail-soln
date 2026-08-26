<?php

namespace Modules\Authentication\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'code' => $role->code,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->values()->all(),
            ]);

        return response()->json([
            'data' => $roles,
        ]);
    }
}
