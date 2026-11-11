<?php

namespace App\Support\Permissions;

/**
 * The menus and permissions the code knows about. MenuSeeder writes them to menu_groups / menus / permissions;
 * after that the menu tree (names, icons, order, groups) is edited from the back office, while the keys stay
 * as they are here because routes check them (`crud:flights`, `perm:flights.view`, `can:dcs.access`).
 */
final class MenuCatalog
{
    public const CRUD = ['view', 'create', 'update', 'delete'];

    public const ACTION_LABELS = [
        'view' => 'View',
        'create' => 'Create',
        'update' => 'Update',
        'delete' => 'Delete',
    ];

    /**
     * Menu groups in sidebar order. A menu: key (permission prefix), name, icon, path, actions
     * (list or action => label), permission (key needed to see it when it owns none), scopes, children.
     *
     * @return list<array{name: string, menus: list<array<string, mixed>>}>
     */
    public static function groups(): array
    {
        $crud = self::CRUD;

        return [
            ['name' => 'General', 'menus' => [
                ['key' => 'dashboard', 'name' => 'Dashboard', 'icon' => 'LayoutDashboard', 'path' => '/', 'actions' => ['view']],
            ]],
            // travel agents: their seat quotas and the passengers sold on them (agency.portal)
            ['name' => 'Agency', 'menus' => [
                ['key' => 'agency_home', 'name' => 'Нүүр', 'icon' => 'House', 'path' => '/agency', 'actions' => ['view'], 'scopes' => ['agency']],
                ['key' => 'agency_booking', 'name' => 'Шинэ захиалга', 'icon' => 'PlaneTakeoff', 'path' => '/agency/booking', 'actions' => ['view'], 'scopes' => ['agency']],
                ['key' => 'agency_bookings', 'name' => 'Захиалга удирдах', 'icon' => 'FileSearch', 'path' => '/agency/bookings', 'actions' => ['view'], 'scopes' => ['agency']],
                ['key' => 'agency_search', 'name' => 'Суудал hold хийх', 'icon' => 'Search', 'path' => '/agency/search', 'actions' => ['view'], 'scopes' => ['agency']],
                ['key' => 'agency_holds', 'name' => 'Hold-ууд', 'icon' => 'Timer', 'path' => '/agency/holds', 'actions' => ['view'], 'scopes' => ['agency']],
                ['key' => 'agency_quotas', 'name' => 'Миний квот', 'icon' => 'Ticket', 'path' => '/agency/quotas', 'actions' => ['view'], 'scopes' => ['agency']],
                ['key' => 'agency_account', 'name' => 'Миний данс', 'icon' => 'Wallet', 'path' => '/agency/account', 'actions' => ['view'], 'scopes' => ['agency']],
            ]],
            ['name' => 'Aircraft management', 'menus' => [
                ['key' => 'aircraft', 'name' => 'Fleets', 'icon' => 'Plane', 'path' => '/fleet/aircraft', 'actions' => $crud],
                ['key' => 'seat_maps', 'name' => 'Seatmap', 'icon' => 'Armchair', 'path' => '/fleet/seat-maps', 'actions' => $crud],
            ]],
            ['name' => 'Flight', 'menus' => [
                ['name' => 'Flight schedule', 'icon' => 'CalendarClock', 'children' => [
                    ['key' => 'flights', 'name' => 'All flights', 'path' => '/operations/flights', 'actions' => $crud],
                    ['key' => 'flight_series', 'name' => 'Frequency-based flight', 'path' => '/operations/flight-series', 'actions' => $crud],
                    ['key' => 'allocations', 'name' => 'Assign quota to agency', 'path' => '/operations/allocations', 'actions' => $crud],
                ]],
                ['key' => 'flight_load', 'name' => 'Flight load', 'icon' => 'Gauge', 'path' => '/operations/flight-load', 'actions' => ['view']],
                ['key' => 'charters', 'name' => 'Charter requests', 'icon' => 'PlaneTakeoff', 'path' => '/requests/charter', 'actions' => ['view', 'create', 'update']],
            ]],
            ['name' => 'DCS', 'menus' => [
                ['key' => 'flight_manager', 'name' => 'Flight manager', 'icon' => 'TowerControl', 'path' => '/dcs/flight-manager', 'actions' => [
                    'view' => 'View', 'update' => 'Open check-in, gate, ETD, gate agents',
                    'close' => 'Close check-in / gate, depart (OCC)', 'cancel' => 'Cancel flights', 'reopen' => 'Reopen closed flights',
                ]],
                ['key' => 'seat_blocks', 'name' => 'Seat block', 'icon' => 'Map', 'path' => '/dcs/seat-block', 'actions' => ['view', 'create', 'delete']],
                ['key' => 'manifest', 'name' => 'Manifest', 'icon' => 'ClipboardList', 'path' => '/dcs/manifest', 'actions' => ['view', 'export' => 'E-mail documents']],
                ['key' => 'activity_logs', 'name' => 'DCS history log', 'icon' => 'BookOpenText', 'path' => '/dcs/activity-log', 'actions' => ['view']],
            ]],
            ['name' => 'Airport', 'menus' => [
                ['key' => 'airlines', 'name' => 'Company', 'icon' => 'Building2', 'path' => '/company/airlines', 'actions' => $crud],
                ['key' => 'airports', 'name' => 'Airports', 'icon' => 'PlaneTakeoff', 'path' => '/company/airports', 'actions' => $crud],
                ['key' => 'agencies', 'name' => 'Agencies', 'icon' => 'Handshake', 'path' => '/company/agencies', 'actions' => $crud],
                ['key' => 'agency_accounts', 'name' => 'Agency accounts', 'icon' => 'Wallet', 'path' => '/company/agency-accounts', 'actions' => ['view', 'create' => 'Top up / adjust']],
                ['key' => 'agency_access', 'name' => 'Agents permission', 'icon' => 'BadgeCheck', 'path' => '/company/agency-access', 'actions' => ['view', 'update', 'delete']],
            ]],
            ['name' => 'Rules and ancillary', 'menus' => [
                ['name' => 'Rules', 'icon' => 'SlidersHorizontal', 'children' => [
                    ['key' => 'checkin_hierarchy', 'name' => 'Check-in hierarchy', 'path' => '/rules/checkin-hierarchy', 'actions' => $crud],
                    ['key' => 'baggage_rules', 'name' => 'Baggage rules', 'path' => '/rules/baggage-rules', 'actions' => $crud],
                    ['key' => 'seat_rules', 'name' => 'Seat rules', 'path' => '/rules/seat-rules', 'actions' => $crud],
                    ['key' => 'ssr', 'name' => 'SSR', 'path' => '/rules/ssr', 'actions' => $crud],
                    ['key' => 'templates', 'name' => 'Template', 'path' => '/rules/templates', 'actions' => $crud],
                    ['key' => 'bag_tag', 'name' => 'Bag Tag Orientation', 'path' => '/rules/bag-tag', 'actions' => $crud],
                ]],
                ['key' => 'flight_products', 'name' => 'Shop items', 'icon' => 'PackageOpen', 'path' => '/rules/flight-products', 'actions' => ['view', 'update']],
            ]],
            ['name' => 'Inventory', 'menus' => [
                ['name' => 'Fare settings', 'icon' => 'Tags', 'children' => [
                    ['key' => 'fare_families', 'name' => 'Fare Family', 'path' => '/fare-settings/fare-families', 'actions' => $crud],
                    ['key' => 'rbd_fare_class', 'name' => 'Booking Class - Fare Base', 'path' => '/fare-settings/rbd-fare-class', 'actions' => $crud],
                    ['key' => 'fare_combinations', 'name' => 'Combination Fare', 'path' => '/fare-settings/combinations', 'actions' => ['view', 'create', 'delete']],
                    ['key' => 'fare_tax', 'name' => 'Fare + Fare Tax', 'path' => '/fare-settings/fare-tax', 'actions' => $crud],
                    ['key' => 'tax_codes', 'name' => 'Tax Code', 'path' => '/fare-settings/tax-codes', 'actions' => $crud],
                    ['key' => 'rbd_modifications', 'name' => 'Modification', 'path' => '/fare-settings/modifications', 'actions' => $crud],
                    ['key' => 'fares', 'name' => 'Fare and pricing (old)', 'path' => '/inventory/fares', 'actions' => $crud],
                ]],
                ['name' => 'Seat allocations', 'icon' => 'Armchair', 'children' => [
                    ['key' => 'seat_inventory', 'name' => 'By flight', 'path' => '/inventory/seat-allocations', 'actions' => [
                        'view', 'create' => 'Initialize / roll forward / apply template', 'update', 'delete' => 'Remove RBD / reset',
                    ]],
                    ['key' => 'seat_templates', 'name' => 'Templates', 'path' => '/inventory/seat-templates', 'actions' => $crud],
                ]],
                ['key' => 'bookings', 'name' => 'Modification', 'icon' => 'Ticket', 'path' => '/inventory/bookings', 'actions' => $crud],
                ['key' => 'baggage_overrides', 'name' => 'Override baggage allowance', 'icon' => 'Luggage', 'path' => '/inventory/baggage-overrides', 'actions' => $crud],
                ['key' => 'carriage', 'name' => 'Conditions of carriage', 'icon' => 'Scale', 'path' => '/inventory/carriage', 'actions' => ['view', 'update']],
            ]],
            ['name' => 'Reports', 'menus' => [
                ['name' => 'Reports', 'icon' => 'BarChart3', 'children' => [
                    ['key' => 'traffic_report', 'name' => 'Traffic & PAX summary', 'path' => '/reports/traffic', 'actions' => ['view']],
                    ['key' => 'pnl_report', 'name' => 'PNL report', 'path' => '/reports/pnl', 'actions' => ['view']],
                    ['key' => 'sales_report', 'name' => 'Sales report', 'path' => '/reports/sales', 'actions' => ['view']],
                    ['key' => 'sales_summary', 'name' => 'Sales summary', 'path' => '/reports/sales-summary', 'actions' => ['view']],
                    ['key' => 'station_report', 'name' => 'Station report', 'path' => '/reports/station', 'actions' => ['view']],
                    ['key' => 'fare_info', 'name' => 'Fare info', 'path' => '/reports/fare-info', 'actions' => ['view']],
                ]],
                ['key' => 'pax_search', 'name' => 'PAX search', 'icon' => 'Search', 'path' => '/reports/pax-search', 'actions' => ['view']],
            ]],
            ['name' => 'Access', 'menus' => [
                ['key' => 'users', 'name' => 'Users', 'icon' => 'Users', 'path' => '/access/users', 'actions' => $crud],
                ['key' => 'roles', 'name' => 'Roles', 'icon' => 'ShieldCheck', 'path' => '/access/roles', 'actions' => $crud, 'scopes' => ['admin', 'airline']],
                ['key' => 'ip_whitelist', 'name' => 'IP access', 'icon' => 'Network', 'path' => '/access/ip-whitelist', 'actions' => $crud, 'scopes' => ['admin', 'airline']],
                ['key' => 'menus', 'name' => 'Menus', 'icon' => 'ListTree', 'path' => '/access/menus', 'actions' => $crud, 'scopes' => ['admin']],
            ]],
            ['name' => 'System', 'menus' => [
                ['key' => 'announcements', 'name' => 'Notifications', 'icon' => 'Bell', 'path' => '/system/notifications', 'actions' => $crud],
                ['key' => 'terms', 'name' => 'Terms & condition', 'icon' => 'FileText', 'path' => '/system/terms', 'actions' => $crud],
                ['key' => 'issue_reports', 'name' => 'Issue reports', 'icon' => 'Bug', 'path' => '/system/issue-reports', 'actions' => ['view', 'update'], 'scopes' => ['admin']],
            ]],
            // other apps: no sidebar entry, only permissions
            ['name' => 'Applications', 'menus' => [
                ['key' => 'agency', 'name' => 'Agency portal', 'icon' => 'Handshake', 'actions' => ['portal' => 'Agency portal']],
                ['key' => 'dcs', 'name' => 'DCS app', 'icon' => 'MonitorCheck', 'actions' => [
                    'access' => 'View', 'checkin' => 'Check-in, baggage, open / close counter',
                ]],
                ['key' => 'gate', 'name' => 'Gate app', 'icon' => 'DoorOpen', 'actions' => ['access' => 'Gate app', 'view_all' => 'View all flights without being assigned (no boarding)']],
            ]],
        ];
    }

    /**
     * The keys before menus (App\Enums\PermissionKey) → the keys that replace them. MenuSeeder applies it once,
     * while any old key is still in the permissions table, so existing roles keep what they could do.
     *
     * @return array<string, list<string>>
     */
    public static function legacyMap(): array
    {
        $all = fn (string ...$menus) => array_merge(...array_map(
            fn ($m) => array_map(fn ($a) => "$m.$a", self::CRUD), $menus,
        ));

        return [
            'dashboard.view' => ['dashboard.view'],
            'airlines.manage' => [...$all('airlines', 'ip_whitelist'), 'agency_access.view', 'agency_access.update', 'agency_access.delete'],
            'agencies.manage' => $all('agencies'),
            'users.manage' => $all('users'),
            'roles.manage' => $all('roles'),
            'reference.manage' => $all('airports', 'terms'),
            'aircraft.manage' => $all('aircraft', 'seat_maps'),
            'fares.manage' => [
                ...$all('fare_families', 'rbd_fare_class', 'fare_tax', 'tax_codes', 'rbd_modifications', 'fares'),
                'fare_combinations.view', 'fare_combinations.create', 'fare_combinations.delete', 'fare_info.view',
            ],
            'products.manage' => [
                ...$all('checkin_hierarchy', 'baggage_rules', 'seat_rules', 'ssr', 'templates', 'bag_tag', 'baggage_overrides', 'terms'),
                'flight_products.view', 'flight_products.update', 'carriage.view', 'carriage.update',
            ],
            'flights.view' => ['flights.view', 'flight_load.view'],
            'flights.manage' => ['flights.create', 'flights.update', 'flights.delete', ...$all('flight_series')],
            'flights.operate' => ['flight_manager.view', 'flight_manager.update'],
            'flights.close' => ['flight_manager.close'],
            'flights.cancel' => ['flight_manager.cancel', 'flight_manager.reopen'],
            'seat_blocks.manage' => ['seat_blocks.view', 'seat_blocks.create', 'seat_blocks.delete'],
            'allocations.manage' => $all('allocations'),
            'agency_accounts.manage' => ['agency_accounts.view', 'agency_accounts.create'],
            'inventory.view' => ['seat_inventory.view', 'seat_templates.view'],
            'inventory.manage' => [
                'seat_inventory.create', 'seat_inventory.update', 'seat_inventory.delete',
                'seat_templates.create', 'seat_templates.update', 'seat_templates.delete',
            ],
            'bookings.view' => ['bookings.view'],
            'bookings.manage' => ['bookings.create', 'bookings.update', 'bookings.delete'],
            'reports.view' => [
                'traffic_report.view', 'pnl_report.view', 'sales_report.view', 'sales_summary.view', 'station_report.view',
                'pax_search.view', 'manifest.view', 'manifest.export',
            ],
            'activity_logs.view' => ['activity_logs.view'],
            'issue_reports.view' => ['issue_reports.view'],
            'issue_reports.manage' => ['issue_reports.update'],
            'announcements.manage' => $all('announcements'),
            'charters.view' => ['charters.view'],
            'charters.manage' => ['charters.create', 'charters.update'],
            'agency.portal' => [
                'agency.portal', 'agency_home.view', 'agency_booking.view', 'agency_bookings.view', 'agency_search.view',
                'agency_holds.view', 'agency_quotas.view', 'agency_account.view',
            ],
            'dcs.access' => ['dcs.access'],
            'dcs.checkin' => ['dcs.checkin'],
            'gate.access' => ['gate.access'],
            'gate.view_all' => ['gate.view_all'],
        ];
    }

    /** @return array<string, string> action => label of a catalogue menu */
    public static function actions(array $menu): array
    {
        $actions = [];
        foreach ($menu['actions'] ?? [] as $action => $label) {
            if (is_int($action)) {
                [$action, $label] = [$label, self::ACTION_LABELS[$label] ?? ucfirst($label)];
            }
            $actions[$action] = $label;
        }

        return $actions;
    }

    /** @return list<string> keys of the catalogue menus */
    public static function menuKeys(): array
    {
        return array_values(array_unique(array_map(fn (string $key) => strstr($key, '.', true), self::keys())));
    }

    /** @return list<string> every permission key in the catalogue */
    public static function keys(): array
    {
        $keys = [];
        $walk = function (array $menus) use (&$walk, &$keys) {
            foreach ($menus as $menu) {
                foreach (array_keys(self::actions($menu)) as $action) {
                    $keys[] = $menu['key'].'.'.$action;
                }
                $walk($menu['children'] ?? []);
            }
        };
        foreach (self::groups() as $group) {
            $walk($group['menus']);
        }

        return $keys;
    }
}
