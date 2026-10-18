<?php

namespace App\Services\Seat;

use App\Enums\SegmentStatus;
use App\Models\AircraftSeat;
use App\Models\Flight;
use App\Models\PassengerSegment;
use App\Models\SeatPrice;
use Illuminate\Support\Collection;

/**
 * The seat map of a flight with the state of every seat.
 * One implementation for DCS, agency, admin (seat blocking) and online check-in.
 */
class SeatMapService
{
    public const AVAILABLE = 'available';

    public const OCCUPIED = 'occupied';

    public const BLOCKED = 'blocked';

    public const HELD = 'held';

    public const RESTRICTED = 'restricted';

    public function __construct(private readonly SeatHoldService $holds) {}

    /**
     * @param  bool  $forOnlineCheckin  apply online check-in restrictions (load zones, exits, excluded zones, holds)
     * @param  string|null  $holder  online check-in session key; its own holds stay selectable
     * @return array{flight_id: int, layout: array<string, array<string>>, rows: array<int>, seats: array<int, array<string, mixed>>}
     */
    public function forFlight(Flight $flight, bool $forOnlineCheckin = false, ?string $holder = null, bool $withOccupants = false): array
    {
        $seats = AircraftSeat::query()->where('aircraft_id', $flight->aircraft_id)->where('is_active', true)
            ->orderBy('seat_row')->orderBy('seat_column')->get();

        $occupied = $this->occupiedSeats($flight);
        $blocked = $flight->seatBlocks()->pluck('seat_number')->flip();
        // First matching rule wins: lowest position, then the most specific
        $prices = $flight->seatPrices()->where('is_active', true)->get()
            ->sortBy([['position', 'asc'], fn (SeatPrice $a, SeatPrice $b) => $b->specificity() <=> $a->specificity()])
            ->values();
        $restrictedZones = $forOnlineCheckin ? $this->closedZonesForOnline($flight, $seats, $occupied) : [];
        $excludedZones = $forOnlineCheckin ? (array) config('flightmode.online_checkin.excluded_zones', []) : [];
        $held = $forOnlineCheckin ? $this->holds->holds($flight->id) : [];

        $items = $seats->map(function (AircraftSeat $seat) use ($occupied, $blocked, $prices, $restrictedZones, $excludedZones, $held, $holder, $forOnlineCheckin, $withOccupants) {
            $occupant = $occupied->get($seat->seat_number);
            $state = match (true) {
                $occupant !== null => self::OCCUPIED,
                $blocked->has($seat->seat_number) => self::BLOCKED,
                $forOnlineCheckin && isset($held[$seat->seat_number]) && $held[$seat->seat_number] !== $holder => self::HELD,
                $forOnlineCheckin && ($seat->is_emergency_exit
                    || in_array($seat->zone, $excludedZones, true)
                    || in_array($seat->zone, $restrictedZones, true)) => self::RESTRICTED,
                default => self::AVAILABLE,
            };

            return array_filter([
                'seat_number' => $seat->seat_number,
                'row' => $seat->seat_row,
                'column' => $seat->seat_column,
                'side' => $seat->side->value,
                'seat_type' => $seat->seat_type->value,
                'cabin_class' => $seat->cabin_class->value,
                'zone' => $seat->zone,
                'is_emergency_exit' => $seat->is_emergency_exit,
                'state' => $state,
                'price' => $this->priceFor($seat, $prices),
                'occupant' => $withOccupants && $occupant ? [
                    'segment_id' => $occupant->id,
                    'passenger_id' => $occupant->passenger_id,
                    'name' => $occupant->passenger?->fullName(),
                    'status' => $occupant->status->value,
                ] : null,
            ], fn ($v) => $v !== null);
        });

        return [
            'flight_id' => $flight->id,
            'layout' => $this->layout($seats),
            'rows' => $seats->pluck('seat_row')->unique()->values()->all(),
            'seats' => $items->values()->all(),
        ];
    }

    /** @return Collection<string, PassengerSegment> seat_number => segment */
    public function occupiedSeats(Flight $flight): Collection
    {
        return PassengerSegment::query()->with('passenger')
            ->where('flight_id', $flight->id)
            ->whereNotNull('seat_number')
            ->whereNotIn('status', [SegmentStatus::Cancelled, SegmentStatus::Waitlisted])
            ->get()
            ->keyBy('seat_number');
    }

    /** Whether a seat can be given to a passenger right now (ignores the passenger's own seat). */
    public function isFree(Flight $flight, string $seatNumber, ?int $exceptSegmentId = null): bool
    {
        $exists = AircraftSeat::query()->where('aircraft_id', $flight->aircraft_id)
            ->where('seat_number', $seatNumber)->where('is_active', true)->exists();

        if (! $exists || $flight->seatBlocks()->where('seat_number', $seatNumber)->exists()) {
            return false;
        }

        return ! PassengerSegment::query()->where('flight_id', $flight->id)->where('seat_number', $seatNumber)
            ->when($exceptSegmentId, fn ($q) => $q->whereKeyNot($exceptSegmentId))
            ->whereNotIn('status', [SegmentStatus::Cancelled, SegmentStatus::Waitlisted])
            ->exists();
    }

    /**
     * Zones that online check-in may not use yet. A zone with load rules opens once the
     * passenger count reaches `pax_from`; its capacity is the size of the opened range(s).
     *
     * @param  Collection<string, PassengerSegment>  $occupied
     * @return array<string>
     */
    public function closedZonesForOnline(Flight $flight, Collection $seats, Collection $occupied): array
    {
        $rules = $flight->aircraft?->loadZones()->get() ?? collect();
        if ($rules->isEmpty()) {
            return [];
        }

        $paxCount = PassengerSegment::query()->where('flight_id', $flight->id)
            ->whereNotIn('status', [SegmentStatus::Cancelled, SegmentStatus::Waitlisted])
            ->whereHas('passenger', fn ($q) => $q->whereNull('infant_of_passenger_id'))
            ->count();

        $zoneOfSeat = $seats->pluck('zone', 'seat_number');
        $soldPerZone = $occupied->keys()->countBy(fn ($seat) => $zoneOfSeat->get($seat));

        $closed = [];
        foreach ($rules->groupBy('zone') as $zone => $zoneRules) {
            $capacity = $zoneRules->sum(fn ($r) => $r->pax_from <= $paxCount ? min($r->pax_to, $paxCount) - $r->pax_from + 1 : 0);
            if ($capacity <= ($soldPerZone->get($zone) ?? 0)) {
                $closed[] = (string) $zone;
            }
        }

        return $closed;
    }

    /** @param Collection<int, SeatPrice> $prices */
    private function priceFor(AircraftSeat $seat, Collection $prices): ?string
    {
        return $prices->first(fn (SeatPrice $p) => $p->appliesTo($seat))?->price;
    }

    /**
     * Seat letters per side, in display order: { left: [A,B,C], middle: [], right: [D,E,F] }.
     *
     * @param  Collection<int, AircraftSeat>  $seats
     * @return array<string, array<string>>
     */
    private function layout(Collection $seats): array
    {
        $layout = ['left' => [], 'middle' => [], 'right' => []];
        foreach ($seats->groupBy(fn (AircraftSeat $s) => $s->side->value) as $side => $sideSeats) {
            $layout[$side] = $sideSeats->pluck('seat_column')->unique()->sort()->values()->all();
        }

        return $layout;
    }
}
