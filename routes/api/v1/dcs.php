<?php

use App\Http\Controllers\Api\V1\Admin\FlightDocumentController;
use App\Http\Controllers\Api\V1\Dcs;
use Illuminate\Support\Facades\Route;

/*
| Check-in counter (DCS). dcs.access = read, dcs.checkin = everything that changes data.
*/

Route::middleware('can:dcs.access')->group(function () {
    Route::get('flights', [Dcs\FlightController::class, 'index']);
    Route::get('flights/{flight}', [Dcs\FlightController::class, 'show']);
    Route::get('flights/{flight}/statistics', [Dcs\FlightController::class, 'statistics']);
    Route::get('flights/{flight}/seat-map', [Dcs\FlightController::class, 'seatMap']);
    Route::get('flights/{flight}/ssr-codes', [Dcs\FlightController::class, 'ssrCodes']);
    Route::get('flights/{flight}/passengers', [Dcs\PassengerController::class, 'index']);
    Route::get('flights/{flight}/baggage', [Dcs\BaggageController::class, 'index']);
    Route::get('flights/{flight}/documents/manifest', [FlightDocumentController::class, 'manifest']);
    Route::get('passengers/{passenger}/activity', [Dcs\PassengerController::class, 'activity']);
    Route::get('segments/{segment}', [Dcs\PassengerController::class, 'show']);
    Route::get('segments/{segment}/baggage-rules', [Dcs\BaggageController::class, 'rules']);
    Route::post('segments/{segment}/baggage/quote', [Dcs\BaggageController::class, 'quote']);
    Route::get('segments/{segment}/boarding-pass', [Dcs\CheckinController::class, 'boardingPass']);
    Route::get('baggage-tags/{tag}', [Dcs\BaggageController::class, 'tag']);
    // QZ Tray printing: certificate + request signing
    Route::get('qz/certificate', [Dcs\QzController::class, 'certificate']);
    Route::post('qz/sign', [Dcs\QzController::class, 'sign'])->middleware('throttle:120,1');
});

Route::middleware('can:dcs.checkin')->group(function () {
    Route::post('flights/{flight}/open', [Dcs\FlightController::class, 'open']);
    Route::post('flights/{flight}/close', [Dcs\FlightController::class, 'close']);
    Route::post('flights/{flight}/passengers', [Dcs\PassengerController::class, 'store']);
    Route::put('passengers/{passenger}', [Dcs\PassengerController::class, 'update']);

    Route::post('segments/{segment}/check-in', [Dcs\CheckinController::class, 'store']);
    Route::delete('segments/{segment}/check-in', [Dcs\CheckinController::class, 'destroy']);

    Route::post('segments/{segment}/baggage', [Dcs\BaggageController::class, 'store']);
    Route::post('flights/{flight}/crew-baggage', [Dcs\BaggageController::class, 'storeCrew']);
    Route::delete('baggage/{checkedBaggage}', [Dcs\BaggageController::class, 'destroy']);

    Route::post('flights/{flight}/groups', [Dcs\GroupController::class, 'store']);
    Route::delete('segments/{segment}/group', [Dcs\GroupController::class, 'destroy']);
});
