<?php

use App\Services\Allocation\AllocationService;
use App\Services\Booking\BookingService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('bookings:cancel-expired-holds', function (BookingService $bookings) {
    $this->info($bookings->cancelExpiredHolds().' booking(s) on hold cancelled.');
})->purpose('Cancel bookings on hold whose ticket time limit has passed');

Artisan::command('allocations:release-unsold', function (AllocationService $allocations) {
    $this->info($allocations->releaseUnsold().' fixed allocation(s) released.');
})->purpose('Give the unsold seats of fixed allocations back to the flight when their names deadline passes');

// needs the scheduler on the server: * * * * * php artisan schedule:run
Schedule::command('bookings:cancel-expired-holds')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('allocations:release-unsold')->everyFiveMinutes()->withoutOverlapping();
