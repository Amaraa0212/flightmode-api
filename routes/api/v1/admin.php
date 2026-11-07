<?php

use App\Http\Controllers\Api\V1\Admin;
use Illuminate\Support\Facades\Route;

/*
| Back office. Every route checks a menu permission (menus / permissions tables, App\Support\Permissions\MenuCatalog):
|   crud:flights            GET → flights.view, POST → flights.create, PUT / PATCH → flights.update, DELETE → flights.delete
|   crud:flights,lookup     GET also for any back-office user (lists other screens load into their selects)
|   crud:airlines,carriage  the same action of either menu
|   perm:a.view,b.update    any of the keys (when the HTTP method does not say the action)
*/

Route::get('dashboard', Admin\DashboardController::class)->middleware('perm:dashboard.view');

// Organisations & access
Route::apiResource('airlines', Admin\AirlineController::class)->only(['index', 'show'])->middleware('crud:airlines,carriage,bag_tag,lookup');
Route::apiResource('airlines', Admin\AirlineController::class)->only(['store', 'destroy'])->middleware('crud:airlines');
Route::put('airlines/{airline}', [Admin\AirlineController::class, 'update'])->middleware('crud:airlines,carriage,bag_tag');
Route::get('airlines/{airline}/agencies', [Admin\AirlineController::class, 'agencies'])->middleware('perm:agency_access.view,airlines.view');
Route::put('airlines/{airline}/agencies/{agency}', [Admin\AirlineController::class, 'setAgencyContract'])->middleware('perm:agency_access.update');
Route::delete('airlines/{airline}/agencies/{agency}', [Admin\AirlineController::class, 'removeAgencyContract'])->middleware('perm:agency_access.delete');
Route::apiResource('agencies', Admin\AgencyController::class)->middleware('crud:agencies,lookup');
Route::get('users/role-options', [Admin\UserController::class, 'roleOptions'])->middleware('perm:users.view');
Route::apiResource('users', Admin\UserController::class)->middleware('crud:users');
Route::middleware('perm:users.update')->group(function () {
    Route::post('users/{user}/api-token', [Admin\UserController::class, 'issueApiToken']);
    Route::delete('users/{user}/api-token', [Admin\UserController::class, 'revokeApiToken']);
    Route::delete('users/{user}/two-factor', [Admin\UserController::class, 'resetTwoFactor']);
});
Route::get('permissions', [Admin\RoleController::class, 'permissions'])->middleware('perm:roles.view');
Route::apiResource('roles', Admin\RoleController::class)->middleware('crud:roles');

// Menus (sidebar, permission catalogue) and airline IP restriction
Route::put('menus/order', [Admin\MenuController::class, 'reorder'])->middleware('perm:menus.update');
Route::middleware('crud:menus')->group(function () {
    Route::get('menus', [Admin\MenuController::class, 'index']);
    Route::post('menus', [Admin\MenuController::class, 'store']);
    Route::put('menus/{menu}', [Admin\MenuController::class, 'update']);
    Route::delete('menus/{menu}', [Admin\MenuController::class, 'destroy']);
    Route::post('menus/{menu}/actions', [Admin\MenuController::class, 'storeAction']);
    Route::delete('menus/{menu}/actions/{permission}', [Admin\MenuController::class, 'destroyAction']);
    Route::apiResource('menu-groups', Admin\MenuGroupController::class)->except(['show'])->parameters(['menu-groups' => 'menuGroup']);
});
Route::middleware('crud:ip_whitelist')->group(function () {
    Route::get('ip-access', [Admin\AirlineIpController::class, 'show']);
    Route::put('ip-access/{airline}', [Admin\AirlineIpController::class, 'setEnabled']);
    Route::post('ip-access/{airline}/addresses', [Admin\AirlineIpController::class, 'store']);
    Route::put('ip-access/{airline}/addresses/{address}', [Admin\AirlineIpController::class, 'update']);
    Route::delete('ip-access/{airline}/addresses/{address}', [Admin\AirlineIpController::class, 'destroy']);
});

// Reference data (read via /reference/*)
Route::apiResource('airports', Admin\AirportController::class)->middleware('crud:airports');
Route::apiResource('countries', Admin\CountryController::class)->only(['store', 'update', 'destroy'])->middleware('crud:airports');
Route::apiResource('ssr-codes', Admin\SsrCodeController::class)->only(['store', 'update', 'destroy'])->middleware('crud:ssr');

// Aircraft & seat maps
Route::apiResource('aircraft', Admin\AircraftController::class)->parameters(['aircraft' => 'aircraft'])->middleware('crud:aircraft,lookup');
Route::get('aircraft/{aircraft}/seats', [Admin\AircraftSeatController::class, 'index'])->middleware('crud:seat_maps,lookup');
Route::post('aircraft/{aircraft}/seats/generate', [Admin\AircraftSeatController::class, 'generate'])->middleware('crud:seat_maps');
Route::delete('aircraft/{aircraft}/seats', [Admin\AircraftSeatController::class, 'destroyRows'])->middleware('crud:seat_maps');
Route::patch('aircraft/{aircraft}/seats/{seat}', [Admin\AircraftSeatController::class, 'update'])->middleware('crud:seat_maps');
Route::get('aircraft/{aircraft}/load-zones', [Admin\AircraftLoadZoneController::class, 'index'])->middleware('crud:checkin_hierarchy,lookup');
Route::put('aircraft/{aircraft}/load-zones', [Admin\AircraftLoadZoneController::class, 'replace'])->middleware('crud:checkin_hierarchy');
Route::apiResource('aircraft.seat-assignment-profiles', Admin\SeatAssignmentProfileController::class)
    ->except(['show'])->parameters(['seat-assignment-profiles' => 'profile'])->middleware('crud:checkin_hierarchy,lookup');

// Fares
Route::apiResource('fares', Admin\FareController::class)->parameters(['fares' => 'id'])->middleware('crud:fares,fare_info');
Route::apiResource('fare-families', Admin\FareFamilyController::class)->parameters(['fare-families' => 'id'])->middleware('crud:fare_families,lookup');
Route::apiResource('rbds', Admin\RbdController::class)->parameters(['rbds' => 'id'])->middleware('crud:rbd_fare_class,lookup');
Route::apiResource('fare-classes', Admin\FareClassController::class)->parameters(['fare-classes' => 'id'])->middleware('crud:rbd_fare_class,lookup');
Route::apiResource('fare-classes.discounts', Admin\FareDiscountController::class)
    ->except(['show'])->parameters(['fare-classes' => 'fareClass'])->middleware('crud:rbd_fare_class');
Route::apiResource('fare-combinations', Admin\FareCombinationController::class)
    ->only(['index', 'store', 'destroy'])->parameters(['fare-combinations' => 'id'])->middleware('crud:fare_combinations');
Route::apiResource('tax-codes', Admin\TaxCodeController::class)->parameters(['tax-codes' => 'id'])->middleware('crud:tax_codes,lookup');
Route::apiResource('fare-taxes', Admin\FareTaxController::class)->parameters(['fare-taxes' => 'id'])->middleware('crud:fare_tax');
Route::apiResource('rbd-modifications', Admin\RbdModificationController::class)->parameters(['rbd-modifications' => 'id'])->middleware('crud:rbd_modifications');

// Seat inventory per flight (cabin seats + nested RBD limits) and its templates
Route::get('inventory/flights', [Admin\InventoryController::class, 'flights'])->middleware('perm:seat_inventory.view');
Route::get('flights/{flight}/inventory', [Admin\InventoryController::class, 'show'])->middleware('perm:seat_inventory.view');
Route::put('flights/{flight}/inventory/cabins', [Admin\InventoryController::class, 'saveCabins'])->middleware('perm:seat_inventory.update');
Route::put('flights/{flight}/inventory/rbds', [Admin\InventoryController::class, 'saveRbds'])->middleware('perm:seat_inventory.update');
Route::patch('flights/{flight}/inventory/rbds/{rbd}', [Admin\InventoryController::class, 'updateRbd'])->middleware('perm:seat_inventory.update');
Route::delete('flights/{flight}/inventory/rbds/{rbd}', [Admin\InventoryController::class, 'destroyRbd'])->middleware('perm:seat_inventory.delete');
Route::post('flights/{flight}/inventory/initialize', [Admin\InventoryController::class, 'initialize'])->middleware('perm:seat_inventory.create');
Route::post('flights/{flight}/inventory/roll-forward', [Admin\InventoryController::class, 'rollForward'])->middleware('perm:seat_inventory.create');
Route::delete('flights/{flight}/inventory', [Admin\InventoryController::class, 'reset'])->middleware('perm:seat_inventory.delete');
Route::post('inventory/apply-template', [Admin\InventoryController::class, 'applyTemplate'])->middleware('perm:seat_inventory.create');
Route::post('inventory-templates/from-flight/{flight}', [Admin\InventoryTemplateController::class, 'fromFlight'])->middleware('perm:seat_templates.create');
Route::apiResource('inventory-templates', Admin\InventoryTemplateController::class)->except(['store'])
    ->parameters(['inventory-templates' => 'id'])->middleware('crud:seat_templates,lookup');

// Rules & ancillary
Route::apiResource('baggage-rules', Admin\BaggageRuleController::class)->parameters(['baggage-rules' => 'id'])->middleware('crud:baggage_rules,lookup');
Route::apiResource('baggage-policies', Admin\BaggagePolicyController::class)->parameters(['baggage-policies' => 'id'])->middleware('crud:terms');
Route::apiResource('seat-prices', Admin\SeatPriceController::class)->parameters(['seat-prices' => 'id'])->middleware('crud:seat_rules,lookup');
Route::apiResource('service-templates', Admin\ServiceTemplateController::class)->parameters(['service-templates' => 'id'])->middleware('crud:templates,lookup');
Route::post('service-templates/{id}/apply', [Admin\ServiceTemplateController::class, 'apply'])->middleware('perm:flight_products.update,flight_manager.update');
Route::apiResource('airline-ssrs', Admin\AirlineSsrController::class)->except(['show'])->middleware('crud:ssr');
Route::put('flights/{flight}/products', [Admin\FlightController::class, 'updateProducts'])->middleware('perm:flight_products.update');

// Flights
Route::middleware('crud:flights,lookup')->group(function () {
    Route::get('flights', [Admin\FlightController::class, 'index']);
    Route::get('flights/latest', [Admin\FlightController::class, 'latest']);
    Route::get('flights/{flight}', [Admin\FlightController::class, 'show']);
    Route::get('flights/{flight}/statistics', [Admin\FlightController::class, 'statistics']);
    Route::get('flights/{flight}/fares', [Admin\FareController::class, 'forFlight']);
    Route::get('flights/{flight}/allocation-capacity', [Admin\FlightController::class, 'allocationCapacity']);
});
Route::post('flights', [Admin\FlightController::class, 'store'])->middleware('perm:flights.create');
Route::post('flights/bulk', [Admin\FlightController::class, 'storeMany'])->middleware('perm:flights.create');
Route::put('flights/{flight}', [Admin\FlightController::class, 'update'])->middleware('perm:flights.update');
Route::delete('flights/{flight}', [Admin\FlightController::class, 'destroy'])->middleware('perm:flights.delete');
Route::apiResource('flight-series', Admin\FlightSeriesController::class)->parameters(['flight-series' => 'flightSeries'])->middleware('crud:flight_series');

// Flight manager (DCS app uses the gate / gate-agent routes too)
Route::get('flight-board', [Admin\FlightOperationController::class, 'board'])->middleware('perm:flight_manager.view,flight_load.view');
Route::get('flight-board/calendar', [Admin\FlightOperationController::class, 'calendar'])->middleware('perm:flight_manager.view,flight_load.view');
Route::get('flights/{flight}/gate-agents/candidates', [Admin\FlightOperationController::class, 'gateAgentCandidates'])->middleware('perm:flight_manager.view');
Route::middleware('perm:flight_manager.update')->group(function () {
    Route::post('flights/{flight}/status', [Admin\FlightOperationController::class, 'changeStatus']);
    Route::put('flights/{flight}/gate', [Admin\FlightOperationController::class, 'setGate']);
    Route::put('flights/{flight}/estimated-departure', [Admin\FlightOperationController::class, 'setEstimatedDeparture']);
    Route::put('flights/{flight}/gate-agents', [Admin\FlightOperationController::class, 'setGateAgents']);
    Route::put('flights/{flight}/online-checkin', [Admin\FlightOperationController::class, 'setOnlineCheckin']);
});
Route::get('flights/{flight}/seat-map', [Admin\SeatBlockController::class, 'seatMap'])->middleware('perm:seat_blocks.view');
Route::post('flights/{flight}/seat-blocks', [Admin\SeatBlockController::class, 'block'])->middleware('perm:seat_blocks.create');
Route::delete('flights/{flight}/seat-blocks', [Admin\SeatBlockController::class, 'unblock'])->middleware('perm:seat_blocks.delete');

// Prepaid agency accounts
Route::get('agency-accounts', [Admin\AgencyAccountController::class, 'index'])->middleware('perm:agency_accounts.view');
Route::post('agency-accounts/top-up', [Admin\AgencyAccountController::class, 'topUp'])->middleware('perm:agency_accounts.create');
Route::get('agency-accounts/{account}/transactions', [Admin\AgencyAccountController::class, 'transactions'])->middleware('perm:agency_accounts.view');

// Agency allocations
Route::get('allocations-board', [Admin\AllocationController::class, 'board'])->middleware('perm:allocations.view');
Route::post('allocations/move', [Admin\AllocationController::class, 'move'])->middleware('perm:allocations.update');
Route::post('allocations/{allocation}/chinggis-push', [Admin\AllocationController::class, 'pushToChinggis'])->middleware('perm:allocations.update');
Route::get('flights/{flight}/allocations-overview', [Admin\AllocationController::class, 'flightOverview'])->middleware('perm:allocations.view');
Route::post('flights/{flight}/allocations/distribute', [Admin\AllocationController::class, 'distribute'])->middleware('perm:allocations.create');
Route::post('flight-series/{flightSeries}/allocations/distribute', [Admin\AllocationController::class, 'distributeSeries'])->middleware('perm:allocations.create');
Route::apiResource('allocations', Admin\AllocationController::class)->middleware('crud:allocations');
Route::get('allocations/{allocation}/passengers', [Admin\AllocationController::class, 'passengers'])->middleware('perm:allocations.view');

// Bookings & passengers
Route::middleware('perm:bookings.view')->group(function () {
    Route::get('bookings', [Admin\BookingController::class, 'index']);
    Route::get('bookings/{booking}', [Admin\BookingController::class, 'show']);
    Route::get('passengers/{passenger}', [Admin\PassengerController::class, 'show']);
    Route::get('passengers/{passenger}/activity', [Admin\PassengerController::class, 'activity']);
    Route::get('passengers/{passenger}/eticket', [Admin\PassengerController::class, 'eticket']);
});
Route::get('flights/{flight}/passengers', [Admin\FlightController::class, 'passengers'])
    ->middleware('perm:bookings.view,manifest.view,flight_load.view,pnl_report.view,flight_manager.view');
Route::apiResource('passenger-bans', Admin\PassengerBanController::class)->middleware('crud:bookings');
Route::post('bookings', [Admin\BookingController::class, 'store'])->middleware('perm:bookings.create');
Route::post('segments/waitlist', [Admin\BookingController::class, 'waitlist'])->middleware('perm:bookings.create');
Route::middleware('perm:bookings.update')->group(function () {
    Route::post('bookings/merge', [Admin\BookingController::class, 'merge']);
    Route::post('bookings/{booking}/issue', [Admin\BookingController::class, 'issue']);
    Route::post('passengers/{passenger}/split', [Admin\BookingController::class, 'split']);
    Route::put('passengers/{passenger}', [Admin\PassengerController::class, 'update']);
    Route::post('passengers/{passenger}/eticket/email', [Admin\PassengerController::class, 'emailEticket']);
    Route::post('segments/{segment}/rebook', [Admin\BookingController::class, 'rebook']);
});
Route::delete('passengers/{passenger}', [Admin\PassengerController::class, 'destroy'])->middleware('perm:bookings.delete');

// Documents & reports
Route::middleware('perm:manifest.view,pnl_report.view')->group(function () {
    Route::get('flights/{flight}/documents/pnl', [Admin\FlightDocumentController::class, 'pnl']);
    Route::get('flights/{flight}/documents/reconciliation', [Admin\FlightDocumentController::class, 'reconciliation']);
    Route::get('flights/{flight}/documents/passengers', [Admin\FlightDocumentController::class, 'passengers']);
    Route::get('flights/{flight}/documents/apis', [Admin\FlightDocumentController::class, 'apis']);
    Route::get('flights/{flight}/documents/manifest', [Admin\FlightDocumentController::class, 'manifest']);
    Route::get('flights/{flight}/documents/no-show', [Admin\FlightDocumentController::class, 'noShow']);
});
Route::post('flights/{flight}/documents/email', [Admin\FlightDocumentController::class, 'email'])->middleware('perm:manifest.export');

Route::get('reports/routes', [Admin\ReportController::class, 'routes'])
    ->middleware('perm:traffic_report.view,sales_report.view,station_report.view,dashboard.view');
Route::get('reports/traffic', [Admin\ReportController::class, 'traffic'])->middleware('perm:traffic_report.view,dashboard.view');
Route::get('reports/fare-sales', [Admin\ReportController::class, 'fareSales'])->middleware('perm:sales_report.view');
Route::get('reports/online-checkin', [Admin\ReportController::class, 'onlineCheckin'])->middleware('perm:dashboard.view,traffic_report.view');
Route::get('reports/passenger-search', [Admin\ReportController::class, 'passengerSearch'])->middleware('perm:pax_search.view');

Route::get('activity-logs', [Admin\ActivityLogController::class, 'index'])->middleware('perm:activity_logs.view');
Route::get('issue-reports', [Admin\IssueReportController::class, 'index'])->middleware('perm:issue_reports.view');
Route::get('issue-reports/{issueReport}', [Admin\IssueReportController::class, 'show'])->middleware('perm:issue_reports.view');
Route::patch('issue-reports/{issueReport}', [Admin\IssueReportController::class, 'update'])->middleware('perm:issue_reports.update');
Route::apiResource('announcements', Admin\AnnouncementController::class)->except(['show'])->middleware('crud:announcements');

// Charter requests
Route::get('charter-requests', [Admin\CharterRequestController::class, 'index'])->middleware('perm:charters.view');
Route::get('charter-requests/{charterRequest}', [Admin\CharterRequestController::class, 'show'])->middleware('perm:charters.view');
Route::post('charter-requests', [Admin\CharterRequestController::class, 'store'])->middleware('perm:charters.create');
Route::patch('charter-requests/{charterRequest}/status', [Admin\CharterRequestController::class, 'updateStatus'])->middleware('perm:charters.update');
