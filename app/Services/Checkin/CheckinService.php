<?php

namespace App\Services\Checkin;

use App\Enums\ActivityChannel;
use App\Enums\BookingStatus;
use App\Enums\CheckinChannel;
use App\Enums\FlightStatus;
use App\Enums\SegmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\AircraftSeat;
use App\Models\Flight;
use App\Models\PassengerSegment;
use App\Services\ActivityLogger;
use App\Services\Seat\SeatMapService;
use Illuminate\Support\Facades\DB;

/**
 * Check-in, seat assignment, boarding and their reversals.
 * Used by DCS, online check-in, agency check-in and the gate app.
 */
class CheckinService
{
    public function __construct(
        private readonly SeatMapService $seatMap,
        private readonly ActivityLogger $logger,
        private readonly PassengerScreening $screening,
    ) {}

    public function assertCounterOpen(Flight $flight): void
    {
        if ($flight->status === FlightStatus::Scheduled) {
            throw BusinessRuleException::make('checkin_not_started', 'Check-in has not been opened for this flight.');
        }

        if ($flight->status !== FlightStatus::CheckinOpen) {
            throw BusinessRuleException::make('checkin_closed', 'Check-in is closed for this flight.');
        }
    }

    /**
     * Check a passenger in and give them a seat. Without a seat number the next free seat
     * is picked following the flight's seat assignment profile.
     *
     * @param  array<string>|null  $autoSeatChoices  seats the automatic pick may use (online check-in restrictions), null = any
     */
    public function checkIn(
        PassengerSegment $segment,
        ?string $seatNumber,
        CheckinChannel $channel,
        bool $allowCabinChange = true,
        ?array $autoSeatChoices = null,
    ): PassengerSegment {
        return DB::transaction(function () use ($segment, $seatNumber, $channel, $allowCabinChange, $autoSeatChoices) {
            /** @var Flight $flight */
            $flight = Flight::query()->lockForUpdate()->findOrFail($segment->flight_id);
            $segment = PassengerSegment::query()->with('passenger.infant')->lockForUpdate()->findOrFail($segment->id);

            if ($segment->passenger->isInfant()) {
                throw BusinessRuleException::make('infant_checkin', 'Infants are checked in together with their adult.');
            }

            if ($segment->passenger->booking?->status === BookingStatus::OnHold) {
                throw BusinessRuleException::make('not_ticketed', 'This booking is on hold: issue the tickets before check-in.');
            }

            if (in_array($segment->status, [SegmentStatus::Cancelled, SegmentStatus::Waitlisted], true)) {
                throw BusinessRuleException::make('segment_not_confirmed', 'Only confirmed passengers can be checked in.');
            }

            if (in_array($segment->status, [SegmentStatus::Boarded, SegmentStatus::Flown], true)) {
                throw BusinessRuleException::make('already_boarded', 'Passenger has already boarded.');
            }

            if ($this->screening->isBanned($segment->passenger, $flight)) {
                throw BusinessRuleException::make('passenger_banned', 'This passenger is on the ban list and cannot be checked in.');
            }

            $seat = $seatNumber
                ? $this->requestedSeat($flight, $segment, strtoupper($seatNumber))
                : $this->autoSeat($flight, $segment, $autoSeatChoices);

            if (! $allowCabinChange && $seat->cabin_class !== $segment->cabin_class) {
                throw BusinessRuleException::make('seat_wrong_cabin', 'This seat is in another cabin.');
            }

            $oldSeat = $segment->seat_number;
            $firstCheckin = $segment->status === SegmentStatus::Booked;

            $segment->fill([
                'seat_number' => $seat->seat_number,
                'cabin_class' => $seat->cabin_class,
                'status' => SegmentStatus::CheckedIn,
                'boarding_sequence' => $segment->boarding_sequence ?? $this->nextBoardingSequence($flight),
            ]);

            if ($firstCheckin) {
                $segment->fill([
                    'checkin_channel' => $channel,
                    'checked_in_at' => now(),
                    'checked_in_by' => auth()->id(),
                ]);
            }
            $segment->save();

            $this->syncInfant($segment, SegmentStatus::CheckedIn);

            $this->logger->log(
                $this->channelFor($channel),
                $oldSeat && $oldSeat !== $seat->seat_number ? 'passenger.seat_changed' : 'passenger.checked_in',
                $oldSeat && $oldSeat !== $seat->seat_number
                    ? "Seat changed {$oldSeat} → {$seat->seat_number}"
                    : "Checked in, seat {$seat->seat_number}",
                $segment,
                $flight,
                $segment->passenger_id,
                ['seat_from' => $oldSeat, 'seat_to' => $seat->seat_number],
            );

            return $segment->refresh();
        });
    }

    /**
     * Keep a seat for a booked passenger without checking them in (counter: click the passenger, then a seat).
     * The check-in that follows takes this seat; null gives it back. Checked-in passengers change seat through checkIn.
     */
    public function holdSeat(PassengerSegment $segment, ?string $seatNumber): PassengerSegment
    {
        return DB::transaction(function () use ($segment, $seatNumber) {
            /** @var Flight $flight */
            $flight = Flight::query()->lockForUpdate()->findOrFail($segment->flight_id);
            $segment = PassengerSegment::query()->with('passenger')->lockForUpdate()->findOrFail($segment->id);

            if ($segment->passenger->isInfant()) {
                throw BusinessRuleException::make('infant_seat', 'Infants sit with their adult.');
            }
            if ($segment->status !== SegmentStatus::Booked) {
                throw BusinessRuleException::make('segment_not_booked', 'Only a booked passenger (not checked in yet) can be given a seat to hold.');
            }

            $oldSeat = $segment->seat_number;
            $seat = $seatNumber ? $this->requestedSeat($flight, $segment, strtoupper($seatNumber)) : null;
            // a seat in another cabin moves the passenger there, the same as at check-in
            $segment->update(['seat_number' => $seat?->seat_number] + ($seat ? ['cabin_class' => $seat->cabin_class] : []));

            $this->logger->log(ActivityChannel::Dcs, $seat ? 'passenger.seat_reserved' : 'passenger.seat_released',
                $seat ? "Seat {$seat->seat_number} held (not checked in)" : "Seat {$oldSeat} released",
                $segment, $flight, $segment->passenger_id, ['seat_from' => $oldSeat, 'seat_to' => $seat?->seat_number]);

            return $segment->refresh();
        });
    }

    /** Undo check-in: the passenger goes back to "booked" and loses the seat. */
    public function cancelCheckin(PassengerSegment $segment): PassengerSegment
    {
        return DB::transaction(function () use ($segment) {
            $segment = PassengerSegment::query()->with('passenger.infant')->lockForUpdate()->findOrFail($segment->id);

            if ($segment->status !== SegmentStatus::CheckedIn) {
                throw BusinessRuleException::make('not_checked_in', 'Only checked-in (not boarded) passengers can be un-checked.');
            }

            // Legacy DCS: "Offload the baggage first" — bag tags carry the boarding sequence.
            if ($segment->checkedBaggage()->exists()) {
                throw BusinessRuleException::make('baggage_not_offloaded', 'Remove the passenger\'s baggage before cancelling the check-in.');
            }

            $oldSeat = $segment->seat_number;
            $segment->update([
                'status' => SegmentStatus::Booked,
                'seat_number' => null,
                'boarding_sequence' => null,
                'checkin_channel' => null,
                'checked_in_at' => null,
                'checked_in_by' => null,
            ]);
            $this->syncInfant($segment, SegmentStatus::Booked);

            $this->logger->log(ActivityChannel::Dcs, 'passenger.checkin_cancelled', "Check-in cancelled (seat {$oldSeat})",
                $segment, $segment->flight_id, $segment->passenger_id, ['seat_from' => $oldSeat, 'seat_to' => null]);

            return $segment->refresh();
        });
    }

    /** Gate: mark as boarded. Boarding an already boarded passenger is harmless. */
    public function board(PassengerSegment $segment): PassengerSegment
    {
        return DB::transaction(function () use ($segment) {
            $segment = PassengerSegment::query()->with(['passenger.infant', 'flight'])->lockForUpdate()->findOrFail($segment->id);

            if (in_array($segment->flight->status, [FlightStatus::GateClosed, FlightStatus::Departed, FlightStatus::Cancelled], true)) {
                throw BusinessRuleException::make('flight_closed', 'This flight is closed.');
            }

            if ($segment->status === SegmentStatus::Boarded) {
                return $segment;
            }

            if ($segment->status !== SegmentStatus::CheckedIn) {
                throw BusinessRuleException::make('not_checked_in', 'This passenger has not checked in.');
            }

            $segment->update([
                'status' => SegmentStatus::Boarded,
                'boarded_at' => now(),
                'boarded_by' => auth()->id(),
                'offload_type' => null,
                'offload_reason' => null,
            ]);
            $this->syncInfant($segment, SegmentStatus::Boarded);

            $this->logger->log(ActivityChannel::Gate, 'passenger.boarded', null, $segment, $segment->flight_id, $segment->passenger_id);

            return $segment->refresh();
        });
    }

    /** Gate: take a boarded passenger off the aircraft (back to checked-in) with a reason. */
    public function offload(PassengerSegment $segment, string $type, ?string $reason): PassengerSegment
    {
        return DB::transaction(function () use ($segment, $type, $reason) {
            $segment = PassengerSegment::query()->with(['passenger.infant', 'flight'])->lockForUpdate()->findOrFail($segment->id);

            if (in_array($segment->flight->status, [FlightStatus::Departed, FlightStatus::Cancelled], true)) {
                throw BusinessRuleException::make('flight_closed', 'This flight is closed.');
            }

            if ($segment->status !== SegmentStatus::Boarded) {
                throw BusinessRuleException::make('not_boarded', 'Only boarded passengers can be offloaded.');
            }

            $segment->update([
                'status' => SegmentStatus::CheckedIn,
                'boarded_at' => null,
                'boarded_by' => null,
                'offload_type' => $type,
                'offload_reason' => $reason,
            ]);
            $this->syncInfant($segment, SegmentStatus::CheckedIn);

            $this->logger->log(ActivityChannel::Gate, 'passenger.offloaded', $reason, $segment, $segment->flight_id,
                $segment->passenger_id, ['type' => $type]);

            return $segment->refresh();
        });
    }

    private function requestedSeat(Flight $flight, PassengerSegment $segment, string $seatNumber): AircraftSeat
    {
        $seat = AircraftSeat::query()->where('aircraft_id', $flight->aircraft_id)
            ->where('seat_number', $seatNumber)->where('is_active', true)->first();

        if (! $seat) {
            throw BusinessRuleException::make('seat_not_found', "Seat {$seatNumber} does not exist on this aircraft.");
        }

        if ($segment->seat_number !== $seatNumber && ! $this->seatMap->isFree($flight, $seatNumber, $segment->id)) {
            throw BusinessRuleException::make('seat_not_available', "Seat {$seatNumber} is not available.");
        }

        return $seat;
    }

    /** Next free seat in the passenger's cabin, zones in the order of the seat assignment profile. */
    /** @param array<string>|null $choices */
    private function autoSeat(Flight $flight, PassengerSegment $segment, ?array $choices = null): AircraftSeat
    {
        if ($segment->seat_number) {
            return AircraftSeat::query()->where('aircraft_id', $flight->aircraft_id)->where('seat_number', $segment->seat_number)->firstOrFail();
        }

        $zones = $flight->seatAssignmentProfile?->orderedZones() ?? [];
        $taken = $this->seatMap->occupiedSeats($flight)->keys()->all();
        $blocked = $flight->seatBlocks()->pluck('seat_number')->all();

        $candidates = AircraftSeat::query()->where('aircraft_id', $flight->aircraft_id)->where('is_active', true)
            ->where('cabin_class', $segment->cabin_class)
            ->where('is_emergency_exit', false)
            ->whereNotIn('seat_number', array_merge($taken, $blocked))
            ->when($choices !== null, fn ($q) => $q->whereIn('seat_number', $choices))
            ->when($zones !== [], fn ($q) => $q->whereIn('zone', $zones))
            ->orderBy('seat_row')->orderBy('seat_column')
            ->get();

        $seat = $zones === []
            ? $candidates->first()
            : $candidates->sortBy(fn (AircraftSeat $s) => [array_search($s->zone, $zones, true), $s->seat_row, $s->seat_column])->first();

        if (! $seat) {
            throw BusinessRuleException::make('no_seat_available', 'No free seat left in this cabin.');
        }

        return $seat;
    }

    /** Next boarding sequence of the flight — check-in, or the first bag of a passenger not checked in yet */
    public function nextBoardingSequence(Flight $flight): int
    {
        return (int) PassengerSegment::query()->where('flight_id', $flight->id)->max('boarding_sequence') + 1;
    }

    /** An infant follows the adult it travels with (no own seat). */
    private function syncInfant(PassengerSegment $adultSegment, SegmentStatus $status): void
    {
        $infant = $adultSegment->passenger->infant;
        if (! $infant) {
            return;
        }

        PassengerSegment::query()->where('passenger_id', $infant->id)->where('flight_id', $adultSegment->flight_id)
            ->update([
                'status' => $status->value,
                'cabin_class' => $adultSegment->cabin_class->value,
                'checked_in_at' => $status === SegmentStatus::Booked ? null : ($adultSegment->checked_in_at ?? now()),
                'boarded_at' => $status === SegmentStatus::Boarded ? now() : null,
            ]);
    }

    private function channelFor(CheckinChannel $channel): ActivityChannel
    {
        return match ($channel) {
            CheckinChannel::Online => ActivityChannel::Online,
            CheckinChannel::Agency => ActivityChannel::Agency,
            CheckinChannel::Dcs => ActivityChannel::Dcs,
        };
    }
}
