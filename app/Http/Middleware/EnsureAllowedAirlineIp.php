<?php

namespace App\Http\Middleware;

use App\Services\Access\AirlineIpAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Airline back-office staff of an airline with IP restriction on: only from its registered addresses. */
class EnsureAllowedAirlineIp
{
    public function __construct(private readonly AirlineIpAccess $ipAccess) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $this->ipAccess->allows($user, $request->ip())) {
            $this->ipAccess->logBlocked($user, $request->ip());

            return response()->json(['message' => AirlineIpAccess::MESSAGE, 'code' => 'ip_not_allowed'], 403);
        }

        return $next($request);
    }
}
