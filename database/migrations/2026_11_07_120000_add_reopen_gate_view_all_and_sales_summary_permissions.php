<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * New permissions and the Sales summary menu, for a database whose menus are already set up. Production's menus were
 * renamed and moved by hand, and MenuSeeder would add the catalog's groups and parents again, so only these are added.
 * Without menus yet (a fresh database) there is nothing to do: MenuSeeder creates everything.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addAction('flight_manager', 'reopen', 'Reopen closed flights', fn () => $this->rolesWith('flight_manager.cancel'));
        $this->addAction('gate', 'view_all', 'View all flights without being assigned (no boarding)',
            fn () => DB::table('roles')->where('slug', 'airline-admin')->pluck('id'));

        DB::table('permissions')->where('key', 'flight_manager.cancel')
            ->update(['name' => DB::raw("REPLACE(name, 'Cancel / reopen flights', 'Cancel flights')")]);

        $sales = DB::table('menus')->where('key', 'sales_report')->first();
        if ($sales && ! DB::table('menus')->where('key', 'sales_summary')->exists()) {
            // right after Sales report, wherever it was moved to
            DB::table('menus')->where('parent_id', $sales->parent_id)->where('menu_group_id', $sales->menu_group_id)
                ->where('order', '>', $sales->order)->increment('order');
            DB::table('menus')->insert([
                'parent_id' => $sales->parent_id,
                'menu_group_id' => $sales->menu_group_id,
                'key' => 'sales_summary',
                'name' => 'Sales summary',
                'path' => '/reports/sales-summary',
                'order' => $sales->order + 1,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->addAction('sales_summary', 'view', 'View', fn () => $this->rolesWith('sales_report.view'));
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('key', ['flight_manager.reopen', 'gate.view_all'])->delete();
        DB::table('menus')->where('key', 'sales_summary')->delete();
    }

    private function addAction(string $menuKey, string $action, string $label, Closure $roles): void
    {
        $menu = DB::table('menus')->where('key', $menuKey)->first();
        $key = "{$menuKey}.{$action}";
        if (! $menu || DB::table('permissions')->where('key', $key)->exists()) {
            return;
        }

        $id = DB::table('permissions')->insertGetId([
            'menu_id' => $menu->id,
            'key' => $key,
            'action' => $action,
            'name' => "{$menu->name}: {$label}",
            'category' => $menuKey,
            'order' => (int) DB::table('permissions')->where('menu_id', $menu->id)->max('order') + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('permission_role')->insert($roles()->map(fn ($role) => ['permission_id' => $id, 'role_id' => $role])->all());
    }

    private function rolesWith(string $key)
    {
        return DB::table('permission_role')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('permissions.key', $key)->pluck('permission_role.role_id');
    }
};
