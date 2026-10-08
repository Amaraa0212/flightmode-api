<?php

use App\Http\Controllers\Api\V1\Checkin\OnlineCheckinController;
use Illuminate\Support\Facades\Route;

/*
| Public online check-in (no login). Session token from /verify goes in the X-Checkin-Token header.
*/

Route::middleware('throttle:30,1')->group(function () {
    Route::post('lookup', [OnlineCheckinController::class, 'lookup']);
    Route::post('verification-code', [OnlineCheckinController::class, 'sendCode'])->middleware('throttle:5,1');
    Route::post('verify', [OnlineCheckinController::class, 'verify'])->middleware('throttle:10,1');

    Route::get('flights/{flight}/policies', [OnlineCheckinController::class, 'policies']);
    Route::get('flights/{flight}/passengers', [OnlineCheckinController::class, 'passengers']);
    Route::get('flights/{flight}/seat-map', [OnlineCheckinController::class, 'seatMap']);
    Route::post('flights/{flight}/seat-hold', [OnlineCheckinController::class, 'holdSeat']);
    Route::post('flights/{flight}/check-in', [OnlineCheckinController::class, 'checkIn']);
    Route::get('flights/{flight}/boarding-passes', [OnlineCheckinController::class, 'boardingPasses']);
});
