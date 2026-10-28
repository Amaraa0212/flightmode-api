<?php

namespace App\Services\Allocation;

use App\Enums\CabinClass;
use App\Enums\PassengerType;
use App\Enums\SegmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\AgencyAllocation;
use App\Models\AgencyAllocationCabin;
use App\Models\AgencyAllocationFare;
use App\Models\AgencyHold;
use App\Models\Flight;
use App\Models\PassengerSegment;
use App\Services\Integrations\Chinggis\ChinggisSync;
use Illuminate\Support\Facades\DB;

/**
 * Agency seat allocations: creation with quota checks, usage and availability.
 * "Used" always means: non-infant passengers of this allocation that hold a place on the outbound flight.
 *
 * A fixed allocation takes its seats out of the flight. A pool allocation takes nothing out: the pool agencies sell
 * the flight's free seats together (what they sell counts like a direct sale), each up to its optional limit, and
 * the unnamed seats of a pool agency's hold are gone for everyone else.
 */
class AllocationService
{
    public function __construct(private readonly ChinggisSync $chinggis) {}

    /**
     * @param array{airline_id: int, agency_id: int, outbound_flight_id: int, return_flight_id?: ?int, mode?: string, seats: int, deadline: string,
     *              is_waitlist?: bool, cabins: array<string, int>, fares?: array<int, array<string, mixed>>} $data
     */
    public function save(array $data, ?AgencyAllocation $allocation = null): AgencyAllocation
    {
        return DB::transaction(function () use ($data, $allocation) {
            $outbound = Flight::query()->lockForUpdate()->findOrFail($data['outbound_flight_id']);
            $return = isset($data['return_flight_id']) ? Flight::query()->lockForUpdate()->findOrFail($data['return_flight_id']) : null;

            foreach (array_filter([$outbound, $return]) as $flight) {
                if ((int) $flight->airline_id !== (int) $data['airline_id']) {
                    throw BusinessRuleException::make('flight_other_airline', "Flight {$flight->flight_number} belongs to another airline.");
                }
            }

            if ($return && $return->scheduled_departure_at->lt($outbound->scheduled_departure_at)) {
                throw BusinessRuleException::make('return_before_outbound', 'The return flight departs before the outbound flight.');
            }

            $this->assertAgencyContracted($data['agency_id'], $data['airline_id']);
            $this->assertNotDuplicate($data, $allocation);

            $mode = $data['mode'] ?? $allocation?->mode ?? AgencyAllocation::FIXED;
            $pool = $mode === AgencyAllocation::POOL;

            if ($pool) {
                // nothing is taken out of the flight: the cabins are what the agency may sell, their seats a limit (0 = none)
                $data['cabins'] = ($data['cabins'] ?? []) ?: $outbound->aircraft?->cabins()->where('seat_count', '>', 0)
                    ->pluck('cabin_class')->mapWithKeys(fn ($c) => [$c->value => 0])->all() ?? [];
                if ($data['cabins'] === []) {
                    throw BusinessRuleException::make('cabin_required', 'Choose at least one cabin the agency may sell.');
                }
                if ($data['seats'] > 0 && max($data['cabins']) > $data['seats']) {
                    throw BusinessRuleException::make('cabins_exceed_seats', 'A cabin limit is higher than the allocation limit.');
                }
            } else {
                if ($data['seats'] < 1) {
                    throw BusinessRuleException::make('seats_required', 'A fixed allocation needs at least one seat.');
                }

                foreach (array_filter([$outbound, $return]) as $flight) {
                    $free = $this->seatsLeftForAllocations($flight, $allocation);
                    if ($data['seats'] > $free) {
                        throw BusinessRuleException::make('not_enough_seats',
                            "Flight {$flight->flight_number} has only {$free} seat(s) left for allocations.");
                    }
                }

                foreach (array_filter([$outbound, $return]) as $flight) {
                    foreach ($data['cabins'] as $cabin => $seats) {
                        if ($seats <= 0) {
                            continue;
                        }
                        $cabinClass = CabinClass::from($cabin);
                        $free = $this->cabinSeatsLeftForAllocations($flight, $cabinClass, $allocation);
                        if ($seats > $free) {
                            throw BusinessRuleException::make('not_enough_cabin_seats',
                                "Flight {$flight->flight_number}: cabin {$cabinClass->value} has only {$free} seat(s) left for allocations.");
                        }
                    }
                }

                if (array_sum($data['cabins']) > $data['seats']) {
                    throw BusinessRuleException::make('cabins_exceed_seats', 'Cabin seats add up to more than the allocation.');
                }
            }

            if ($allocation && ($data['seats'] > 0 || ! $pool) && $data['seats'] < $this->usedSeats($allocation)) {
                throw BusinessRuleException::make('below_used', 'Seats cannot be lower than the passengers already booked.');
            }

            // an update without "fares" keeps the fare buckets it has
            $syncFares = array_key_exists('fares', $data) || ! $allocation?->exists;

            $allocation ??= new AgencyAllocation(['created_by' => auth()->id()]);
            $allocation->fill([
                'airline_id' => $data['airline_id'],
                'agency_id' => $data['agency_id'],
                'outbound_flight_id' => $outbound->id,
                'return_flight_id' => $return?->id,
                'mode' => $mode,
                'seats' => $data['seats'],
                'deadline' => $data['deadline'],
                'is_waitlist' => $data['is_waitlist'] ?? false,
            ])->fill($syncFares ? ['uses_fare_quotas' => ! empty($data['fares'])] : [])->save();

            $allocation->cabins()->delete();
            foreach ($data['cabins'] as $cabin => $seats) {
                // a pool cabin row with 0 seats = may sell this cabin without a limit
                if ($seats > 0 || $pool) {
                    $allocation->cabins()->create(['cabin_class' => CabinClass::from($cabin), 'seats' => $seats]);
                }
            }

            if ($syncFares) {
                $this->syncFares($allocation, $data['fares'] ?? []);
            }

            $allocation->load(['cabins', 'fares.fare', 'outboundFlight', 'returnFlight', 'agency']);
            $this->chinggis->pushAllocation($allocation);

            return $allocation;
        });
    }

    public function delete(AgencyAllocation $allocation): void
    {
        if ($allocation->bookings()->whereHas('passengers')->exists()) {
            throw BusinessRuleException::make('allocation_has_bookings', 'Cannot delete an allocation that has passengers.');
        }

        $this->chinggis->cancelAllocation($allocation);
        $allocation->delete();
    }

    /** Seats of a flight not yet given to fixed allocations, sold outside them (direct / pool) or held by pool agencies. */
    public function seatsLeftForAllocations(Flight $flight, ?AgencyAllocation $except = null): int
    {
        $total = (int) $flight->aircraft?->total_seats;

        $allocated = AgencyAllocation::query()
            ->where('mode', AgencyAllocation::FIXED)
            ->where(fn ($q) => $q->where('outbound_flight_id', $flight->id)->orWhere('return_flight_id', $flight->id))
            ->when($except?->exists, fn ($q) => $q->whereKeyNot($except->id))
            ->sum('seats');

        $sold = $this->soldOutsideFixed($flight)->count();

        return max(0, $total - (int) $allocated - $sold - $this->poolHeldSeats($flight));
    }

    /**
     * Seats of one cabin not yet given to allocations or sold directly by the airline. The cabin size is the
     * flight's seat inventory (seats + oversell - curtail) when it has one, otherwise the aircraft cabin.
     */
    public function cabinSeatsLeftForAllocations(Flight $flight, CabinClass $cabin, ?AgencyAllocation $except = null): int
    {
        return max(0, $this->cabinAllocationRoom($flight, $cabin, $except));
    }

    /**
     * Same as cabinSeatsLeftForAllocations, but negative when the cabin is already over-allocated. This is also what
     * pool agencies may still sell: `$exceptPassengerId` / `$exceptHoldId` leave out the passenger being changed and
     * the hold being named.
     */
    public function cabinAllocationRoom(Flight $flight, CabinClass $cabin, ?AgencyAllocation $except = null, ?int $exceptPassengerId = null, ?int $exceptHoldId = null): int
    {
        $inventory = $flight->cabinInventory()->get();
        $size = $inventory->isNotEmpty()
            ? (int) $inventory->first(fn ($c) => $c->cabin_class === $cabin)?->capacity()
            : (int) $flight->aircraft?->cabins()->where('cabin_class', $cabin->value)->value('seat_count');

        $allocated = AgencyAllocationCabin::query()
            ->where('cabin_class', $cabin->value)
            ->whereHas('allocation', fn ($q) => $q
                ->where('mode', AgencyAllocation::FIXED)
                ->where(fn ($x) => $x->where('outbound_flight_id', $flight->id)->orWhere('return_flight_id', $flight->id))
                ->when($except?->exists, fn ($x) => $x->whereKeyNot($except->id)))
            ->sum('seats');

        $sold = $this->soldOutsideFixed($flight)
            ->where('cabin_class', $cabin->value)
            ->when($exceptPassengerId, fn ($q) => $q->where('passenger_id', '!=', $exceptPassengerId))
            ->count();

        return $size - (int) $allocated - $sold - $this->poolHeldSeats($flight, $cabin, $exceptHoldId);
    }

    /** Seats a pool allocation may still sell in a cabin: the shared room on each of its flights. */
    public function poolRoom(AgencyAllocation $allocation, CabinClass $cabin, ?int $exceptPassengerId = null, ?int $exceptHoldId = null): int
    {
        $allocation->loadMissing(['outboundFlight', 'returnFlight']);

        return max(0, min(array_map(
            fn (Flight $flight) => $this->cabinAllocationRoom($flight, $cabin, null, $exceptPassengerId, $exceptHoldId),
            array_filter([$allocation->outboundFlight, $allocation->returnFlight]),
        )));
    }

    /**
     * Seats the allocation can still take in a cabin (fare bucket, when given): its own limits and, for a pool,
     * what is left on the flight. Holds and names use this.
     */
    public function freeToTake(AgencyAllocation $allocation, CabinClass $cabin, ?AgencyAllocationFare $bucket = null, ?int $exceptPassengerId = null, ?AgencyHold $hold = null): int
    {
        $allocation->loadMissing('cabins');
        $row = $allocation->cabins->firstWhere('cabin_class', $cabin);
        if (! $row) {
            return 0;
        }

        $taken = fn (?CabinClass $c = null) => $this->usedSeats($allocation, $c, $exceptPassengerId) + $this->heldSeats($allocation, $c, null, $hold?->id);
        $limits = [];
        // a pool cabin / allocation with 0 seats has no limit of its own
        if (! $allocation->isPool() || $row->seats > 0) {
            $limits[] = $row->seats - $taken($cabin);
        }
        if (! $allocation->isPool() || $allocation->seats > 0) {
            $limits[] = $allocation->seats - $taken();
        }
        if ($bucket && $bucket->seats > 0) {
            $limits[] = $bucket->seats - $this->usedFareSeats($bucket, $exceptPassengerId) - $this->heldSeats($allocation, null, $bucket, $hold?->id);
        }
        if ($allocation->isPool()) {
            $limits[] = $this->poolRoom($allocation, $cabin, $exceptPassengerId, $hold?->id);
        }

        return max(0, min($limits));
    }

    public function usedSeats(AgencyAllocation $allocation, ?CabinClass $cabin = null, ?int $exceptPassengerId = null): int
    {
        return $this->activeAdultSegments()
            ->where('flight_id', $allocation->outbound_flight_id)
            ->whereHas('passenger.booking', fn ($q) => $q->where('agency_allocation_id', $allocation->id))
            ->when($cabin, fn ($q) => $q->where('cabin_class', $cabin->value))
            ->when($exceptPassengerId, fn ($q) => $q->where('passenger_id', '!=', $exceptPassengerId))
            ->count();
    }

    public function usedFareSeats(AgencyAllocationFare $bucket, ?int $exceptPassengerId = null): int
    {
        $bucket->loadMissing('allocation');

        // a round trip gives the passenger one coupon per flight with the same bucket — count the outbound one only
        return PassengerSegment::query()
            ->where('agency_allocation_fare_id', $bucket->id)
            ->where('flight_id', $bucket->allocation->outbound_flight_id)
            ->whereNotIn('status', [SegmentStatus::Cancelled])
            ->when($exceptPassengerId, fn ($q) => $q->where('passenger_id', '!=', $exceptPassengerId))
            ->whereHas('passenger', fn ($q) => $q->whereNull('infant_of_passenger_id'))
            ->count();
    }

    /**
     * @return array{seats: int, used: int, remaining: int, is_open_for_names: bool, names_deadline_at: string,
     *               cabins: array<int, array<string, mixed>>, fares: array<int, array<string, mixed>>}
     */
    public function usage(AgencyAllocation $allocation): array
    {
        $allocation->loadMissing(['cabins', 'fares.fare', 'outboundFlight']);
        $used = $this->usedSeats($allocation);
        $held = $this->heldSeats($allocation);
        $remaining = max(0, $allocation->seats - $used - $held);
        if ($allocation->isPool()) {
            // what the agency can still sell: the shared room, within its own limit
            $room = $allocation->cabins->sum(fn ($cabin) => $this->freeToTake($allocation, $cabin->cabin_class));
            $remaining = $allocation->seats > 0 ? min($remaining, $room) : $room;
        }

        return [
            'mode' => $allocation->mode,
            'seats' => $allocation->seats,
            'used' => $used,
            'held' => $held,
            'remaining' => $remaining,
            'is_open_for_names' => $allocation->isOpenForNames(),
            'names_deadline_at' => $allocation->namesDeadlineAt()->toIso8601String(),
            'cabins' => $allocation->cabins->map(function ($cabin) use ($allocation) {
                $usedInCabin = $this->usedSeats($allocation, $cabin->cabin_class);
                $heldInCabin = $this->heldSeats($allocation, $cabin->cabin_class);

                return [
                    'cabin_class' => $cabin->cabin_class->value,
                    'seats' => $cabin->seats,
                    'used' => $usedInCabin,
                    'held' => $heldInCabin,
                    'remaining' => $allocation->isPool()
                        ? $this->freeToTake($allocation, $cabin->cabin_class)
                        : max(0, $cabin->seats - $usedInCabin - $heldInCabin),
                ];
            })->values()->all(),
            'fares' => $allocation->fares->map(function (AgencyAllocationFare $bucket) use ($allocation) {
                $sold = $this->usedFareSeats($bucket);
                $heldOnFare = $this->heldSeats($allocation, null, $bucket);

                return [
                    'id' => $bucket->id,
                    'fare_id' => $bucket->fare_id,
                    'fare_class' => $bucket->fareClass(),
                    'cabin_class' => $bucket->cabin_class->value,
                    'seats' => $bucket->seats,
                    'sold' => $sold,
                    'held' => $heldOnFare,
                    'remaining' => $bucket->seats > 0 ? max(0, $bucket->seats - $sold - $heldOnFare) : null,
                    'price' => $bucket->priceFor(PassengerType::Adult),
                ];
            })->values()->all(),
        ];
    }

    /**
     * Throws when the cabin / fare bucket of an allocation cannot take one more passenger.
     * Seats kept by other agencies' holds count as taken; a passenger named on `$hold` uses that hold's seats.
     */
    public function assertCanTake(AgencyAllocation $allocation, CabinClass $cabin, ?AgencyAllocationFare $bucket, ?int $exceptPassengerId = null, ?AgencyHold $hold = null): void
    {
        if ($allocation->isPool()) {
            $this->assertPoolCanTake($allocation, $cabin, $exceptPassengerId, $hold);
        } else {
            $this->assertFixedCanTake($allocation, $cabin, $exceptPassengerId, $hold);
        }

        if ($bucket && $bucket->seats > 0
            && $this->usedFareSeats($bucket, $exceptPassengerId) + $this->heldSeats($allocation, null, $bucket, $hold?->id) >= $bucket->seats) {
            throw BusinessRuleException::make('fare_full', "Fare quota is full ({$bucket->seats} seat(s)).");
        }

        if ($hold && $this->namedOnHold($hold, $exceptPassengerId) >= $hold->seats) {
            throw BusinessRuleException::make('hold_full', "Every seat of this hold already has a name ({$hold->seats}).");
        }
    }

    private function assertPoolCanTake(AgencyAllocation $allocation, CabinClass $cabin, ?int $exceptPassengerId, ?AgencyHold $hold): void
    {
        $row = $allocation->cabins->firstWhere('cabin_class', $cabin);
        if (! $row) {
            throw BusinessRuleException::make('cabin_not_in_allocation', "This allocation does not sell cabin {$cabin->value}.");
        }
        if ($row->seats > 0 && $this->usedSeats($allocation, $cabin, $exceptPassengerId) + $this->heldSeats($allocation, $cabin, null, $hold?->id) >= $row->seats) {
            throw BusinessRuleException::make('cabin_full', "Cabin {$cabin->value} of this allocation is full.");
        }
        if ($allocation->seats > 0 && $this->usedSeats($allocation, null, $exceptPassengerId) + $this->heldSeats($allocation, null, null, $hold?->id) >= $allocation->seats) {
            throw BusinessRuleException::make('allocation_full', 'This allocation is full.');
        }
        if ($this->poolRoom($allocation, $cabin, $exceptPassengerId, $hold?->id) < 1) {
            throw BusinessRuleException::make('flight_full', "No seat left for sale in cabin {$cabin->value} of this flight.");
        }
    }

    private function assertFixedCanTake(AgencyAllocation $allocation, CabinClass $cabin, ?int $exceptPassengerId, ?AgencyHold $hold): void
    {
        $cabinSeats = (int) $allocation->cabins->firstWhere('cabin_class', $cabin)?->seats;
        $heldInCabin = $this->heldSeats($allocation, $cabin, null, $hold?->id);
        if ($this->usedSeats($allocation, $cabin, $exceptPassengerId) + $heldInCabin >= $cabinSeats) {
            throw BusinessRuleException::make('cabin_full', "Cabin {$cabin->value} of this allocation is full.");
        }

        if ($this->usedSeats($allocation, null, $exceptPassengerId) + $this->heldSeats($allocation, null, null, $hold?->id) >= $allocation->seats) {
            throw BusinessRuleException::make('allocation_full', 'This allocation is full.');
        }
    }

    /**
     * Seats kept by active holds that have no name yet. Holds only keep seats while the allocation takes names.
     */
    public function heldSeats(AgencyAllocation $allocation, ?CabinClass $cabin = null, ?AgencyAllocationFare $bucket = null, ?int $exceptHoldId = null): int
    {
        $allocation->loadMissing('outboundFlight');
        if (! $allocation->isOpenForNames()) {
            return 0;
        }

        return (int) AgencyHold::query()
            ->where('agency_allocation_id', $allocation->id)
            ->where('status', AgencyHold::ACTIVE)
            ->when($cabin, fn ($q) => $q->where('cabin_class', $cabin->value))
            ->when($bucket, fn ($q) => $q->where('agency_allocation_fare_id', $bucket->id))
            ->when($exceptHoldId, fn ($q) => $q->whereKeyNot($exceptHoldId))
            ->get()
            ->sum(fn (AgencyHold $h) => max(0, $h->seats - $this->namedOnHold($h)));
    }

    /** Passengers (not infants, not cancelled) whose names were entered on the hold. */
    public function namedOnHold(AgencyHold $hold, ?int $exceptPassengerId = null): int
    {
        $hold->loadMissing('allocation');

        return PassengerSegment::query()
            ->where('flight_id', $hold->allocation->outbound_flight_id)
            ->whereNotIn('status', [SegmentStatus::Cancelled])
            ->when($exceptPassengerId, fn ($q) => $q->where('passenger_id', '!=', $exceptPassengerId))
            ->whereHas('passenger', fn ($q) => $q->whereNull('infant_of_passenger_id')
                ->whereHas('booking', fn ($b) => $b->where('agency_hold_id', $hold->id)))
            ->count();
    }

    /** Unnamed seats of active pool holds on a flight: gone for everyone else. */
    public function poolHeldSeats(Flight $flight, ?CabinClass $cabin = null, ?int $exceptHoldId = null): int
    {
        return (int) AgencyHold::query()->with('allocation.outboundFlight')
            ->where('status', AgencyHold::ACTIVE)
            ->when($cabin, fn ($q) => $q->where('cabin_class', $cabin->value))
            ->when($exceptHoldId, fn ($q) => $q->whereKeyNot($exceptHoldId))
            ->whereHas('allocation', fn ($q) => $q->where('mode', AgencyAllocation::POOL)
                ->where(fn ($x) => $x->where('outbound_flight_id', $flight->id)->orWhere('return_flight_id', $flight->id)))
            ->get()
            // like heldSeats: a hold keeps seats only while its allocation takes names
            ->filter(fn (AgencyHold $h) => $h->allocation->isOpenForNames())
            ->sum(fn (AgencyHold $h) => max(0, $h->seats - $this->namedOnHold($h)));
    }

    /** Passengers on the flight that no fixed allocation pays for: direct sales and pool sales. */
    private function soldOutsideFixed(Flight $flight)
    {
        return $this->activeAdultSegments()
            ->where('flight_id', $flight->id)
            ->whereHas('passenger.booking', fn ($q) => $q->whereNull('agency_allocation_id')
                ->orWhereHas('allocation', fn ($a) => $a->where('mode', AgencyAllocation::POOL)));
    }

    private function activeAdultSegments()
    {
        return PassengerSegment::query()
            ->whereNotIn('status', [SegmentStatus::Cancelled, SegmentStatus::Waitlisted])
            ->whereHas('passenger', fn ($q) => $q->whereNull('infant_of_passenger_id'));
    }

    private function assertAgencyContracted(int $agencyId, int $airlineId): void
    {
        $contracted = DB::table('agency_airline')->where('agency_id', $agencyId)->where('airline_id', $airlineId)
            ->where('is_active', true)->exists();

        if (! $contracted) {
            throw BusinessRuleException::make('agency_not_contracted', 'This agency has no active contract with the airline.');
        }
    }

    /** @param array<string, mixed> $data */
    private function assertNotDuplicate(array $data, ?AgencyAllocation $allocation): void
    {
        $exists = AgencyAllocation::query()
            ->where('agency_id', $data['agency_id'])
            ->where('outbound_flight_id', $data['outbound_flight_id'])
            ->where('return_flight_id', $data['return_flight_id'] ?? null)
            ->when($allocation?->exists, fn ($q) => $q->whereKeyNot($allocation->id))
            ->exists();

        if ($exists) {
            throw BusinessRuleException::make('allocation_exists', 'This agency already has an allocation on these flights.');
        }
    }

    /** @param array<int, array<string, mixed>> $fares */
    private function syncFares(AgencyAllocation $allocation, array $fares): void
    {
        $keep = [];
        foreach ($fares as $row) {
            $bucket = isset($row['id']) ? $allocation->fares()->find($row['id']) : null;
            $bucket ??= $allocation->fares()->make();
            $bucket->fill([
                'fare_id' => $row['fare_id'] ?? null,
                'cabin_class' => $row['cabin_class'],
                'seats' => $row['seats'] ?? 0,
                'min_fare_seats' => $row['min_fare_seats'] ?? 0,
                'fare_amount' => $row['fare_id'] ?? null ? null : ($row['fare_amount'] ?? 0),
                'fare_max_amount' => $row['fare_id'] ?? null ? null : ($row['fare_max_amount'] ?? null),
                'tax_amount' => $row['fare_id'] ?? null ? null : ($row['tax_amount'] ?? 0),
                'child_discount_percent' => $row['fare_id'] ?? null ? 0 : ($row['child_discount_percent'] ?? 0),
                'infant_discount_percent' => $row['fare_id'] ?? null ? 0 : ($row['infant_discount_percent'] ?? 0),
            ])->save();
            $keep[] = $bucket->id;
        }

        $removed = $allocation->fares()->whereNotIn('id', $keep)->get();
        foreach ($removed as $bucket) {
            if ($this->usedFareSeats($bucket) > 0) {
                throw BusinessRuleException::make('fare_in_use', 'A fare that already has passengers cannot be removed.');
            }
            $bucket->delete();
        }
    }
}
