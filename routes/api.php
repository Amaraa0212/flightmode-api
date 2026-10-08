<?php

use Illuminate\Support\Facades\Route;

/*
| All endpoints live under /api/v1 and are split by area.
| See docs/architecture.md for the conventions.
*/

Route::prefix('v1')->group(function () {
    Route::get('ping', fn () => response()->json(['status' => 'ok']));

    Route::prefix('auth')->group(base_path('routes/api/v1/auth.php'));
    Route::prefix('checkin')->group(base_path('routes/api/v1/checkin.php'));
    Route::prefix('partner')->group(base_path('routes/api/v1/partner.php'));

    Route::middleware(['auth:sanctum', 'active', 'no-partner-token'])->group(function () {
        Route::group([], base_path('routes/api/v1/shared.php'));
        Route::prefix('admin')->group(base_path('routes/api/v1/admin.php'));
        Route::prefix('agency')->group(base_path('routes/api/v1/agency.php'));
        Route::prefix('dcs')->group(base_path('routes/api/v1/dcs.php'));
        Route::prefix('gate')->group(base_path('routes/api/v1/gate.php'));
    });
});
