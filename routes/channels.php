<?php

use App\Enums\PermissionKey;
use App\Models\Flight;
use App\Models\User;
use App\Services\Access\AccessScope;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/*
| DCS counters of one flight: check-in, seats, baggage, groups, flight status (App\Events\Dcs\FlightUpdated).
*/
Broadcast::channel('dcs.flight.{flight}', function (User $user, Flight $flight) {
    return $user->can(PermissionKey::DcsAccess->value) && app(AccessScope::class)->canSeeFlight($user, $flight);
});
