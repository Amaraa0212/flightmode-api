<?php

use App\Enums\RoleScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Flight manager split: closing check-in / the gate and departing go to the new OCC role, cancelling and
| reopening flights to the airline administrator. Existing databases get the permissions and the role
| (RoleSeeder does the same on a fresh database). Only adds rows: roles changed by hand keep the rest.
*/
return new class extends Migration
{
    private const PERMISSIONS = [
        'flights.close' => 'Close check-in / close gate / depart (OCC)',
        'flights.cancel' => 'Cancel flights and reopen closed ones',
    ];

    private const OCC = ['dashboard.view', 'flights.view', 'flights.operate', 'flights.close', 'inventory.view', 'bookings.view',
        'reports.view', 'activity_logs.view', 'dcs.access'];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $key => $name) {
            DB::table('permissions')->updateOrInsert(['key' => $key], ['name' => $name, 'category' => 'flights', 'updated_at' => now()]);
        }

        if (! DB::table('roles')->where('slug', 'occ')->exists()) {
            DB::table('roles')->insert(['slug' => 'occ', 'name' => 'OCC (operations control)', 'scope' => RoleScope::Airline->value,
                'is_system' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->grant('occ', self::OCC);
        $this->grant('airline-admin', ['flights.cancel']);
    }

    public function down(): void
    {
        DB::table('roles')->where('slug', 'occ')->delete();
        DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->delete();
    }

    /** @param list<string> $keys */
    private function grant(string $slug, array $keys): void
    {
        $roleId = DB::table('roles')->where('slug', $slug)->value('id');
        if (! $roleId) {
            return;
        }
        foreach (DB::table('permissions')->whereIn('key', $keys)->pluck('id') as $permissionId) {
            DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $roleId]);
        }
    }
};
