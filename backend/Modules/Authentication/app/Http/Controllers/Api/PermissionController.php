<?php

namespace Modules\Authentication\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Authentication\Models\Permission;

class PermissionController
{
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('roles.view')) {
            abort(403, 'This action is unauthorized.');
        }

        $permissions = Permission::query()
            ->orderBy('name')
            ->get(['id', 'name', 'scope'])
            ->map(fn (Permission $permission) => [
                'id' => $permission->id,
                'name' => $permission->name,
                'scope' => $permission->scope,
            ]);

        return response()->json([
            'data' => $permissions,
        ]);
    }
}
