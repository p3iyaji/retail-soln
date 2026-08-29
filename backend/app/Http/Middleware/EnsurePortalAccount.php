<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\CustomerPortal\Models\CustomerAccount;
use Symfony\Component\HttpFoundation\Response;

class EnsurePortalAccount
{
    /**
     * Reject staff (and any non-portal) Sanctum tokenables on portal APIs.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof CustomerAccount) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $token = $user->currentAccessToken();
        if ($token === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $ability = (string) config('customer_portal.sanctum_token_ability', 'portal');
        if (method_exists($token, 'can') && ! $token->can($ability)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
