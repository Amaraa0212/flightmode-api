<?php

use App\Http\Controllers\Api\V1\Admin\CharterRequestController;
use App\Http\Controllers\Api\V1\Agency\AccountController;
use App\Http\Controllers\Api\V1\Agency\AllocationController;
use App\Http\Controllers\Api\V1\Agency\BookingController;
use App\Http\Controllers\Api\V1\Agency\HoldController;
use Illuminate\Support\Facades\Route;

/*
| Travel agency portal. Users only see their own agency's allocations and bookings.
*/

Route::middleware('can:agency.portal')->group(function () {
    Route::get('allocations', [AllocationController::class, 'index']);
    Route::get('allocations/{allocation}', [AllocationController::class, 'show']);
    Route::get('allocations/{allocation}/passengers', [AllocationController::class, 'passengers']);
    Route::get('allocations/{allocation}/ssr-codes', [AllocationController::class, 'ssrCodes']);
    Route::get('allocations/{allocation}/return-options', [AllocationController::class, 'returnOptions']);
    Route::post('allocations/{allocation}/import', [BookingController::class, 'import']);

    // find flights of a day, hold seats, enter names later
    Route::get('flights', [HoldController::class, 'flights']);
    Route::get('holds', [HoldController::class, 'index']);
    Route::post('holds', [HoldController::class, 'store']);
    Route::delete('holds/{hold}', [HoldController::class, 'destroy']);

    // prepaid balance with each airline
    Route::get('accounts', [AccountController::class, 'index']);
    Route::get('accounts/{account}/transactions', [AccountController::class, 'transactions']);

    Route::post('bookings', [BookingController::class, 'store']);
    Route::post('bookings/{booking}/issue', [BookingController::class, 'issue']);
    Route::get('passenger-lookup', [BookingController::class, 'lookup']);
    Route::get('passengers/{passenger}', [BookingController::class, 'showPassenger']);
    Route::put('passengers/{passenger}', [BookingController::class, 'updatePassenger']);
    Route::delete('passengers/{passenger}', [BookingController::class, 'cancelPassenger']);
    Route::get('passengers/{passenger}/eticket', [BookingController::class, 'eticket']);
    Route::post('passengers/{passenger}/eticket/email', [BookingController::class, 'emailEticket']);
});

Route::middleware('can:charters.manage')->group(function () {
    Route::get('charter-requests', [CharterRequestController::class, 'index']);
    Route::post('charter-requests', [CharterRequestController::class, 'store']);
});
