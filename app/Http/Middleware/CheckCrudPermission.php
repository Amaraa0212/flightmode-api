<?php

namespace App\Http\Middleware;

use App\Enums\RoleScope;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `crud:flights` — the action comes from the HTTP method: GET → flights.view, POST → flights.create,
 * PUT / PATCH → flights.update, DELETE → flights.delete.
 *
 * More menus after the first also pass with the same action (`crud:airlines,carriage`: carriage.update may PUT
 * an airline). `lookup` lets GET through for every back-office user (any *.view, not agency logins): lists other
 * screens load into their selects (flights, aircraft, agencies ...). Writes always need the menu's own action.
 */
class CheckCrudPermission
{
    private const ACTIONS = ['GET' => 'view', 'HEAD' => 'view', 'POST' => 'create', 'PUT' => 'update', 'PATCH' => 'update', 'DELETE' => 'delete'];

    public function handle(Request $request, Closure $next, string ...$menus): Response
    {
        $user = $request->user();
        $action = self::ACTIONS[$request->method()] ?? null;
        $lookup = in_array('lookup', $menus, true);
        $keys = array_map(fn ($menu) => "$menu.$action", array_diff($menus, ['lookup']));

        $allowed = $user && $action && ($user->hasAnyPermission(...$keys) || ($lookup && $action === 'view' && $this->isBackOffice($user)));
        if (! $allowed) {
            throw new AuthorizationException('You do not have permission for this.');
        }

        return $next($request);
    }

    private function isBackOffice(User $user): bool
    {
        if ($user->isSystemAdmin()) {
            return true;
        }

        return $user->scope() !== RoleScope::Agency
            && collect($user->permissionKeys())->contains(fn (string $key) => str_ends_with($key, '.view'));
    }
}
