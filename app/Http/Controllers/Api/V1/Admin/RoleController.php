<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\RoleScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Menu;
use App\Models\MenuGroup;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Roles and their menu permissions. System administrators manage every role; an airline administrator manages
 * the roles of their own airline (airline / airport scope) and can only grant permissions they hold themselves.
 */
class RoleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return RoleResource::collection(
            $this->visible($request->user())->with(['permissions', 'airline'])->withCount('users')->orderBy('name')->get()
        );
    }

    /** The permission matrix: menu groups → menus (sub-menus flattened) → their actions. Only what the actor may grant. */
    public function permissions(Request $request): JsonResponse
    {
        $actor = $request->user();
        $menus = Menu::query()->with('permissions')->orderBy('order')->orderBy('id')->get();
        $byParent = $menus->groupBy(fn (Menu $m) => $m->parent_id ?? 0);

        $rows = function (Menu $menu, ?string $parent) use (&$rows, $byParent, $actor): array {
            $permissions = $menu->permissions
                ->filter(fn (Permission $p) => $actor->hasPermission($p->key))
                ->map(fn (Permission $p) => ['key' => $p->key, 'action' => $p->action, 'name' => $p->name])
                ->values()->all();
            $own = $permissions === [] ? [] : [[
                'id' => $menu->id,
                'key' => $menu->key,
                'name' => $parent ? "$parent / {$menu->name}" : $menu->name,
                'scopes' => $menu->scopes,
                'is_active' => $menu->is_active,
                'permissions' => $permissions,
            ]];

            return [...$own, ...$byParent->get($menu->id, collect())->flatMap(fn (Menu $child) => $rows($child, $menu->name))->all()];
        };
        $roots = $byParent->get(0, collect());

        $groups = MenuGroup::query()->orderBy('order')->orderBy('id')->get()
            ->map(fn (MenuGroup $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'menus' => $roots->where('menu_group_id', $group->id)->flatMap(fn (Menu $m) => $rows($m, null))->values()->all(),
            ])
            ->push(['id' => null, 'name' => 'Ungrouped', 'menus' => $roots->whereNull('menu_group_id')->flatMap(fn (Menu $m) => $rows($m, null))->values()->all()])
            ->filter(fn (array $group) => $group['menus'] !== [])
            ->values();

        return response()->json(['data' => $groups]);
    }

    public function store(RoleRequest $request): JsonResponse
    {
        $data = $this->scopeData($request->user(), $request->safe()->except('permissions'));
        $keys = $this->grantable($request->user(), $request->validated('permissions', []));

        $role = DB::transaction(function () use ($data, $keys) {
            $role = Role::create($data);
            $this->syncPermissions($role, $keys);

            return $role;
        });

        return $this->created('Role created.', new RoleResource($role->load(['permissions', 'airline'])));
    }

    public function show(Request $request, Role $role): RoleResource
    {
        $this->ensureVisible($request->user(), $role);

        return new RoleResource($role->load(['permissions', 'airline'])->loadCount('users'));
    }

    public function update(RoleRequest $request, Role $role): JsonResponse
    {
        $actor = $request->user();
        $this->ensureEditable($actor, $role);
        $data = $this->scopeData($actor, $request->safe()->except('permissions') + ['scope' => $role->scope->value, 'airline_id' => $role->airline_id], $role);

        DB::transaction(function () use ($request, $actor, $role, $data) {
            $role->update($data);
            if ($request->has('permissions')) {
                $this->syncPermissions($role, $this->grantable($actor, $request->validated('permissions', [])));
            }
        });

        return $this->success('Role updated.', new RoleResource($role->load(['permissions', 'airline'])));
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        $this->ensureEditable($request->user(), $role);
        if ($role->is_system || $role->users()->exists()) {
            throw ValidationException::withMessages(['role' => 'System roles and roles with users cannot be deleted.']);
        }

        $role->delete();

        return $this->deleted('Role deleted.');
    }

    /** System administrators: every role. Airline users: their airline's roles and the shared airline / airport roles. */
    private function visible(User $actor): Builder
    {
        return Role::query()->when(! $actor->isSystemAdmin(), fn (Builder $q) => $q->where(
            fn (Builder $x) => $x->where('airline_id', $actor->airline_id ?? 0)->orWhere(fn (Builder $y) => $y
                ->whereNull('airline_id')->whereIn('scope', [RoleScope::Airline->value, RoleScope::Airport->value]))
        ));
    }

    private function ensureVisible(User $actor, Role $role): void
    {
        if (! $this->visible($actor)->whereKey($role->id)->exists()) {
            throw new AuthorizationException('You do not have access to this role.');
        }
    }

    private function ensureEditable(User $actor, Role $role): void
    {
        if ($actor->isSystemAdmin()) {
            return;
        }
        if ($role->airline_id === null || (int) $role->airline_id !== (int) $actor->airline_id) {
            throw new AuthorizationException('You can only change roles of your own airline.');
        }
        if ((int) $role->id === (int) $actor->role_id) {
            throw new AuthorizationException('You cannot change your own role.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function scopeData(User $actor, array $data, ?Role $role = null): array
    {
        if ($actor->isSystemAdmin()) {
            if (! empty($data['airline_id']) && ! in_array($data['scope'], [RoleScope::Airline->value, RoleScope::Airport->value], true)) {
                throw ValidationException::withMessages(['airline_id' => 'Only airline and airport roles can belong to an airline.']);
            }

            return $data;
        }

        if (! $actor->airline_id) {
            throw new AuthorizationException('Only airline administrators can create roles.');
        }
        if (! in_array($data['scope'], [RoleScope::Airline->value, RoleScope::Airport->value], true)) {
            throw ValidationException::withMessages(['scope' => 'Choose the airline or airport scope.']);
        }

        return ['airline_id' => $actor->airline_id, 'is_system' => false] + $data;
    }

    /**
     * Only permissions the actor holds may be granted (an airline administrator cannot give more than they have).
     *
     * @param  array<string>  $keys
     * @return array<string>
     */
    private function grantable(User $actor, array $keys): array
    {
        $denied = array_values(array_filter($keys, fn (string $key) => ! $actor->hasPermission($key)));
        if ($denied !== []) {
            throw ValidationException::withMessages(['permissions' => 'You cannot grant permissions you do not have: '.implode(', ', $denied)]);
        }

        return $keys;
    }

    /** @param array<string> $keys */
    private function syncPermissions(Role $role, array $keys): void
    {
        // an agency menu is of no use without the portal itself (the agency API checks agency.portal)
        if (collect($keys)->contains(fn (string $key) => preg_match('/^agency_(home|booking|bookings|search|holds|quotas|account|conditions)\./', $key))) {
            $keys[] = 'agency.portal';
        }
        $role->permissions()->sync(Permission::query()->whereIn('key', array_unique($keys))->pluck('id'));
    }
}
