<?php

use App\Http\Controllers\Api\V1\Shared\AnnouncementFeedController;
use App\Http\Controllers\Api\V1\Shared\IssueReportController;
use App\Http\Controllers\Api\V1\Shared\ReferenceController;
use Illuminate\Support\Facades\Route;

// Lookup lists for every signed-in user
Route::prefix('reference')->group(function () {
    Route::get('countries', [ReferenceController::class, 'countries']);
    Route::get('airports', [ReferenceController::class, 'airports']);
    Route::get('ssr-codes', [ReferenceController::class, 'ssrCodes']);
    Route::get('enums', [ReferenceController::class, 'enums']);
});

// Announcements addressed to me
Route::get('announcements', [AnnouncementFeedController::class, 'index']);
Route::post('announcements/{announcement}/dismiss', [AnnouncementFeedController::class, 'dismiss']);

// Problems reported from the page help panel
Route::post('issue-reports', [IssueReportController::class, 'store'])->middleware('throttle:10,1');
