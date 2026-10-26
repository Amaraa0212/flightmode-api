<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('two-factor-challenge', [AuthController::class, 'twoFactorChallenge'])->middleware('throttle:10,1');

Route::middleware(['auth:sanctum', 'active', 'no-partner-token'])->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
    Route::put('password', [AuthController::class, 'changePassword']);
    Route::post('two-factor/setup', [AuthController::class, 'setupTwoFactor'])->middleware('throttle:10,1');
    Route::post('two-factor/confirm', [AuthController::class, 'confirmTwoFactor'])->middleware('throttle:10,1');
    Route::delete('two-factor', [AuthController::class, 'disableTwoFactor'])->middleware('throttle:10,1');
});
