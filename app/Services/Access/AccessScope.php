<?php

namespace App\Services\Access;

use App\Models\AgencyAllocation;
use App\Models\Booking;
use App\Models\Flight;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Decides which rows a user may see or touch.
 *   system admin  → everything
 *   airline user  → rows of their airline
 *   airport user  → flights departing their station (any airline)
 *   agency user   → their agency's allocations / bookings and the flights behind them
 */
class AccessScope
{
    /** Restrict any table that has an airline_id column. */
    public function airlineOwned(Builder $query, User $user, string $column = 'airline_id'): Builder
    {
        if ($user->isSystemAdmin()) {
            return $query;
        }

        if ($user->agency_id && ! $user->airline_id) {
            $airlineIds = $user->agency?->airlines()->pluck('airlines.id')->all() ?? [];

            return $query->whereIn($query->qualifyColumn($column), $airlineIds);
        }

        return $query->where($query->qualifyColumn($column), $user->airline_id ?? 0);
    }

    public function flights(Builder $query, User $user): Builder
    {
        if ($user->isSystemAdmin()) {
            return $query;
        }

        if ($user->isAirportUser()) {
            return $query->where('origin_airport_id', $user->airport_id ?? 0);
        }

        if ($user->agency_id && ! $user->airline_id) {
            $flightIds = AgencyAllocation::query()->where('agency_id', $user->agency_id)
                ->get(['outbound_flight_id', 'return_flight_id'])
                ->flatMap(fn ($a) => [$a->outbound_flight_id, $a->return_flight_id])
                ->filter()->unique()->all();

            return $query->whereIn('flights.id', $flightIds);
        }

        return $query->where('flights.airline_id', $user->airline_id ?? 0);
    }

    public function allocations(Builder $query, User $user): Builder
    {
        if ($user->isSystemAdmin()) {
            return $query;
        }

        if ($user->agency_id && ! $user->airline_id) {
            return $query->where('agency_allocations.agency_id', $user->agency_id);
        }

        return $query->where('agency_allocations.airline_id', $user->airline_id ?? 0);
    }

    public function bookings(Builder $query, User $user): Builder
    {
        if ($user->isSystemAdmin()) {
            return $query;
        }

        if ($user->agency_id && ! $user->airline_id) {
            return $query->where('bookings.agency_id', $user->agency_id);
        }

        if ($user->isAirportUser()) {
            return $query->whereHas('passengers.segments.flight', fn (Builder $q) => $q->where('origin_airport_id', $user->airport_id ?? 0));
        }

        return $query->where('bookings.airline_id', $user->airline_id ?? 0);
    }

    public function canSeeFlight(User $user, Flight $flight): bool
    {
        return $this->flights(Flight::query()->whereKey($flight->getKey()), $user)->exists();
    }

    public function ensureFlight(User $user, Flight $flight): void
    {
        if (! $this->canSeeFlight($user, $flight)) {
            throw new AuthorizationException('You do not have access to this flight.');
        }
    }

    public function ensureAllocation(User $user, AgencyAllocation $allocation): void
    {
        if (! $this->allocations(AgencyAllocation::query()->whereKey($allocation->getKey()), $user)->exists()) {
            throw new AuthorizationException('You do not have access to this allocation.');
        }
    }

    public function ensureBooking(User $user, Booking $booking): void
    {
        if (! $this->bookings(Booking::query()->whereKey($booking->getKey()), $user)->exists()) {
            throw new AuthorizationException('You do not have access to this booking.');
        }
    }

    /** For a model with airline_id: 403 unless it belongs to the user's airline (admins pass). */
    public function ensureAirlineOwned(User $user, Model $model, string $column = 'airline_id'): void
    {
        if (! $user->isSystemAdmin() && (int) $model->getAttribute($column) !== (int) $user->airline_id) {
            throw new AuthorizationException('This record belongs to another airline.');
        }
    }

    /**
     * Airline to write a new record for: airline users always write to their own airline,
     * system admins must say which airline.
     */
    public function airlineIdForWrite(User $user, ?int $requested): int
    {
        if (! $user->isSystemAdmin()) {
            if (! $user->airline_id) {
                throw new AuthorizationException('Your account is not linked to an airline.');
            }

            return (int) $user->airline_id;
        }

        if (! $requested) {
            throw ValidationException::withMessages(['airline_id' => 'The airline id field is required.']);
        }

        return $requested;
    }
}
