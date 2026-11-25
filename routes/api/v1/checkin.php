<?php

use App\Http\Controllers\Api\V1\Checkin\OnlineCheckinController;
use Illuminate\Support\Facades\Route;

/*
| Public online check-in (no login). The session token goes in the X-Checkin-Token header.
|   Oyu Tolgoi:  lookup/sap (person code)            → session token
|   others:      lookup/pnr (PNR + last name) → code → verify → session token
*/

// Rate limiters: AppServiceProvider::onlineCheckinLimits()
Route::post('lookup/sap', [OnlineCheckinController::class, 'lookupBySap'])->middleware('throttle:oci-sap');
Route::post('lookup/pnr', [OnlineCheckinController::class, 'lookupByPnr'])->middleware('throttle:oci-pnr');
Route::post('verification-code', [OnlineCheckinController::class, 'sendCode'])->middleware('throttle:oci-code');
Route::post('verify', [OnlineCheckinController::class, 'verify'])->middleware('throttle:oci-code');

Route::middleware('throttle:oci')->group(function () {
    Route::get('flights', [OnlineCheckinController::class, 'flights']);
    Route::get('flights/{flight}/policies', [OnlineCheckinController::class, 'policies']);
    Route::get('flights/{flight}/passengers', [OnlineCheckinController::class, 'passengers']);
    Route::get('flights/{flight}/seat-map', [OnlineCheckinController::class, 'seatMap']);
    Route::post('flights/{flight}/seat-hold', [OnlineCheckinController::class, 'holdSeat']);
    Route::delete('flights/{flight}/seat-hold/{seat}', [OnlineCheckinController::class, 'releaseSeat'])->where('seat', '[0-9]{1,3}[A-Za-z]');
    Route::post('flights/{flight}/check-in', [OnlineCheckinController::class, 'checkIn']);
    Route::get('flights/{flight}/boarding-passes', [OnlineCheckinController::class, 'boardingPasses']);
});
