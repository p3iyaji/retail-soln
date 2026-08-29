<?php

namespace Modules\Authentication\Services;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class AuthTokenService
{
    public function issueToken(User $user): string
    {
        $tokenName = (string) config('authentication.sanctum_token_name', 'api');

        return $user->createToken($tokenName, ['staff'])->plainTextToken;
    }

    public function revokeCurrentToken(User $user, ?string $bearerToken = null): void
    {
        if ($bearerToken !== null) {
            PersonalAccessToken::findToken($bearerToken)?->delete();

            return;
        }

        $user->currentAccessToken()?->delete();
    }
}
