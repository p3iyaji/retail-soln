<?php

namespace Modules\Authentication\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Modules\Authentication\Http\Requests\UpdateUserRequest;
use Modules\Authentication\Http\Resources\UserResource;
use Modules\Authentication\Models\Role;
use Modules\Organization\Models\Store;

class UserController
{
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('users.view')) {
            abort(403, 'This action is unauthorized.');
        }

        $users = User::query()
            ->orderBy('name')
            ->paginate(perPage: (int) $request->integer('per_page', 15));

        return response()->json([
            'data' => UserResource::collection($users->items()),
            'meta' => [
                'currentPage' => $users->currentPage(),
                'lastPage' => $users->lastPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->hasPermission('users.manage')) {
            abort(403, 'This action is unauthorized.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', Rule::exists('roles', 'code')],
        ]);

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
        ]);

        return response()->json([
            'data' => new UserResource($user),
        ], 201);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        if (! $request->user()->hasPermission('users.view')) {
            abort(403, 'This action is unauthorized.');
        }

        return response()->json([
            'data' => new UserResource($user->load('stores')),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $validated = $request->validated();

        if (array_key_exists('name', $validated)) {
            $user->name = $validated['name'];
        }

        if (array_key_exists('email', $validated)) {
            $user->email = $validated['email'];
        }

        if (! empty($validated['password'] ?? null)) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        return response()->json([
            'data' => new UserResource($user->fresh()->load('stores')),
        ]);
    }

    public function assignRole(Request $request, User $user): JsonResponse
    {
        if (! $request->user()->hasPermission('users.assign-roles')) {
            abort(403, 'This action is unauthorized.');
        }

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::exists('roles', 'code')],
        ]);

        $user->forceFill(['role' => $validated['role']])->save();

        return response()->json([
            'data' => new UserResource($user->fresh()),
        ]);
    }

    public function assignStores(Request $request, User $user): JsonResponse
    {
        if (! $request->user()->hasPermission('users.assign-stores')) {
            abort(403, 'This action is unauthorized.');
        }

        $validated = $request->validate([
            'storeIds' => ['required', 'array', 'min:1'],
            'storeIds.*' => ['integer', Rule::exists('stores', 'id')],
            'defaultStoreId' => ['nullable', 'integer', Rule::exists('stores', 'id')],
        ]);

        $actor = $request->user();
        foreach ($validated['storeIds'] as $storeId) {
            if (! $actor->canAccessStore((int) $storeId)) {
                abort(403, 'Cannot assign a store outside your access.');
            }
        }

        $defaultId = $validated['defaultStoreId'] ?? $validated['storeIds'][0];
        if (! in_array($defaultId, $validated['storeIds'], true)) {
            $defaultId = $validated['storeIds'][0];
        }

        $sync = [];
        foreach ($validated['storeIds'] as $storeId) {
            $sync[$storeId] = ['is_default' => (int) $storeId === (int) $defaultId];
        }

        $user->stores()->sync($sync);

        return response()->json([
            'data' => new UserResource($user->fresh()->load('stores')),
        ]);
    }
}
