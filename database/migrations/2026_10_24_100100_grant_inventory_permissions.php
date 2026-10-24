<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Adds the seat inventory permissions on existing databases and gives them to the default airline roles
| (RoleSeeder does the same on a fresh database). Only adds rows: roles changed by hand keep their other permissions.
*/
return new class extends Migration
{
    private const PERMISSIONS = [
        'inventory.view' => 'View seat inventory (cabin seats, class limits)',
        'inventory.manage' => 'Manage seat inventory, roll forward, templates',
    ];

    private const GRANTS = [
        'airline-admin' => ['inventory.view', 'inventory.manage'],
        'airline-staff' => ['inventory.view'],
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $key => $name) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['name' => $name, 'category' => 'inventory', 'updated_at' => now()]);
        }

        $permissionIds = DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->pluck('id', 'key');
        foreach (self::GRANTS as $slug => $keys) {
            $roleId = DB::table('roles')->where('slug', $slug)->value('id');
            if (! $roleId) {
                continue;
            }
            foreach ($keys as $key) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionIds[$key], 'role_id' => $roleId]);
            }
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->delete();
    }
};
