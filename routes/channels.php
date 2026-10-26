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

/*
| Gate app: the same FlightUpdated events for a flight the agent is assigned to (manifest, boarding, statistics) …
*/
Broadcast::channel('gate.flight.{flight}', function (User $user, Flight $flight) {
    return $user->can(PermissionKey::GateAccess->value)
        && ($user->isSystemAdmin() || $flight->gateAgents()->whereKey($user->id)->exists());
});

/*
| … and flight-level changes (status, gate, assignment) for the agent's flight list.
*/
Broadcast::channel('gate.agent.{id}', function (User $user, $id) {
    return (int) $user->id === (int) $id && $user->can(PermissionKey::GateAccess->value);
});
