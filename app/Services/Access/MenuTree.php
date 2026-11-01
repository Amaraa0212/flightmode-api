<?php

namespace App\Services\Access;

use App\Models\Menu;
use App\Models\MenuGroup;
use App\Models\User;
use Illuminate\Support\Collection;

/** The sidebar of a user: active menu groups → menus → sub-menus they may see (scope + permission). */
class MenuTree
{
    /** @return list<array<string, mixed>> */
    public function forUser(User $user): array
    {
        $scope = $user->scope()->value;
        $menus = Menu::query()->where('is_active', true)->orderBy('order')->orderBy('id')->get();
        $byParent = $menus->groupBy(fn (Menu $m) => $m->parent_id ?? 0);

        $visible = function (Menu $menu) use ($user, $scope): bool {
            $key = $menu->visibilityKey();

            return $menu->allowsScope($scope) && ($key === null || $user->hasPermission($key));
        };

        $build = function (Menu $menu) use (&$build, $byParent, $visible): ?array {
            if (! $visible($menu)) {
                return null;
            }
            $children = $byParent->get($menu->id, collect())->map($build)->filter()->values();
            // a parent shows only with a visible child; an entry without a page (an app such as DCS) never shows
            if ($children->isEmpty() && ! $menu->path) {
                return null;
            }

            return [
                'id' => $menu->id,
                'key' => $menu->key,
                'name' => $menu->name,
                'icon' => $menu->icon,
                'path' => $menu->path,
                'permission' => $menu->visibilityKey(),
                'children' => $children->all(),
            ];
        };

        /** @var Collection<int, Menu> $roots */
        $roots = $byParent->get(0, collect());

        return MenuGroup::query()->orderBy('order')->orderBy('id')->get()
            ->map(fn (MenuGroup $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'items' => $roots->where('menu_group_id', $group->id)->map($build)->filter()->values()->all(),
            ])
            ->filter(fn (array $group) => $group['items'] !== [])
            ->values()->all();
    }
}
