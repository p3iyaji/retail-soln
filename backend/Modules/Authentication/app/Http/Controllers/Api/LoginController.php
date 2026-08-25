<?php

namespace Modules\Authentication\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Modules\Authentication\Http\Requests\LoginRequest;
use Modules\Authentication\Http\Resources\UserResource;
use Modules\Authentication\Services\AuthChallengeService;
use Modules\Authentication\Services\AuthTokenService;

class LoginController
{
    public function __invoke(
        LoginRequest $request,
        AuthChallengeService $challenges,
        AuthTokenService $tokens,
    ): JsonResponse {
        /** @var User|null $user */
        $user = User::query()->where('email', $request->validated('email'))->first();

        if ($user === null || ! Hash::check($request->validated('password'), $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            return response()->json([
                'twoFactorRequired' => true,
                'challengeToken' => $challenges->createTwoFactorChallenge($user),
            ]);
        }

        return response()->json([
            'token' => $tokens->issueToken($user),
            'tokenType' => 'Bearer',
            'user' => new UserResource($user),
        ]);
    }
}
