<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\MenuGroup;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions\MenuCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Menus and permissions from App\Support\Permissions\MenuCatalog. Safe to run again (also on production):
 *  - missing groups / menus are created; ones that exist keep what was edited in the back office (name, icon, order ...)
 *  - permissions are created / renamed, ones no longer in the catalogue are removed
 *  - the first run moves roles from the old *.manage keys to the new ones (MenuCatalog::legacyMap)
 *
 *   php artisan db:seed --class=MenuSeeder --force
 */
class MenuSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $legacyGrants = $this->legacyGrants();

            foreach (MenuCatalog::groups() as $groupOrder => $definition) {
                $group = MenuGroup::query()->firstOrCreate(['name' => $definition['name']], ['order' => $groupOrder + 1]);
                $this->menus($definition['menus'], $group, null);
            }

            Permission::query()->whereNotIn('key', MenuCatalog::keys())->delete();

            foreach ($legacyGrants as $roleId => $keys) {
                Role::query()->find($roleId)?->permissions()->sync(Permission::query()->whereIn('key', $keys)->pluck('id'));
            }
        });
    }

    /** @param list<array<string, mixed>> $menus */
    private function menus(array $menus, MenuGroup $group, ?Menu $parent): void
    {
        foreach ($menus as $order => $definition) {
            $match = match (true) {
                isset($definition['key']) => ['key' => $definition['key']],
                isset($definition['path']) => ['path' => $definition['path']],
                default => ['name' => $definition['name'], 'menu_group_id' => $group->id, 'parent_id' => $parent?->id],
            };

            $menu = Menu::query()->firstOrCreate($match, [
                'parent_id' => $parent?->id,
                'menu_group_id' => $parent ? null : $group->id,
                'key' => $definition['key'] ?? null,
                'name' => $definition['name'],
                'icon' => $definition['icon'] ?? null,
                'path' => $definition['path'] ?? null,
                'permission_key' => $definition['permission'] ?? null,
                'scopes' => $definition['scopes'] ?? null,
                'order' => $order + 1,
            ]);

            $actionOrder = 0;
            foreach (MenuCatalog::actions($definition) as $action => $label) {
                Permission::query()->updateOrCreate(['key' => $menu->key.'.'.$action], [
                    'menu_id' => $menu->id,
                    'action' => $action,
                    'name' => $definition['name'].': '.$label,
                    'category' => $menu->key,
                    'order' => ++$actionOrder,
                ]);
            }

            $this->menus($definition['children'] ?? [], $group, $menu);
        }
    }

    /**
     * While the old keys are still in the database: every role's keys translated to the new ones.
     *
     * @return array<int, list<string>> role id => permission keys
     */
    private function legacyGrants(): array
    {
        $map = MenuCatalog::legacyMap();
        $legacyOnly = array_diff(array_keys($map), MenuCatalog::keys());
        if (! Permission::query()->whereIn('key', $legacyOnly)->whereNull('menu_id')->exists()) {
            return [];
        }

        $grants = [];
        foreach (Role::query()->with('permissions:id,key')->get() as $role) {
            $keys = $role->permissions->pluck('key')
                ->flatMap(fn (string $key) => $map[$key] ?? [$key])
                ->unique()->values()->all();
            $grants[$role->id] = $keys;
        }

        return $grants;
    }
}
