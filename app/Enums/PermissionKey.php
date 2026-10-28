<?php

namespace App\Enums;

/**
 * Every permission the API checks. Seeded into `permissions` by PermissionSeeder
 * and used in routes as `can:<value>`.
 */
enum PermissionKey: string
{
    // Back office
    case DashboardView = 'dashboard.view';
    case AirlinesManage = 'airlines.manage';
    case AgenciesManage = 'agencies.manage';
    case UsersManage = 'users.manage';
    case RolesManage = 'roles.manage';
    case ReferenceManage = 'reference.manage';
    case AircraftManage = 'aircraft.manage';
    case FaresManage = 'fares.manage';
    case ProductsManage = 'products.manage';
    case FlightsView = 'flights.view';
    case FlightsManage = 'flights.manage';
    case FlightOperations = 'flights.operate';
    case FlightsClose = 'flights.close';
    case FlightsCancel = 'flights.cancel';
    case SeatBlocksManage = 'seat_blocks.manage';
    case AllocationsManage = 'allocations.manage';
    case AgencyAccountsManage = 'agency_accounts.manage';
    case InventoryView = 'inventory.view';
    case InventoryManage = 'inventory.manage';
    case BookingsView = 'bookings.view';
    case BookingsManage = 'bookings.manage';
    case ReportsView = 'reports.view';
    case ActivityLogsView = 'activity_logs.view';
    case IssueReportsView = 'issue_reports.view';
    case IssueReportsManage = 'issue_reports.manage';
    case AnnouncementsManage = 'announcements.manage';
    case ChartersView = 'charters.view';
    case ChartersManage = 'charters.manage';

    // Agency portal
    case AgencyPortal = 'agency.portal';

    // Airport
    case DcsAccess = 'dcs.access';
    case DcsCheckin = 'dcs.checkin';
    case GateAccess = 'gate.access';

    public function label(): string
    {
        return match ($this) {
            self::DashboardView => 'Dashboard',
            self::AirlinesManage => 'Manage airlines',
            self::AgenciesManage => 'Manage agencies',
            self::UsersManage => 'Manage users',
            self::RolesManage => 'Manage roles & permissions',
            self::ReferenceManage => 'Manage airports, countries, SSR codes',
            self::AircraftManage => 'Manage aircraft & seat maps',
            self::FaresManage => 'Manage fares',
            self::ProductsManage => 'Manage baggage rules, seat prices, SSR prices, templates',
            self::FlightsView => 'View flights',
            self::FlightsManage => 'Create / edit flights',
            self::FlightOperations => 'Flight manager (open check-in, gate, gate agents)',
            self::FlightsClose => 'Close check-in / close gate / depart (OCC)',
            self::FlightsCancel => 'Cancel flights and reopen closed ones',
            self::SeatBlocksManage => 'Block seats',
            self::AllocationsManage => 'Manage agency allocations',
            self::AgencyAccountsManage => 'Agency accounts: top up / adjust balances',
            self::InventoryView => 'View seat inventory (cabin seats, class limits)',
            self::InventoryManage => 'Manage seat inventory, roll forward, templates',
            self::BookingsView => 'View bookings & passengers',
            self::BookingsManage => 'Edit bookings & passengers',
            self::ReportsView => 'Reports',
            self::ActivityLogsView => 'Activity log',
            self::IssueReportsView => 'Reported issues',
            self::IssueReportsManage => 'Process reported issues',
            self::AnnouncementsManage => 'Manage announcements',
            self::ChartersView => 'View charter requests',
            self::ChartersManage => 'Create / process charter requests',
            self::AgencyPortal => 'Agency portal',
            self::DcsAccess => 'DCS: view',
            self::DcsCheckin => 'DCS: check-in, baggage, open / close counter',
            self::GateAccess => 'Gate app',
        };
    }

    public function category(): string
    {
        return explode('.', $this->value)[0];
    }
}
