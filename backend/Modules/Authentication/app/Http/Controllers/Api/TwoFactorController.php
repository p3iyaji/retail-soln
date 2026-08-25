<?php

namespace Modules\Authentication\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Modules\Authentication\Http\Requests\TwoFactorChallengeRequest;
use Modules\Authentication\Http\Requests\TwoFactorConfirmRequest;
use Modules\Authentication\Http\Resources\UserResource;
use Modules\Authentication\Services\AuthChallengeService;
use Modules\Authentication\Services\AuthTokenService;

class TwoFactorController
{
    public function enable(
        EnableTwoFactorAuthentication $enable,
    ): JsonResponse {
        /** @var User $user */
        $user = auth()->user();

        if (! $this->userMayManageTwoFactor($user)) {
            return response()->json([
                'message' => 'Two-factor authentication is only available for privileged roles.',
            ], 403);
        }

        $enable($user);

        return response()->json([
            'qrCodeSvg' => $user->twoFactorQrCodeSvg(),
            'secretKey' => Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
        ]);
    }

    public function confirm(
        TwoFactorConfirmRequest $request,
        ConfirmTwoFactorAuthentication $confirm,
    ): JsonResponse {
        /** @var User $user */
        $user = auth()->user();

        if (! $this->userMayManageTwoFactor($user)) {
            return response()->json([
                'message' => 'Two-factor authentication is only available for privileged roles.',
            ], 403);
        }

        $confirm($user, $request->validated('code'));

        return response()->json([
            'message' => 'Two-factor authentication confirmed.',
            'user' => new UserResource($user->fresh()),
        ]);
    }

    public function disable(
        DisableTwoFactorAuthentication $disable,
    ): JsonResponse {
        /** @var User $user */
        $user = auth()->user();

        if (! $this->userMayManageTwoFactor($user)) {
            return response()->json([
                'message' => 'Two-factor authentication is only available for privileged roles.',
            ], 403);
        }

        $disable($user);

        return response()->json([
            'message' => 'Two-factor authentication disabled.',
        ]);
    }

    public function challenge(
        TwoFactorChallengeRequest $request,
        AuthChallengeService $challenges,
        AuthTokenService $tokens,
        TwoFactorAuthenticationProvider $provider,
    ): JsonResponse {
        $user = $challenges->resolveTwoFactorChallenge($request->validated('challengeToken'));

        if ($user === null || ! $user->hasEnabledTwoFactorAuthentication()) {
            return response()->json([
                'message' => 'Invalid two-factor challenge.',
            ], 401);
        }

        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
        $code = $request->validated('code');

        $validCode = $provider->verify($secret, $code);
        $validRecoveryCode = collect($user->recoveryCodes())->contains(
            fn (string $recoveryCode): bool => hash_equals($recoveryCode, $code)
        );

        if (! $validCode && ! $validRecoveryCode) {
            return response()->json([
                'message' => 'Invalid two-factor code.',
            ], 401);
        }

        if ($validRecoveryCode) {
            $user->replaceRecoveryCode($code);
        }

        return response()->json([
            'token' => $tokens->issueToken($user),
            'tokenType' => 'Bearer',
            'user' => new UserResource($user),
        ]);
    }

    private function userMayManageTwoFactor(User $user): bool
    {
        $eligibleRoles = config('authentication.two_factor_eligible_roles', []);

        return in_array($user->role, $eligibleRoles, true);
    }
}
