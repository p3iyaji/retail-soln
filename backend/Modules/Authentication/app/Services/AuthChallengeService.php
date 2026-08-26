<?php

namespace Modules\Authentication\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AuthChallengeService
{
    private const CHALLENGE_TTL_SECONDS = 300;

    public function createTwoFactorChallenge(User $user): string
    {
        $token = Str::random(64);

        Cache::put($this->cacheKey($token), $user->getKey(), self::CHALLENGE_TTL_SECONDS);

        return $token;
    }

    public function resolveTwoFactorChallenge(string $token): ?User
    {
        $userId = Cache::pull($this->cacheKey($token));

        if ($userId === null) {
            return null;
        }

        return User::query()->find($userId);
    }

    private function cacheKey(string $token): string
    {
        return 'auth:two-factor-challenge:'.$token;
    }
}
