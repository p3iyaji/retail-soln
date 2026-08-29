<?php

namespace Modules\CustomerPortal\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\CustomerPortal\Http\Requests\PortalLoginRequest;
use Modules\CustomerPortal\Http\Requests\PortalRegisterRequest;
use Modules\CustomerPortal\Http\Requests\PortalVerifyRequest;
use Modules\CustomerPortal\Http\Resources\PortalAccountResource;
use Modules\CustomerPortal\Models\CustomerAccount;
use Modules\CustomerPortal\Services\PortalAuthService;
use Modules\CustomerPortal\Services\PortalRegistrationService;
use Modules\CustomerPortal\Services\PortalTokenService;

class PortalAuthController
{
    public function register(
        PortalRegisterRequest $request,
        PortalRegistrationService $registration,
        PortalAuthService $auth,
    ): JsonResponse {
        $result = $registration->register($request->validated());
        $payload = $auth->verificationRequiredPayload($result['account'], $result['verification_token']);

        return response()->json([
            'verificationRequired' => true,
            'email' => $payload['email'],
            'message' => 'Check your email to verify your account before signing in.',
            ...($this->optionalVerificationToken($payload)),
        ], 201);
    }

    public function login(
        PortalLoginRequest $request,
        PortalAuthService $auth,
    ): JsonResponse {
        try {
            $result = $auth->attemptLogin(
                $request->validated('email'),
                $request->validated('password'),
            );
        } catch (ValidationException $e) {
            if ($e->status === 401) {
                return response()->json([
                    'message' => 'Invalid credentials.',
                ], 401);
            }

            throw $e;
        }

        if (($result['verification_required'] ?? false) === true) {
            return response()->json([
                'verificationRequired' => true,
                'email' => $result['email'],
                'message' => 'Verify your email before signing in.',
            ]);
        }

        return response()->json([
            'token' => $result['token'],
            'tokenType' => 'Bearer',
            'account' => new PortalAccountResource($result['account']),
        ]);
    }

    public function verify(
        PortalVerifyRequest $request,
        PortalAuthService $auth,
    ): JsonResponse {
        $result = $auth->verifyEmail(
            $request->validated('email'),
            $request->validated('token'),
        );

        return response()->json([
            'token' => $result['token'],
            'tokenType' => 'Bearer',
            'account' => new PortalAccountResource($result['account']),
        ]);
    }

    public function logout(
        PortalTokenService $tokens,
    ): JsonResponse {
        /** @var CustomerAccount $account */
        $account = request()->user();
        $bearer = request()->bearerToken();
        $tokens->revokeCurrentToken($account, $bearer);

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(): JsonResponse
    {
        /** @var CustomerAccount $account */
        $account = request()->user();
        $account->loadMissing(['customer.addresses']);

        return response()->json([
            'data' => new PortalAccountResource($account),
        ]);
    }

    /**
     * @param  array{verification_token?: string}  $payload
     * @return array{verificationToken?: string}
     */
    private function optionalVerificationToken(array $payload): array
    {
        if (! isset($payload['verification_token'])) {
            return [];
        }

        return ['verificationToken' => $payload['verification_token']];
    }
}
