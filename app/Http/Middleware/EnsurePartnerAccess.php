<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Agency API: the caller must use a partner token, and its agency must have API access enabled. */
class EnsurePartnerAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->usesPartnerToken()) {
            return response()->json(['message' => 'Use a partner API token for this endpoint.', 'code' => 'partner_token_required'], 403);
        }

        $agency = $request->user()->agency;

        if (! $agency || ! $agency->is_active || ! $agency->api_enabled) {
            return response()->json(['message' => 'API access is not enabled for your agency.', 'code' => 'partner_access_disabled'], 403);
        }

        return $next($request);
    }
}
