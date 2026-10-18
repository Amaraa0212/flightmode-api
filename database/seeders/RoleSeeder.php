<?php

namespace Database\Seeders;

use App\Enums\PermissionKey as P;
use App\Enums\RoleScope;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/** Default roles (mirrors the legacy roles: system user, airline staff, agent, sub-agent, station, gate). */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['slug' => 'system-admin', 'name' => 'System administrator', 'scope' => RoleScope::Admin, 'permissions' => []],
            ['slug' => 'airline-admin', 'name' => 'Airline administrator', 'scope' => RoleScope::Airline, 'permissions' => [
                P::DashboardView, P::AirlinesManage, P::AgenciesManage, P::UsersManage, P::AircraftManage, P::FaresManage,
                P::ProductsManage, P::FlightsView, P::FlightsManage, P::FlightOperations, P::SeatBlocksManage,
                P::AllocationsManage, P::BookingsView, P::BookingsManage, P::ReportsView, P::ActivityLogsView, P::IssueReportsView, P::IssueReportsManage,
                P::AnnouncementsManage, P::ChartersView, P::ChartersManage, P::DcsAccess, P::DcsCheckin, P::GateAccess,
            ]],
            ['slug' => 'airline-staff', 'name' => 'Airline staff', 'scope' => RoleScope::Airline, 'permissions' => [
                P::DashboardView, P::FlightsView, P::FlightOperations, P::SeatBlocksManage, P::AllocationsManage,
                P::BookingsView, P::BookingsManage, P::ReportsView, P::ChartersView,
            ]],
            ['slug' => 'station-agent', 'name' => 'Station check-in agent', 'scope' => RoleScope::Airport, 'permissions' => [
                P::FlightsView, P::FlightOperations, P::BookingsView, P::ReportsView, P::DcsAccess, P::DcsCheckin, P::GateAccess,
            ]],
            ['slug' => 'gate-agent', 'name' => 'Gate agent', 'scope' => RoleScope::Airport, 'permissions' => [P::GateAccess]],
            ['slug' => 'agency-admin', 'name' => 'Agency administrator', 'scope' => RoleScope::Agency, 'permissions' => [
                P::AgencyPortal, P::UsersManage, P::ChartersManage,
            ]],
            ['slug' => 'agency-agent', 'name' => 'Agency ticketing agent', 'scope' => RoleScope::Agency, 'permissions' => [P::AgencyPortal]],
        ];

        foreach ($roles as $definition) {
            $role = Role::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                ['name' => $definition['name'], 'scope' => $definition['scope'], 'is_system' => true],
            );
            $keys = array_map(fn (P $p) => $p->value, $definition['permissions']);
            $role->permissions()->sync(Permission::query()->whereIn('key', $keys)->pluck('id'));
        }
    }
}
