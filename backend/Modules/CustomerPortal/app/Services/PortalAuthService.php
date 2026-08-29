<?php

namespace Modules\CustomerPortal\Services;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\CustomerPortal\Models\CustomerAccount;

class PortalAuthService
{
    /**
     * Bcrypt hash used only so missing-account logins still run Hash::check (timing).
     */
    private const DUMMY_PASSWORD_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    public function __construct(
        private readonly PortalTokenService $tokens,
    ) {}

    /**
     * @return array{token: string, account: CustomerAccount}|array{verification_required: true, email: string}
     */
    public function attemptLogin(string $email, string $password): array
    {
        $email = Str::lower(trim($email));

        /** @var CustomerAccount|null $account */
        $account = CustomerAccount::query()->where('email', $email)->first();

        $passwordValid = Hash::check($password, $account?->password ?? self::DUMMY_PASSWORD_HASH);

        if ($account === null || ! $passwordValid) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ])->status(401);
        }

        $customer = $account->customer;
        if ($customer === null || ! $customer->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ])->status(401);
        }

        if (! $account->hasVerifiedEmail()) {
            // Do not rotate verification tokens on login — keeps prior email links valid.
            return $this->verificationRequiredPayload($account);
        }

        return [
            'token' => $this->tokens->issueToken($account),
            'account' => $account->loadMissing(['customer.addresses']),
        ];
    }

    /**
     * @return array{token: string, account: CustomerAccount}
     */
    public function verifyEmail(string $email, string $token): array
    {
        $email = Str::lower(trim($email));

        /** @var CustomerAccount|null $account */
        $account = CustomerAccount::query()->where('email', $email)->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                'email' => ['Invalid verification link.'],
            ]);
        }

        $hashed = hash('sha256', $token);
        if (
            $account->email_verification_token === null
            || ! hash_equals($account->email_verification_token, $hashed)
            || ($account->email_verification_expires_at !== null && $account->email_verification_expires_at->isPast())
        ) {
            throw ValidationException::withMessages([
                'token' => ['Invalid or expired verification token.'],
            ]);
        }

        if (! $account->hasVerifiedEmail()) {
            $account->markEmailAsVerified();
        } else {
            // Token matched a leftover hash; clear it and issue session.
            $account->forceFill([
                'email_verification_token' => null,
                'email_verification_expires_at' => null,
            ])->save();
        }

        return [
            'token' => $this->tokens->issueToken($account->fresh()),
            'account' => $account->fresh(['customer.addresses']),
        ];
    }

    /**
     * @return array{verification_required: true, email: string, verification_token?: string}
     */
    public function resendVerification(CustomerAccount $account): array
    {
        if ($account->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'email' => ['Email is already verified.'],
            ]);
        }

        return $this->issueFreshVerificationToken($account);
    }

    /**
     * @return array{verification_required: true, email: string, verification_token?: string}
     */
    public function verificationRequiredPayload(CustomerAccount $account, ?string $plainToken = null): array
    {
        $payload = [
            'verification_required' => true,
            'email' => $account->email,
        ];

        if ($plainToken !== null && $this->shouldExposeVerificationToken()) {
            $payload['verification_token'] = $plainToken;
        }

        return $payload;
    }

    /**
     * @return array{verification_required: true, email: string, verification_token?: string}
     */
    public function issueFreshVerificationToken(CustomerAccount $account): array
    {
        $plainToken = Str::random(64);
        $minutes = (int) config('customer_portal.verification_expires_minutes', 60);

        $account->forceFill([
            'email_verification_token' => hash('sha256', $plainToken),
            'email_verification_expires_at' => now()->addMinutes($minutes),
        ])->save();

        return $this->verificationRequiredPayload($account, $plainToken);
    }

    private function shouldExposeVerificationToken(): bool
    {
        return app()->environment(['local', 'testing']);
    }
}
