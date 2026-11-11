<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Agency portal menus get a view permission each (they all needed only agency.portal), so a role can be given some of
 * them only; every role with agency.portal keeps all of them. Menus are found by path: their names may have been edited.
 */
return new class extends Migration
{
    private const MENUS = [
        '/agency' => 'agency_home',
        '/agency/booking' => 'agency_booking',
        '/agency/bookings' => 'agency_bookings',
        '/agency/search' => 'agency_search',
        '/agency/holds' => 'agency_holds',
        '/agency/quotas' => 'agency_quotas',
        '/agency/account' => 'agency_account',
    ];

    public function up(): void
    {
        // roles that still exist (permission_role may keep rows of roles deleted by hand)
        $portalRoles = DB::table('permission_role')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->join('roles', 'roles.id', '=', 'permission_role.role_id')
            ->where('permissions.key', 'agency.portal')->pluck('permission_role.role_id')->unique();

        // safe to run again after a failed run: each step only adds what is missing
        foreach (self::MENUS as $path => $key) {
            $menu = DB::table('menus')->where('path', $path)->first();
            if (! $menu || ($menu->key && $menu->key !== $key)) {
                continue;
            }
            DB::table('menus')->where('id', $menu->id)->update(['key' => $key, 'permission_key' => null, 'updated_at' => now()]);
            $id = DB::table('permissions')->where('key', "{$key}.view")->value('id') ?? DB::table('permissions')->insertGetId([
                'menu_id' => $menu->id,
                'key' => "{$key}.view",
                'action' => 'view',
                'name' => "{$menu->name}: View",
                'category' => $key,
                'order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->grant($id, $portalRoles);
        }

        $this->agencyRoles();
    }

    /** @param  Collection<int, int>  $roles */
    private function grant(int $permissionId, $roles): void
    {
        $has = DB::table('permission_role')->where('permission_id', $permissionId)->pluck('role_id');
        DB::table('permission_role')->insert($roles->diff($has)->map(fn ($role) => ['permission_id' => $permissionId, 'role_id' => $role])->values()->all());
    }

    /**
     * An agency administrator adds their ticketing agents (the users page, kept to their own agency), and there is a
     * ticketing agent role to give them — production's agency roles were set up again by hand with the portal only.
     */
    private function agencyRoles(): void
    {
        $users = DB::table('permissions')->whereIn('key', ['users.view', 'users.create', 'users.update', 'users.delete'])->pluck('id');
        $admins = DB::table('roles')->where('slug', 'agency-admin')->pluck('id');
        $users->each(fn ($permission) => $this->grant($permission, $admins));

        if (! DB::table('roles')->where('slug', 'agency-admin')->exists() || DB::table('roles')->where('slug', 'agency-agent')->exists()) {
            return;
        }
        $agent = DB::table('roles')->insertGetId([
            'slug' => 'agency-agent',
            'name' => 'Agency ticketing agent',
            'scope' => 'agency',
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $portal = DB::table('permissions')->where('key', 'agency.portal')->orWhere(fn ($q) => $q->whereIn('key', array_map(fn ($k) => "{$k}.view", self::MENUS)))->pluck('id');
        DB::table('permission_role')->insert($portal->map(fn ($p) => ['permission_id' => $p, 'role_id' => $agent])->all());
    }

    public function down(): void
    {
        foreach (self::MENUS as $path => $key) {
            DB::table('permissions')->where('key', "{$key}.view")->delete();
            DB::table('menus')->where('path', $path)->where('key', $key)->update(['key' => null, 'permission_key' => 'agency.portal']);
        }
    }
};
