<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `perm:flights.view,manifest.view` — passes when the user has any of the permission keys.
 * For routes whose HTTP method does not say the action (POST allocations/move is an update).
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string ...$keys): Response
    {
        if (! $request->user()?->hasAnyPermission(...$keys)) {
            throw new AuthorizationException('You do not have permission for this.');
        }

        return $next($request);
    }
}
