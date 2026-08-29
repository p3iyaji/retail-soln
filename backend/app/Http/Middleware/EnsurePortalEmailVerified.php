<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\CustomerPortal\Models\CustomerAccount;
use Symfony\Component\HttpFoundation\Response;

class EnsurePortalEmailVerified
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof CustomerAccount || ! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email verification is required.',
            ], 403);
        }

        return $next($request);
    }
}
