<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\RoleScope;
use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\MenuGroup;
use App\Models\Permission;
use App\Support\Permissions\MenuCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sidebar menus and the permissions they own. Shared by every airline, so only system administrators edit them.
 * Keys from MenuCatalog are checked by routes: they cannot be renamed or deleted here.
 */
class MenuController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->ensureSystemAdmin($request);

        return response()->json(['data' => $this->tree()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureSystemAdmin($request);
        $data = $this->validated($request);
        $actions = $request->validate([
            'actions' => ['array'],
            'actions.*' => ['string', 'regex:/^[a-z][a-z_]*$/', 'max:30'],
        ])['actions'] ?? ($data['key'] ?? null ? MenuCatalog::CRUD : []);

        $menu = DB::transaction(function () use ($data, $actions) {
            $menu = Menu::create($data);
            if ($menu->key) {
                foreach (array_values(array_unique($actions)) as $i => $action) {
                    $this->createPermission($menu, $action, MenuCatalog::ACTION_LABELS[$action] ?? ucfirst($action), $i + 1);
                }
            }

            return $menu;
        });

        return $this->created('Menu created.', $this->present($menu->load('permissions')));
    }

    public function update(Request $request, Menu $menu): JsonResponse
    {
        $this->ensureSystemAdmin($request);
        $data = $this->validated($request, $menu);
        if ($menu->key && array_key_exists('key', $data) && $data['key'] !== $menu->key) {
            throw ValidationException::withMessages(['key' => 'The key of a menu with permissions cannot be changed.']);
        }
        if (($data['parent_id'] ?? null) === $menu->id) {
            throw ValidationException::withMessages(['parent_id' => 'A menu cannot be its own parent.']);
        }

        DB::transaction(function () use ($menu, $data) {
            $menu->update($data);
            // permission names follow the menu name
            foreach ($menu->permissions as $permission) {
                $label = str_contains($permission->name, ': ') ? substr($permission->name, strpos($permission->name, ': ') + 2) : $permission->name;
                $permission->update(['name' => $menu->name.': '.$label]);
            }
        });

        return $this->success('Menu updated.', $this->present($menu->load('permissions')));
    }

    public function destroy(Request $request, Menu $menu): JsonResponse
    {
        $this->ensureSystemAdmin($request);
        $keys = $this->descendantPermissionKeys($menu);
        if (array_intersect($keys, MenuCatalog::keys()) !== []) {
            throw ValidationException::withMessages(['menu' => 'This menu (or a sub-menu) has permissions the system checks; turn it off instead of deleting it.']);
        }

        $menu->delete();

        return $this->deleted('Menu deleted.');
    }

    /** PUT /admin/menus/order: [{id, order, parent_id?, menu_group_id?}] */
    public function reorder(Request $request): JsonResponse
    {
        $this->ensureSystemAdmin($request);
        $items = $request->validate([
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'integer', 'exists:menus,id'],
            'items.*.order' => ['required', 'integer', 'min:0'],
        ])['items'];

        DB::transaction(function () use ($items) {
            foreach ($items as $item) {
                Menu::query()->whereKey($item['id'])->update(['order' => $item['order']]);
            }
        });

        return $this->success('Order saved.', $this->tree());
    }

    /** A custom action besides view / create / update / delete (e.g. export, close) */
    public function storeAction(Request $request, Menu $menu): JsonResponse
    {
        $this->ensureSystemAdmin($request);
        if (! $menu->key) {
            throw ValidationException::withMessages(['action' => 'Give the menu a key first.']);
        }
        $data = $request->validate([
            'action' => ['required', 'string', 'regex:/^[a-z][a-z_]*$/', 'max:30'],
            'name' => ['nullable', 'string', 'max:100'],
        ]);
        if (Permission::query()->where('key', $menu->key.'.'.$data['action'])->exists()) {
            throw ValidationException::withMessages(['action' => 'This action already exists.']);
        }

        $permission = $this->createPermission($menu, $data['action'], $data['name'] ?: (MenuCatalog::ACTION_LABELS[$data['action']] ?? ucfirst($data['action'])),
            (int) $menu->permissions()->max('order') + 1);

        return $this->created('Action added.', $permission->only(['id', 'key', 'action', 'name']));
    }

    public function destroyAction(Request $request, Menu $menu, Permission $permission): JsonResponse
    {
        $this->ensureSystemAdmin($request);
        if ($permission->menu_id !== $menu->id) {
            abort(404);
        }
        if (in_array($permission->key, MenuCatalog::keys(), true)) {
            throw ValidationException::withMessages(['action' => 'The system checks this permission; it cannot be removed.']);
        }

        $permission->delete();

        return $this->deleted('Action removed.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Menu $menu = null): array
    {
        $required = $menu ? 'sometimes' : 'required';
        $data = $request->validate([
            'parent_id' => ['nullable', 'integer', 'exists:menus,id'],
            'menu_group_id' => ['nullable', 'integer', 'exists:menu_groups,id'],
            'key' => ['nullable', 'string', 'regex:/^[a-z][a-z0-9_]*$/', 'max:50', Rule::unique('menus', 'key')->ignore($menu?->id)],
            'name' => [$required, 'string', 'max:100'],
            'icon' => ['nullable', 'string', 'max:50'],
            'path' => ['nullable', 'string', 'max:255', 'regex:/^\//'],
            'permission_key' => ['nullable', 'string', 'exists:permissions,key'],
            'scopes' => ['nullable', 'array'],
            'scopes.*' => [Rule::enum(RoleScope::class)],
            'order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // a sub-menu sits in its parent's group
        if (! empty($data['parent_id'])) {
            $data['menu_group_id'] = null;
        }
        if (array_key_exists('scopes', $data) && empty($data['scopes'])) {
            $data['scopes'] = null;
        }

        return $data;
    }

    private function createPermission(Menu $menu, string $action, string $label, int $order): Permission
    {
        return Permission::create([
            'menu_id' => $menu->id,
            'key' => $menu->key.'.'.$action,
            'action' => $action,
            'name' => $menu->name.': '.$label,
            'category' => $menu->key,
            'order' => $order,
        ]);
    }

    /** @return list<string> */
    private function descendantPermissionKeys(Menu $menu): array
    {
        $keys = $menu->permissions()->pluck('key')->all();
        foreach ($menu->children as $child) {
            $keys = [...$keys, ...$this->descendantPermissionKeys($child)];
        }

        return $keys;
    }

    /** Every group with its menus → sub-menus (inactive ones too) and their permissions */
    private function tree(): array
    {
        $menus = Menu::query()->with('permissions')->orderBy('order')->orderBy('id')->get();
        $byParent = $menus->groupBy(fn (Menu $m) => $m->parent_id ?? 0);
        $build = function (Menu $menu) use (&$build, $byParent): array {
            return $this->present($menu) + [
                'children' => $byParent->get($menu->id, collect())->map($build)->values()->all(),
            ];
        };
        $roots = $byParent->get(0, collect());

        return MenuGroup::query()->orderBy('order')->orderBy('id')->get()
            ->map(fn (MenuGroup $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'order' => $group->order,
                'menus' => $roots->where('menu_group_id', $group->id)->map($build)->values()->all(),
            ])
            ->push([
                'id' => null,
                'name' => 'Ungrouped',
                'order' => PHP_INT_MAX,
                'menus' => $roots->whereNull('menu_group_id')->map($build)->values()->all(),
            ])
            ->filter(fn (array $group) => $group['id'] !== null || $group['menus'] !== [])
            ->values()->all();
    }

    /** @return array<string, mixed> */
    private function present(Menu $menu): array
    {
        return [
            'id' => $menu->id,
            'parent_id' => $menu->parent_id,
            'menu_group_id' => $menu->menu_group_id,
            'key' => $menu->key,
            'name' => $menu->name,
            'icon' => $menu->icon,
            'path' => $menu->path,
            'permission_key' => $menu->permission_key,
            'scopes' => $menu->scopes,
            'order' => $menu->order,
            'is_active' => $menu->is_active,
            'is_system' => $menu->key !== null && in_array($menu->key, MenuCatalog::menuKeys(), true),
            'permissions' => $menu->permissions->map(fn (Permission $p) => [
                'id' => $p->id, 'key' => $p->key, 'action' => $p->action, 'name' => $p->name,
                'is_system' => in_array($p->key, MenuCatalog::keys(), true),
            ])->values()->all(),
        ];
    }

    private function ensureSystemAdmin(Request $request): void
    {
        if (! $request->user()->isSystemAdmin()) {
            throw new AuthorizationException('Only a system administrator can edit menus.');
        }
    }
}
