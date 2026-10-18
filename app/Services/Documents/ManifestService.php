<?php

namespace App\Services\Documents;

use App\Enums\PassengerType;
use App\Enums\SegmentStatus;
use App\Models\AircraftSeat;
use App\Models\CheckedBaggage;
use App\Models\Flight;
use App\Models\PassengerSegment;
use App\Services\Flight\FlightStatistics;
use Illuminate\Support\Collection;

/**
 * Flight documents printed / exported after check-in:
 * passenger manifest, baggage manifest, balance (zone load) and excess baggage list.
 */
class ManifestService
{
    public function __construct(private readonly FlightStatistics $statistics) {}

    /** @return array<string, mixed> */
    public function header(Flight $flight): array
    {
        $flight->loadMissing(['airline', 'aircraft', 'origin', 'destination']);
        $tz = $flight->origin->timezone;
        $departure = $flight->departureAt()->copy()->timezone($tz);
        $scheduled = $flight->scheduled_departure_at->copy()->timezone($tz);
        $estimated = $flight->estimated_departure_at?->copy()->timezone($tz);

        return [
            'airline' => $flight->airline->name,
            'airline_logo' => $flight->airline->logoUrl(),
            'flight_number' => $flight->flight_number,
            'registration' => $flight->aircraft?->registration,
            'aircraft_type' => $flight->aircraft?->type,
            'route' => $flight->route(),
            'origin' => $flight->origin->iata_code,
            'destination' => $flight->destination->iata_code,
            'departure_date' => $departure->format('Y-m-d'),
            'departure_time' => $departure->format('H:i'),
            // printed manifests: STD, plus ETD when the flight is delayed
            'std_date' => $scheduled->format('Y-m-d'),
            'std_time' => $scheduled->format('H:i'),
            'etd_date' => $estimated?->format('Y-m-d'),
            'etd_time' => $estimated?->format('H:i'),
            'gate' => $flight->gate,
        ];
    }

    /** Checked-in passengers with seat and baggage. */
    public function passengers(Flight $flight): array
    {
        $rows = $this->checkedIn($flight)->map(fn (PassengerSegment $s) => [
            'segment_id' => $s->id,
            'name' => $s->passenger->last_name.'/'.$s->passenger->first_name,
            'first_name' => $s->passenger->first_name,
            'last_name' => $s->passenger->last_name,
            'gender' => $s->passenger->gender?->value,
            'type' => ($type = $s->passenger->type ?? PassengerType::Adult)->value,
            // legacy GDR column: M / F for adults, CHD / INF otherwise
            'gdr' => $type === PassengerType::Adult ? ($s->passenger->gender?->value ?? '') : $type->value,
            'nationality' => $s->passenger->nationality,
            'date_of_birth' => $s->passenger->date_of_birth?->format('Y-m-d'),
            'remark' => $s->passenger->remark,
            'pnr' => $s->passenger->booking->pnr,
            'cabin_class' => $s->cabin_class->value,
            'seat_number' => $s->seat_number,
            'boarding_sequence' => $s->boarding_sequence,
            'ssr' => $s->ssrCodes->pluck('code')->implode(' '),
            'online' => $s->checkin_channel?->value === 'online',
            'bag_pieces' => (int) $s->checkedBaggage->sum('pieces'),
            'bag_weight_kg' => (float) $s->checkedBaggage->sum('weight_kg'),
            // legacy "*": checked in online and came to the airport without bags
            'online_no_bags' => $s->checkin_channel?->value === 'online' && $s->checkedBaggage->isEmpty(),
        ])->sortBy('name')->values();

        $types = $rows->countBy('type');

        return [
            'rows' => $rows->all(),
            'totals' => [
                'ADT' => $types->get('ADT', 0),
                'CHD' => $types->get('CHD', 0),
                'INF' => $types->get('INF', 0),
                'total' => $rows->count(),
            ],
        ];
    }

    /** Baggage per acceptance with its tags, crew bags last. */
    public function baggage(Flight $flight): array
    {
        $rows = CheckedBaggage::query()->with(['tags', 'segment.passenger', 'rule'])
            ->where('flight_id', $flight->id)
            ->get()
            ->sortBy(fn (CheckedBaggage $b) => [$b->passenger_segment_id === null ? 1 : 0, $b->segment?->passenger?->last_name])
            ->map(fn (CheckedBaggage $b) => [
                'id' => $b->id,
                'type' => $b->type->value,
                'segment_id' => $b->passenger_segment_id,
                'passenger' => $b->segment ? $b->segment->passenger->last_name.'/'.$b->segment->passenger->first_name : 'CREW'.($b->crew_name ? ' '.$b->crew_name : ''),
                'seat_number' => $b->segment?->seat_number,
                'cabin_class' => $b->segment?->cabin_class->value,
                'checkin_group_id' => $b->checkin_group_id,
                'pieces' => $b->pieces,
                'weight_kg' => (float) $b->weight_kg,
                'tags' => $b->tags->pluck('tag_number')->all(),
                'rule' => $b->rule?->name,
                'description' => $b->description,
            ])->values();

        return [
            'rows' => $rows->all(),
            'totals' => ['pieces' => $rows->sum('pieces'), 'weight_kg' => $rows->sum('weight_kg')],
        ];
    }

    public function balance(Flight $flight): array
    {
        // bags / kg per zone: the bags of the checked-in passengers seated there
        $zoneOf = AircraftSeat::query()->where('aircraft_id', $flight->aircraft_id)->pluck('zone', 'seat_number');
        $bags = $this->checkedIn($flight)->filter(fn (PassengerSegment $s) => $s->seat_number)
            ->groupBy(fn (PassengerSegment $s) => $zoneOf->get($s->seat_number) ?? '-');
        $zones = collect($this->statistics->zoneLoad($flight))->map(fn (array $z) => $z + [
            'bags' => (int) ($bags->get($z['zone'] ?? '-')?->sum(fn ($s) => $s->checkedBaggage->sum('pieces')) ?? 0),
            'kg' => (float) ($bags->get($z['zone'] ?? '-')?->sum(fn ($s) => $s->checkedBaggage->sum('weight_kg')) ?? 0),
        ]);

        return [
            'rows' => $zones->all(),
            'totals' => [
                'male_adults' => $zones->sum('male_adults'),
                'female_adults' => $zones->sum('female_adults'),
                'children' => $zones->sum('children'),
                'infants' => $zones->sum('infants'),
                'total' => $zones->sum('total'),
                'bags' => $zones->sum('bags'),
                'kg' => $zones->sum('kg'),
            ],
        ];
    }

    /** Baggage that was charged (excess / paid bags). */
    public function excessBaggage(Flight $flight): array
    {
        return CheckedBaggage::query()->with(['tags', 'segment.passenger'])
            ->where('flight_id', $flight->id)
            ->where(fn ($q) => $q->where('price', '>', 0)->orWhere('excess_price', '>', 0))
            ->get()
            ->map(fn (CheckedBaggage $b) => [
                'passenger' => $b->segment ? $b->segment->passenger->last_name.'/'.$b->segment->passenger->first_name : 'CREW',
                'seat_number' => $b->segment?->seat_number,
                'tags' => $b->tags->pluck('tag_number')->all(),
                'weight_kg' => (float) $b->weight_kg,
                'price' => (float) $b->price,
                'excess_price' => (float) $b->excess_price,
                'payment_reference' => $b->payment_reference,
                'waived_amount' => (float) $b->waived_amount,
                'waive_reason' => $b->waive_reason,
                'waive_authorized_by' => $b->waive_authorized_by,
                'currency' => $b->currency,
                'description' => $b->description,
            ])->values()->all();
    }

    /**
     * @param  array<string>  $parts  subset of: passengers, baggage, balance, excess_baggage
     * @return array<string, mixed>
     */
    public function build(Flight $flight, array $parts): array
    {
        $document = ['header' => $this->header($flight)];
        foreach ($parts as $part) {
            $document[$part] = match ($part) {
                'passengers' => $this->passengers($flight),
                'baggage' => $this->baggage($flight),
                'balance' => $this->balance($flight),
                'excess_baggage' => $this->excessBaggage($flight),
            };
        }

        return $document;
    }

    /** @return Collection<int, PassengerSegment> */
    private function checkedIn(Flight $flight): Collection
    {
        return PassengerSegment::query()->with(['passenger.booking', 'ssrCodes', 'checkedBaggage'])
            ->where('flight_id', $flight->id)
            ->whereIn('status', [SegmentStatus::CheckedIn, SegmentStatus::Boarded, SegmentStatus::Flown])
            ->get();
    }
}
