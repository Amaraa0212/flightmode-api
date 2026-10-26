<?php

namespace App\Services\Allocation;

use App\Enums\ActivityChannel;
use App\Enums\CabinClass;
use App\Exceptions\BusinessRuleException;
use App\Models\AgencyAllocation;
use App\Models\AgencyHold;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Agency holds: seats of the agency's own allocation kept now, names entered later (BookingService with `hold_id`).
 * Held seats without a name count as taken for everyone else selling on the allocation until the names deadline.
 */
class HoldService
{
    public function __construct(
        private readonly AllocationService $allocations,
        private readonly ActivityLogger $logger,
    ) {}

    public function create(AgencyAllocation $allocation, CabinClass $cabin, int $seats, ?int $bucketId, User $actor, ActivityChannel $channel): AgencyHold
    {
        return DB::transaction(function () use ($allocation, $cabin, $seats, $bucketId, $actor, $channel) {
            $allocation = AgencyAllocation::query()->with(['cabins', 'fares', 'outboundFlight'])->lockForUpdate()->findOrFail($allocation->id);

            if (! $allocation->isOpenForNames()) {
                throw BusinessRuleException::make('names_deadline_passed', 'The name list deadline for this allocation has passed.');
            }

            $cabinSeats = (int) $allocation->cabins->firstWhere('cabin_class', $cabin)?->seats;
            if ($cabinSeats < 1) {
                throw BusinessRuleException::make('cabin_not_in_allocation', "This allocation has no seats in cabin {$cabin->value}.");
            }

            $bucket = $bucketId ? $allocation->fares->firstWhere('id', $bucketId) : null;
            if ($bucketId && ! $bucket) {
                throw BusinessRuleException::make('fare_not_in_allocation', 'The chosen fare does not belong to this allocation.');
            }
            if ($allocation->uses_fare_quotas && ! $bucket) {
                throw BusinessRuleException::make('fare_required', 'Choose a fare for this allocation.');
            }
            if ($bucket && $bucket->cabin_class !== $cabin) {
                throw BusinessRuleException::make('fare_wrong_cabin', 'The chosen fare is for another cabin.');
            }

            $free = min(
                $cabinSeats - $this->allocations->usedSeats($allocation, $cabin) - $this->allocations->heldSeats($allocation, $cabin),
                $allocation->seats - $this->allocations->usedSeats($allocation) - $this->allocations->heldSeats($allocation),
                $bucket && $bucket->seats > 0
                    ? $bucket->seats - $this->allocations->usedFareSeats($bucket) - $this->allocations->heldSeats($allocation, null, $bucket)
                    : PHP_INT_MAX,
            );
            if ($seats > $free) {
                throw BusinessRuleException::make('not_enough_seats', 'Only '.max(0, $free).' seat(s) left to hold.');
            }

            $hold = AgencyHold::create([
                'airline_id' => $allocation->airline_id,
                'agency_id' => $allocation->agency_id,
                'agency_allocation_id' => $allocation->id,
                'agency_allocation_fare_id' => $bucket?->id,
                'cabin_class' => $cabin,
                'seats' => $seats,
                'created_by' => $actor->id,
            ]);

            $this->logger->log($channel, 'hold.created', "{$seats} seat(s) {$cabin->value}", $hold, $allocation->outbound_flight_id);

            return $hold;
        });
    }

    /** Frees the seats that have no name yet; named passengers stay booked. */
    public function release(AgencyHold $hold, ActivityChannel $channel): AgencyHold
    {
        if (! $hold->isActive()) {
            throw BusinessRuleException::make('hold_released', 'This hold is already released.');
        }

        $hold->update(['status' => AgencyHold::RELEASED, 'released_at' => now()]);
        $this->logger->log($channel, 'hold.released', null, $hold, $hold->allocation?->outbound_flight_id);

        return $hold;
    }

    /** @return array{seats: int, named: int, remaining: int, is_open_for_names: bool} */
    public function usage(AgencyHold $hold): array
    {
        $named = $this->allocations->namedOnHold($hold);
        $open = $hold->isActive() && $hold->allocation->isOpenForNames();

        return [
            'seats' => $hold->seats,
            'named' => $named,
            'remaining' => $open ? max(0, $hold->seats - $named) : 0,
            'is_open_for_names' => $open,
        ];
    }
}
