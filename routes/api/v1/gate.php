<?php

use App\Http\Controllers\Api\V1\Gate\GateController;
use Illuminate\Support\Facades\Route;

/*
| Boarding app. A gate agent sees only the flights they are assigned to (flight manager).
*/

Route::middleware('can:gate.access')->group(function () {
    Route::get('flights', [GateController::class, 'flights']);
    Route::get('flights/{flight}', [GateController::class, 'show']);
    Route::get('flights/{flight}/passengers', [GateController::class, 'passengers']);
    Route::post('flights/{flight}/board', [GateController::class, 'board']);
    Route::post('segments/{segment}/offload', [GateController::class, 'offload']);
});
