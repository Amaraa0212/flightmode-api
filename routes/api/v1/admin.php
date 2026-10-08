<?php

use App\Http\Controllers\Api\V1\Admin;
use Illuminate\Support\Facades\Route;

/*
| Back office. Every route checks a permission from App\Enums\PermissionKey.
*/

Route::get('dashboard', Admin\DashboardController::class)->middleware('can:dashboard.view');

// Organisations & access
Route::middleware('can:airlines.manage')->group(function () {
    Route::apiResource('airlines', Admin\AirlineController::class);
    Route::get('airlines/{airline}/agencies', [Admin\AirlineController::class, 'agencies']);
    Route::put('airlines/{airline}/agencies/{agency}', [Admin\AirlineController::class, 'setAgencyContract']);
    Route::delete('airlines/{airline}/agencies/{agency}', [Admin\AirlineController::class, 'removeAgencyContract']);
});
Route::apiResource('agencies', Admin\AgencyController::class)->middleware('can:agencies.manage');
Route::middleware('can:users.manage')->group(function () {
    Route::apiResource('users', Admin\UserController::class);
    Route::post('users/{user}/api-token', [Admin\UserController::class, 'issueApiToken']);
    Route::delete('users/{user}/api-token', [Admin\UserController::class, 'revokeApiToken']);
});
Route::middleware('can:roles.manage')->group(function () {
    Route::get('permissions', [Admin\RoleController::class, 'permissions']);
    Route::apiResource('roles', Admin\RoleController::class);
});

// Reference data (read via /reference/*)
Route::middleware('can:reference.manage')->group(function () {
    Route::apiResource('airports', Admin\AirportController::class);
    Route::apiResource('countries', Admin\CountryController::class)->only(['store', 'update', 'destroy']);
    Route::apiResource('ssr-codes', Admin\SsrCodeController::class)->only(['store', 'update', 'destroy']);
});

// Aircraft & seat maps
Route::middleware('can:aircraft.manage')->group(function () {
    Route::apiResource('aircraft', Admin\AircraftController::class)->parameters(['aircraft' => 'aircraft']);
    Route::get('aircraft/{aircraft}/seats', [Admin\AircraftSeatController::class, 'index']);
    Route::post('aircraft/{aircraft}/seats/generate', [Admin\AircraftSeatController::class, 'generate']);
    Route::delete('aircraft/{aircraft}/seats', [Admin\AircraftSeatController::class, 'destroyRows']);
    Route::patch('aircraft/{aircraft}/seats/{seat}', [Admin\AircraftSeatController::class, 'update']);
    Route::get('aircraft/{aircraft}/load-zones', [Admin\AircraftLoadZoneController::class, 'index']);
    Route::put('aircraft/{aircraft}/load-zones', [Admin\AircraftLoadZoneController::class, 'replace']);
    Route::apiResource('aircraft.seat-assignment-profiles', Admin\SeatAssignmentProfileController::class)
        ->except(['show'])->parameters(['seat-assignment-profiles' => 'profile']);
});

// Products & prices
Route::middleware('can:fares.manage')->group(function () {
    Route::apiResource('fares', Admin\FareController::class)->parameters(['fares' => 'id']);
});
Route::middleware('can:products.manage')->group(function () {
    Route::apiResource('baggage-rules', Admin\BaggageRuleController::class)->parameters(['baggage-rules' => 'id']);
    Route::apiResource('baggage-policies', Admin\BaggagePolicyController::class)->parameters(['baggage-policies' => 'id']);
    Route::apiResource('seat-prices', Admin\SeatPriceController::class)->parameters(['seat-prices' => 'id']);
    Route::apiResource('service-templates', Admin\ServiceTemplateController::class)->parameters(['service-templates' => 'id']);
    Route::apiResource('airline-ssrs', Admin\AirlineSsrController::class)->except(['show']);
});

// Flights
Route::middleware('can:flights.view')->group(function () {
    Route::get('flights', [Admin\FlightController::class, 'index']);
    Route::get('flights/{flight}', [Admin\FlightController::class, 'show']);
    Route::get('flights/{flight}/statistics', [Admin\FlightController::class, 'statistics']);
    Route::get('flights/{flight}/fares', [Admin\FareController::class, 'forFlight']);
    Route::get('flights/{flight}/allocation-capacity', [Admin\FlightController::class, 'allocationCapacity']);
});
Route::middleware('can:flights.manage')->group(function () {
    Route::post('flights', [Admin\FlightController::class, 'store']);
    Route::put('flights/{flight}', [Admin\FlightController::class, 'update']);
    Route::delete('flights/{flight}', [Admin\FlightController::class, 'destroy']);
    Route::apiResource('flight-series', Admin\FlightSeriesController::class)->parameters(['flight-series' => 'flightSeries']);
});
Route::middleware('can:flights.operate')->group(function () {
    Route::get('flight-board', [Admin\FlightOperationController::class, 'board']);
    Route::post('flights/{flight}/status', [Admin\FlightOperationController::class, 'changeStatus']);
    Route::put('flights/{flight}/gate', [Admin\FlightOperationController::class, 'setGate']);
    Route::put('flights/{flight}/estimated-departure', [Admin\FlightOperationController::class, 'setEstimatedDeparture']);
    Route::get('flights/{flight}/gate-agents/candidates', [Admin\FlightOperationController::class, 'gateAgentCandidates']);
    Route::put('flights/{flight}/gate-agents', [Admin\FlightOperationController::class, 'setGateAgents']);
    Route::put('flights/{flight}/online-checkin', [Admin\FlightOperationController::class, 'setOnlineCheckin']);
    Route::post('service-templates/{id}/apply', [Admin\ServiceTemplateController::class, 'apply']);
});
Route::middleware('can:seat_blocks.manage')->group(function () {
    Route::get('flights/{flight}/seat-map', [Admin\SeatBlockController::class, 'seatMap']);
    Route::post('flights/{flight}/seat-blocks', [Admin\SeatBlockController::class, 'block']);
    Route::delete('flights/{flight}/seat-blocks', [Admin\SeatBlockController::class, 'unblock']);
});

// Agency allocations
Route::middleware('can:allocations.manage')->group(function () {
    Route::apiResource('allocations', Admin\AllocationController::class);
    Route::get('allocations/{allocation}/passengers', [Admin\AllocationController::class, 'passengers']);
});

// Bookings & passengers
Route::middleware('can:bookings.view')->group(function () {
    Route::get('bookings', [Admin\BookingController::class, 'index']);
    Route::get('bookings/{booking}', [Admin\BookingController::class, 'show']);
    Route::get('flights/{flight}/passengers', [Admin\FlightController::class, 'passengers']);
    Route::get('passengers/{passenger}', [Admin\PassengerController::class, 'show']);
    Route::get('passengers/{passenger}/activity', [Admin\PassengerController::class, 'activity']);
    Route::get('passengers/{passenger}/eticket', [Admin\PassengerController::class, 'eticket']);
});
Route::middleware('can:bookings.manage')->group(function () {
    Route::apiResource('passenger-bans', Admin\PassengerBanController::class);
    Route::post('bookings', [Admin\BookingController::class, 'store']);
    Route::post('bookings/merge', [Admin\BookingController::class, 'merge']);
    Route::post('passengers/{passenger}/split', [Admin\BookingController::class, 'split']);
    Route::put('passengers/{passenger}', [Admin\PassengerController::class, 'update']);
    Route::delete('passengers/{passenger}', [Admin\PassengerController::class, 'destroy']);
    Route::post('passengers/{passenger}/eticket/email', [Admin\PassengerController::class, 'emailEticket']);
    Route::post('segments/waitlist', [Admin\BookingController::class, 'waitlist']);
    Route::post('segments/{segment}/rebook', [Admin\BookingController::class, 'rebook']);
});

// Documents & reports
Route::middleware('can:reports.view')->group(function () {
    Route::get('flights/{flight}/documents/pnl', [Admin\FlightDocumentController::class, 'pnl']);
    Route::get('flights/{flight}/documents/passengers', [Admin\FlightDocumentController::class, 'passengers']);
    Route::get('flights/{flight}/documents/apis', [Admin\FlightDocumentController::class, 'apis']);
    Route::get('flights/{flight}/documents/manifest', [Admin\FlightDocumentController::class, 'manifest']);
    Route::get('flights/{flight}/documents/no-show', [Admin\FlightDocumentController::class, 'noShow']);
    Route::post('flights/{flight}/documents/email', [Admin\FlightDocumentController::class, 'email']);

    Route::get('reports/routes', [Admin\ReportController::class, 'routes']);
    Route::get('reports/traffic', [Admin\ReportController::class, 'traffic']);
    Route::get('reports/fare-sales', [Admin\ReportController::class, 'fareSales']);
    Route::get('reports/online-checkin', [Admin\ReportController::class, 'onlineCheckin']);
    Route::get('reports/passenger-search', [Admin\ReportController::class, 'passengerSearch']);
});

Route::get('activity-logs', [Admin\ActivityLogController::class, 'index'])->middleware('can:activity_logs.view');
Route::apiResource('announcements', Admin\AnnouncementController::class)->except(['show'])->middleware('can:announcements.manage');

// Charter requests
Route::middleware('can:charters.view')->group(function () {
    Route::get('charter-requests', [Admin\CharterRequestController::class, 'index']);
    Route::get('charter-requests/{charterRequest}', [Admin\CharterRequestController::class, 'show']);
});
Route::middleware('can:charters.manage')->group(function () {
    Route::post('charter-requests', [Admin\CharterRequestController::class, 'store']);
    Route::patch('charter-requests/{charterRequest}/status', [Admin\CharterRequestController::class, 'updateStatus']);
});
