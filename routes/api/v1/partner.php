<?php

use App\Http\Controllers\Api\V1\Agency\AllocationController;
use App\Http\Controllers\Api\V1\Agency\BookingController;
use Illuminate\Support\Facades\Route;

/*
| Agency machine-to-machine API (legacy /api/agent/*).
| Authorization: Bearer <token issued by POST /admin/users/{user}/api-token>, ability "partner".
| Same controllers and rules as the agency portal.
*/

Route::middleware(['auth:sanctum', 'active', 'partner', 'throttle:120,1'])->group(function () {
    Route::get('allocations', [AllocationController::class, 'index']);
    Route::get('allocations/{allocation}', [AllocationController::class, 'show']);
    Route::get('allocations/{allocation}/passengers', [AllocationController::class, 'passengers']);
    Route::get('allocations/{allocation}/ssr-codes', [AllocationController::class, 'ssrCodes']);

    Route::post('bookings', [BookingController::class, 'store']);
    Route::get('passengers/{passenger}', [BookingController::class, 'showPassenger']);
    Route::patch('passengers/{passenger}', [BookingController::class, 'updatePassenger']);
    Route::delete('passengers/{passenger}', [BookingController::class, 'cancelPassenger']);
    Route::get('passengers/{passenger}/eticket', [BookingController::class, 'eticket']);
});
