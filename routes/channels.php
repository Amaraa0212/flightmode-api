<?php

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
    return $user->can('dcs.access') && app(AccessScope::class)->canSeeFlight($user, $flight);
});

/*
| Gate app: the same FlightUpdated events for a flight the agent is assigned to (manifest, boarding, statistics) …
*/
Broadcast::channel('gate.flight.{flight}', function (User $user, Flight $flight) {
    return $user->can('gate.access') && app(AccessScope::class)->canUseGateOn($user, $flight);
});

/*
| … and flight-level changes (status, gate, assignment) for the agent's flight list.
*/
Broadcast::channel('gate.agent.{id}', function (User $user, $id) {
    return (int) $user->id === (int) $id && $user->can('gate.access');
});
