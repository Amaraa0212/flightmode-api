<?php

namespace App\Services\Allocation;

use App\Enums\CabinClass;
use App\Enums\SegmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\AgencyAllocation;
use App\Models\AgencyAllocationFare;
use App\Models\Flight;
use App\Models\PassengerSegment;
use App\Services\Integrations\Chinggis\ChinggisSync;
use Illuminate\Support\Facades\DB;

/**
 * Agency seat allocations: creation with quota checks, usage and availability.
 * "Used" always means: non-infant passengers of this allocation that hold a place on the outbound flight.
 */
class AllocationService
{
    public function __construct(private readonly ChinggisSync $chinggis) {}

    /**
     * @param array{airline_id: int, agency_id: int, outbound_flight_id: int, return_flight_id?: ?int, seats: int, deadline: string,
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

            foreach (array_filter([$outbound, $return]) as $flight) {
                $free = $this->seatsLeftForAllocations($flight, $allocation);
                if ($data['seats'] > $free) {
                    throw BusinessRuleException::make('not_enough_seats',
                        "Flight {$flight->flight_number} has only {$free} seat(s) left for allocations.");
                }
            }

            if ($allocation && $data['seats'] < $this->usedSeats($allocation)) {
                throw BusinessRuleException::make('below_used', 'Seats cannot be lower than the passengers already booked.');
            }

            $cabinTotal = array_sum($data['cabins']);
            if ($cabinTotal > $data['seats']) {
                throw BusinessRuleException::make('cabins_exceed_seats', 'Cabin seats add up to more than the allocation.');
            }

            $allocation ??= new AgencyAllocation(['created_by' => auth()->id()]);
            $allocation->fill([
                'airline_id' => $data['airline_id'],
                'agency_id' => $data['agency_id'],
                'outbound_flight_id' => $outbound->id,
                'return_flight_id' => $return?->id,
                'seats' => $data['seats'],
                'deadline' => $data['deadline'],
                'is_waitlist' => $data['is_waitlist'] ?? false,
                'uses_fare_quotas' => ! empty($data['fares']),
            ])->save();

            $allocation->cabins()->delete();
            foreach ($data['cabins'] as $cabin => $seats) {
                if ($seats > 0) {
                    $allocation->cabins()->create(['cabin_class' => CabinClass::from($cabin), 'seats' => $seats]);
                }
            }

            $this->syncFares($allocation, $data['fares'] ?? []);

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

    /** Seats of a flight not yet given to allocations or sold directly by the airline. */
    public function seatsLeftForAllocations(Flight $flight, ?AgencyAllocation $except = null): int
    {
        $total = (int) $flight->aircraft?->total_seats;

        $allocated = AgencyAllocation::query()
            ->where(fn ($q) => $q->where('outbound_flight_id', $flight->id)->orWhere('return_flight_id', $flight->id))
            ->when($except?->exists, fn ($q) => $q->whereKeyNot($except->id))
            ->sum('seats');

        $direct = $this->activeAdultSegments()
            ->where('flight_id', $flight->id)
            ->whereHas('passenger.booking', fn ($q) => $q->whereNull('agency_allocation_id'))
            ->count();

        return max(0, $total - (int) $allocated - $direct);
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
        return PassengerSegment::query()
            ->where('agency_allocation_fare_id', $bucket->id)
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

        return [
            'seats' => $allocation->seats,
            'used' => $used,
            'remaining' => max(0, $allocation->seats - $used),
            'is_open_for_names' => $allocation->isOpenForNames(),
            'names_deadline_at' => $allocation->namesDeadlineAt()->toIso8601String(),
            'cabins' => $allocation->cabins->map(function ($cabin) use ($allocation) {
                $usedInCabin = $this->usedSeats($allocation, $cabin->cabin_class);

                return [
                    'cabin_class' => $cabin->cabin_class->value,
                    'seats' => $cabin->seats,
                    'used' => $usedInCabin,
                    'remaining' => max(0, $cabin->seats - $usedInCabin),
                ];
            })->values()->all(),
            'fares' => $allocation->fares->map(function (AgencyAllocationFare $bucket) {
                $sold = $this->usedFareSeats($bucket);

                return [
                    'id' => $bucket->id,
                    'fare_id' => $bucket->fare_id,
                    'fare_class' => $bucket->fareClass(),
                    'cabin_class' => $bucket->cabin_class->value,
                    'seats' => $bucket->seats,
                    'sold' => $sold,
                    'remaining' => $bucket->seats > 0 ? max(0, $bucket->seats - $sold) : null,
                ];
            })->values()->all(),
        ];
    }

    /** Throws when the cabin / fare bucket of an allocation cannot take one more passenger. */
    public function assertCanTake(AgencyAllocation $allocation, CabinClass $cabin, ?AgencyAllocationFare $bucket, ?int $exceptPassengerId = null): void
    {
        $cabinSeats = (int) $allocation->cabins->firstWhere('cabin_class', $cabin)?->seats;
        if ($this->usedSeats($allocation, $cabin, $exceptPassengerId) >= $cabinSeats) {
            throw BusinessRuleException::make('cabin_full', "Cabin {$cabin->value} of this allocation is full.");
        }

        if ($this->usedSeats($allocation, null, $exceptPassengerId) >= $allocation->seats) {
            throw BusinessRuleException::make('allocation_full', 'This allocation is full.');
        }

        if ($bucket && $bucket->seats > 0 && $this->usedFareSeats($bucket, $exceptPassengerId) >= $bucket->seats) {
            throw BusinessRuleException::make('fare_full', "Fare quota is full ({$bucket->seats} seat(s)).");
        }
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
