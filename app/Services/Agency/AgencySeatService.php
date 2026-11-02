<?php

namespace App\Services\Agency;

use App\Enums\ActivityChannel;
use App\Enums\FlightStatus;
use App\Enums\SegmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\AgencyAllocation;
use App\Models\Flight;
use App\Models\PassengerSegment;
use App\Services\ActivityLogger;
use App\Services\Seat\SeatMapService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Seat reservation by an agency for the passengers of its quota on a charter flight (legacy OT: Oyu Tolgoi).
 * Only reserves: the segment keeps status booked with a seat, and the check-in that follows (counter or online)
 * takes that seat. The seat map applies the online check-in rules (blocked seats, exits, closed / excluded zones),
 * and other agencies' passengers stay anonymous.
 */
class AgencySeatService
{
    public function __construct(
        private readonly SeatMapService $seatMap,
        private readonly ActivityLogger $logger,
    ) {}

    /** The allocation's flight the agency works on (outbound or return), when seats can still be reserved on it. */
    public function flight(AgencyAllocation $allocation, ?int $flightId = null): Flight
    {
        $flightId ??= $allocation->outbound_flight_id;
        if (! in_array($flightId, $allocation->flightIds(), true)) {
            throw BusinessRuleException::make('flight_not_in_allocation', 'This flight is not part of the allocation.');
        }

        $flight = Flight::query()->findOrFail($flightId);
        if (! $flight->isCharter()) {
            throw BusinessRuleException::make('not_charter', 'Agencies reserve seats on charter flights only.');
        }
        if ($flight->status !== FlightStatus::Scheduled) {
            throw BusinessRuleException::make('seat_reservation_closed', 'Check-in has started: seats are given at check-in now.');
        }

        return $flight;
    }

    /** @return array<string, mixed> seat map + the agency's passengers on the flight */
    public function seatMap(AgencyAllocation $allocation, Flight $flight): array
    {
        $own = $this->segments($allocation, $flight);
        $ownIds = $own->pluck('id')->flip();
        $map = $this->seatMap->forFlight($flight, forOnlineCheckin: true, holder: $this->holder($allocation), withOccupants: true);

        $map['seats'] = array_map(function (array $seat) use ($ownIds) {
            $mine = isset($seat['occupant']) && $ownIds->has($seat['occupant']['segment_id']);
            // another agency's passenger: occupied, nothing more
            if (! $mine) {
                unset($seat['occupant']);
            }
            $seat['own'] = $mine;

            return $seat;
        }, $map['seats']);

        $map['passengers'] = $own->map(fn (PassengerSegment $s) => [
            'segment_id' => $s->id,
            'passenger_id' => $s->passenger_id,
            'name' => $s->passenger->fullName(),
            'pnr' => $s->passenger->booking?->pnr,
            'person_code' => $s->passenger->person_code,
            'cabin_class' => $s->cabin_class->value,
            'seat_number' => $s->seat_number,
            'status' => $s->status->value,
        ])->values()->all();

        return $map;
    }

    /**
     * Reserve (or with null give back) seats of the agency's passengers.
     *
     * @param  array<int, array{segment_id: int, seat_number: ?string}>  $rows
     */
    public function assign(AgencyAllocation $allocation, Flight $flight, array $rows): int
    {
        return DB::transaction(function () use ($allocation, $flight, $rows) {
            Flight::query()->whereKey($flight->id)->lockForUpdate()->first();
            $own = $this->segments($allocation, $flight)->keyBy('id');
            $seats = collect($this->seatMap->forFlight($flight, forOnlineCheckin: true, holder: $this->holder($allocation), withOccupants: true)['seats'])
                ->keyBy('seat_number');

            $wanted = collect($rows)->map(fn ($r) => isset($r['seat_number']) ? strtoupper(trim($r['seat_number'])) : null);
            $dupes = $wanted->filter()->duplicates();
            if ($dupes->isNotEmpty()) {
                throw BusinessRuleException::make('seat_twice', "Seat {$dupes->first()} is given to two passengers.");
            }
            // seats being given up in this same request are free for the others
            $released = collect($rows)->map(fn ($r) => $own->get($r['segment_id'])?->seat_number)->filter()->flip();

            $moves = [];
            foreach ($rows as $i => $row) {
                /** @var PassengerSegment|null $segment */
                $segment = $own->get($row['segment_id'])
                    ?? throw BusinessRuleException::make('segment_not_yours', 'A passenger is not on this allocation, or is already checked in.');
                $seatNumber = $wanted[$i];

                if ($seatNumber && $seatNumber !== $segment->seat_number) {
                    $seat = $seats->get($seatNumber) ?? throw BusinessRuleException::make('seat_not_found', "Seat {$seatNumber} does not exist.");
                    $free = $seat['state'] === SeatMapService::AVAILABLE
                        || ($seat['state'] === SeatMapService::OCCUPIED && $released->has($seatNumber));
                    if (! $free) {
                        throw BusinessRuleException::make('seat_not_available', "Seat {$seatNumber} cannot be reserved.");
                    }
                    if ($seat['cabin_class'] !== $segment->cabin_class->value) {
                        throw BusinessRuleException::make('seat_wrong_cabin', "Seat {$seatNumber} is in another cabin.");
                    }
                }

                if ($seatNumber !== $segment->seat_number) {
                    $moves[] = [$segment, $segment->seat_number, $seatNumber];
                }
            }

            // free the old seats first: a swap would otherwise hit the one-passenger-per-seat index
            PassengerSegment::query()->whereKey(array_map(fn ($m) => $m[0]->id, $moves))->update(['seat_number' => null]);
            foreach ($moves as [$segment, $old, $seatNumber]) {
                $segment->update(['seat_number' => $seatNumber]);
                $this->logger->log(ActivityChannel::Agency, $seatNumber ? 'passenger.seat_reserved' : 'passenger.seat_released',
                    $seatNumber ? "Seat {$seatNumber} reserved by the agency" : "Seat {$old} released by the agency",
                    $segment, $flight, $segment->passenger_id, ['seat_from' => $old, 'seat_to' => $seatNumber]);
            }
            $changed = count($moves);

            return $changed;
        });
    }

    /** Seats for every passenger of the agency without one: bookings kept together, front rows first. */
    public function autoAssign(AgencyAllocation $allocation, Flight $flight): int
    {
        $own = $this->segments($allocation, $flight)->whereNull('seat_number')
            ->sortBy(fn (PassengerSegment $s) => [$s->passenger->booking_id, $s->id]);
        $free = collect($this->seatMap->forFlight($flight, forOnlineCheckin: true, holder: $this->holder($allocation))['seats'])
            ->where('state', SeatMapService::AVAILABLE)->groupBy('cabin_class');

        $rows = [];
        foreach ($own as $segment) {
            $seat = $free->get($segment->cabin_class->value)?->shift();
            if ($seat) {
                $rows[] = ['segment_id' => $segment->id, 'seat_number' => $seat['seat_number']];
            }
        }

        return $rows ? $this->assign($allocation, $flight, $rows) : 0;
    }

    /**
     * Booked (not checked in) non-infant passengers of the allocation's agency on the flight — a return allocation's
     * passengers are booked on the outbound allocation, so the agency is the owner, not the allocation.
     *
     * @return Collection<int, PassengerSegment>
     */
    private function segments(AgencyAllocation $allocation, Flight $flight): Collection
    {
        return PassengerSegment::query()->with('passenger.booking')
            ->where('flight_id', $flight->id)
            ->where('status', SegmentStatus::Booked)
            ->whereHas('passenger', fn ($q) => $q->whereNull('infant_of_passenger_id')
                ->whereHas('booking', fn ($b) => $b->where('agency_id', $allocation->agency_id)))
            ->orderBy('id')
            ->get();
    }

    private function holder(AgencyAllocation $allocation): string
    {
        return "agency-allocation:{$allocation->id}";
    }
}
