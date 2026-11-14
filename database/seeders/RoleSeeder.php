<?php

namespace Database\Seeders;

use App\Enums\RoleScope;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions\MenuCatalog;
use Illuminate\Database\Seeder;

/**
 * Default roles (mirrors the legacy roles: system user, airline staff, agent, sub-agent, station, gate).
 * Written with the pre-menu keys and expanded through MenuCatalog::legacyMap(). Re-running it resets
 * these system roles' permissions, so production only needs MenuSeeder.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['slug' => 'system-admin', 'name' => 'System administrator', 'scope' => RoleScope::Admin, 'permissions' => []],
            ['slug' => 'airline-admin', 'name' => 'Airline administrator', 'scope' => RoleScope::Airline, 'permissions' => [
                'dashboard.view', 'airlines.manage', 'agencies.manage', 'users.manage', 'roles.manage', 'aircraft.manage', 'fares.manage',
                'products.manage', 'flights.view', 'flights.manage', 'flights.operate', 'flights.cancel', 'seat_blocks.manage',
                'allocations.manage', 'agency_accounts.manage', 'inventory.view', 'inventory.manage', 'bookings.view', 'bookings.manage', 'reports.view', 'activity_logs.view', 'issue_reports.view', 'issue_reports.manage',
                'announcements.manage', 'charters.view', 'charters.manage', 'dcs.access', 'dcs.checkin', 'gate.access', 'gate.view_all',
            ]],
            ['slug' => 'airline-staff', 'name' => 'Airline staff', 'scope' => RoleScope::Airline, 'permissions' => [
                'dashboard.view', 'flights.view', 'flights.operate', 'seat_blocks.manage', 'allocations.manage', 'inventory.view',
                'bookings.view', 'bookings.manage', 'reports.view', 'charters.view',
            ]],
            // operations control centre: the only role that closes check-in / the gate and departs flights
            ['slug' => 'occ', 'name' => 'OCC (operations control)', 'scope' => RoleScope::Airline, 'permissions' => [
                'dashboard.view', 'flights.view', 'flights.operate', 'flights.close', 'inventory.view', 'bookings.view',
                'reports.view', 'activity_logs.view', 'dcs.access',
            ]],
            ['slug' => 'station-agent', 'name' => 'Station check-in agent', 'scope' => RoleScope::Airport, 'permissions' => [
                'flights.view', 'flights.operate', 'bookings.view', 'reports.view', 'dcs.access', 'dcs.checkin', 'gate.access',
            ]],
            ['slug' => 'gate-agent', 'name' => 'Gate agent', 'scope' => RoleScope::Airport, 'permissions' => ['gate.access']],
            ['slug' => 'agency-admin', 'name' => 'Agency administrator', 'scope' => RoleScope::Agency, 'permissions' => [
                'agency.portal', 'users.manage', 'charters.manage', 'agency_conditions.manage',
            ]],
            ['slug' => 'agency-agent', 'name' => 'Agency ticketing agent', 'scope' => RoleScope::Agency, 'permissions' => ['agency.portal']],
        ];

        $map = MenuCatalog::legacyMap();
        foreach ($roles as $definition) {
            $role = Role::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                ['name' => $definition['name'], 'scope' => $definition['scope'], 'is_system' => true],
            );
            $keys = collect($definition['permissions'])->flatMap(fn (string $key) => $map[$key])->unique()->all();
            $role->permissions()->sync(Permission::query()->whereIn('key', $keys)->pluck('id'));
        }
    }
}
