<?php

namespace App\Services\Flight;

use App\Enums\CabinClass;
use App\Enums\CheckinChannel;
use App\Enums\Gender;
use App\Enums\PassengerType;
use App\Enums\SegmentStatus;
use App\Models\AgencyAllocation;
use App\Models\AircraftSeat;
use App\Models\CheckedBaggage;
use App\Models\Flight;
use App\Models\PassengerSegment;
use Illuminate\Support\Collection;

/**
 * Passenger / baggage counts of a flight. One implementation for the DCS side bar, gate app,
 * flight lists, manifests and reports (the legacy app had five different versions of these loops).
 */
class FlightStatistics
{
    /** @return array<string, mixed> */
    public function summary(Flight $flight): array
    {
        $flight->loadMissing('aircraft.cabins');
        $segments = $this->segments($flight);
        $holding = $segments->filter(fn (PassengerSegment $s) => ! in_array($s->status, [SegmentStatus::Cancelled, SegmentStatus::Waitlisted], true));

        return [
            'capacity' => [
                'total' => (int) $flight->aircraft?->total_seats,
                'cabins' => $flight->aircraft?->cabinSeatCounts() ?? [],
            ],
            'booked' => $this->countByType($holding),
            'checked_in' => $this->countByType($holding->filter(fn (PassengerSegment $s) => $s->isCheckedIn())),
            'boarded' => $this->countByType($holding->filter(fn (PassengerSegment $s) => in_array($s->status, [SegmentStatus::Boarded, SegmentStatus::Flown], true))),
            'waitlisted' => $segments->where('status', SegmentStatus::Waitlisted)->count(),
            'go_show' => $holding->where('is_go_show', true)->count(),
            'online_checkin' => $holding->filter(fn (PassengerSegment $s) => $s->checkin_channel === CheckinChannel::Online)->count(),
            'by_cabin' => $this->byCabin($holding),
            'ssr' => $this->ssrCounts($holding),
            'baggage' => $this->baggage($flight),
        ];
    }

    /** Per agency allocation: seats given and passengers booked. */
    public function allocations(Flight $flight): array
    {
        return AgencyAllocation::query()->with(['agency', 'cabins'])
            ->where(fn ($q) => $q->where('outbound_flight_id', $flight->id)->orWhere('return_flight_id', $flight->id))
            ->get()
            ->map(function (AgencyAllocation $allocation) use ($flight) {
                $segments = PassengerSegment::query()->with('passenger')
                    ->where('flight_id', $flight->id)
                    ->whereNotIn('status', [SegmentStatus::Cancelled, SegmentStatus::Waitlisted])
                    ->whereHas('passenger.booking', fn ($q) => $q->where('agency_allocation_id', $allocation->id))
                    ->get();

                return [
                    'allocation_id' => $allocation->id,
                    'agency' => ['id' => $allocation->agency_id, 'name' => $allocation->agency->name],
                    'seats' => $allocation->seats,
                    'cabins' => $allocation->cabins->mapWithKeys(fn ($c) => [$c->cabin_class->value => $c->seats])->all(),
                    'booked' => $this->countByType($segments),
                    'by_cabin' => $this->byCabin($segments),
                ];
            })->values()->all();
    }

    /**
     * Checked-in passengers per seat zone, for the balance / load sheet.
     *
     * @return array<int, array{zone: ?string, total: int, male_adults: int, female_adults: int, children: int, infants: int}>
     */
    public function zoneLoad(Flight $flight): array
    {
        $zones = AircraftSeat::query()->where('aircraft_id', $flight->aircraft_id)->pluck('zone', 'seat_number');
        $segments = $this->segments($flight)->filter(fn (PassengerSegment $s) => $s->isCheckedIn());
        $seatOf = $segments->filter(fn ($s) => $s->seat_number)->mapWithKeys(fn ($s) => [$s->passenger_id => $s->seat_number]);

        return $segments
            ->groupBy(function (PassengerSegment $s) use ($zones, $seatOf) {
                // infants sit with their adult
                $seat = $s->seat_number ?? $seatOf->get($s->passenger->infant_of_passenger_id);

                return $zones->get($seat) ?? '-';
            })
            ->map(fn (Collection $group, $zone) => [
                'zone' => $zone === '-' ? null : $zone,
                'total' => $group->filter(fn ($s) => $s->passenger->type !== PassengerType::Infant)->count(),
                'male_adults' => $group->filter(fn ($s) => $s->passenger->type === PassengerType::Adult && $s->passenger->gender !== Gender::Female)->count(),
                'female_adults' => $group->filter(fn ($s) => $s->passenger->type === PassengerType::Adult && $s->passenger->gender === Gender::Female)->count(),
                'children' => $group->filter(fn ($s) => $s->passenger->type === PassengerType::Child)->count(),
                'infants' => $group->filter(fn ($s) => $s->passenger->type === PassengerType::Infant)->count(),
            ])
            ->sortKeys()->values()->all();
    }

    /** @return array{pieces: int, weight_kg: float, passengers: int, crew_pieces: int, crew_weight_kg: float, excess_amount: float} */
    public function baggage(Flight $flight): array
    {
        $all = CheckedBaggage::query()->where('flight_id', $flight->id)->get();
        $pax = $all->whereNotNull('passenger_segment_id');
        $crew = $all->whereNull('passenger_segment_id');

        return [
            'pieces' => (int) $pax->sum('pieces'),
            'weight_kg' => (float) $pax->sum('weight_kg'),
            'passengers' => $pax->pluck('passenger_segment_id')->unique()->count(),
            'crew_pieces' => (int) $crew->sum('pieces'),
            'crew_weight_kg' => (float) $crew->sum('weight_kg'),
            'excess_amount' => (float) $pax->sum(fn ($b) => (float) $b->price + (float) $b->excess_price),
        ];
    }

    /** @return Collection<int, PassengerSegment> */
    private function segments(Flight $flight): Collection
    {
        return PassengerSegment::query()->with(['passenger', 'ssrCodes'])->where('flight_id', $flight->id)->get();
    }

    /** @param Collection<int, PassengerSegment> $segments */
    private function countByType(Collection $segments): array
    {
        $counts = ['ADT' => 0, 'CHD' => 0, 'INF' => 0];
        foreach ($segments as $segment) {
            $counts[($segment->passenger->type ?? PassengerType::Adult)->value]++;
        }

        return $counts + ['total' => $counts['ADT'] + $counts['CHD'], 'total_with_infants' => array_sum($counts)];
    }

    /** @param Collection<int, PassengerSegment> $segments */
    private function byCabin(Collection $segments): array
    {
        $result = [];
        foreach (CabinClass::cases() as $cabin) {
            $inCabin = $segments->filter(fn (PassengerSegment $s) => $s->cabin_class === $cabin);
            if ($inCabin->isNotEmpty()) {
                $result[$cabin->value] = $this->countByType($inCabin);
            }
        }

        return $result;
    }

    /** @param Collection<int, PassengerSegment> $segments */
    private function ssrCounts(Collection $segments): array
    {
        return $segments->flatMap(fn (PassengerSegment $s) => $s->ssrCodes->pluck('code'))
            ->countBy()->sortKeys()->all();
    }
}
