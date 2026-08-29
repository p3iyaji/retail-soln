<?php

namespace Modules\CustomerPortal\Services;

use Laravel\Sanctum\PersonalAccessToken;
use Modules\CustomerPortal\Models\CustomerAccount;

class PortalTokenService
{
    public function issueToken(CustomerAccount $account): string
    {
        $tokenName = (string) config('customer_portal.sanctum_token_name', 'portal');
        $ability = (string) config('customer_portal.sanctum_token_ability', 'portal');

        return $account->createToken($tokenName, [$ability])->plainTextToken;
    }

    public function revokeCurrentToken(CustomerAccount $account, ?string $bearerToken = null): void
    {
        if ($bearerToken !== null) {
            PersonalAccessToken::findToken($bearerToken)?->delete();

            return;
        }

        $account->currentAccessToken()?->delete();
    }
}
