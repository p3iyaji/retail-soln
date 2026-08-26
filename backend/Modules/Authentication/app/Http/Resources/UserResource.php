<?php

namespace Modules\Authentication\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'emailVerifiedAt' => $this->email_verified_at?->toIso8601String(),
            'twoFactorEnabled' => $this->two_factor_secret !== null
                && $this->two_factor_confirmed_at !== null,
            'roles' => $this->role ? [$this->role] : [],
            'permissions' => $this->roleModel()
                ?->permissions()
                ->pluck('name')
                ->values()
                ->all() ?? [],
            'storeAccess' => $this->storeAccessPayload(),
        ];
    }
}
