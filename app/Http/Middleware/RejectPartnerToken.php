<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The web app routes are for login tokens only; partner tokens may call /api/v1/partner/* and nothing else. */
class RejectPartnerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->usesPartnerToken()) {
            return response()->json(['message' => 'Partner API tokens can only call /api/v1/partner endpoints.', 'code' => 'partner_token_not_allowed'], 403);
        }

        return $next($request);
    }
}
