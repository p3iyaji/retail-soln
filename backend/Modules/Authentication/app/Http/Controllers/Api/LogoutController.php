<?php

namespace Modules\Authentication\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Authentication\Services\AuthTokenService;

class LogoutController
{
    public function __invoke(Request $request, AuthTokenService $tokens): JsonResponse
    {
        $user = $request->user();

        if ($user !== null) {
            $tokens->revokeCurrentToken($user, $request->bearerToken());
        }

        return response()->json([
            'message' => 'Logged out.',
        ]);
    }
}
