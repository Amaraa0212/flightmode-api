<?php

namespace App\Services\Reports;

use App\Enums\CheckinChannel;
use App\Enums\FlightStatus;
use App\Enums\SegmentStatus;
use App\Models\Flight;
use App\Models\PassengerSegment;
use App\Models\User;
use App\Services\Access\AccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Back-office reports. Every query is limited to what the user may see (AccessScope). */
class ReportService
{
    public function __construct(private readonly AccessScope $access) {}

    /**
     * Per flight: booked / flown / no-show / go-show / online check-in / baggage (legacy "traffic summary" & "station report").
     *
     * @param  array{from?: ?string, to?: ?string, origin?: ?string, destination?: ?string, round_trip?: bool, departed_only?: bool}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function traffic(User $user, array $filters): Collection
    {
        $flights = $this->flights($user, $filters)
            ->when($filters['departed_only'] ?? false, fn ($q) => $q->where('status', FlightStatus::Departed->value))
            ->with(['aircraft', 'origin', 'destination'])
            ->orderBy('scheduled_departure_at')
            ->get();

        $counts = PassengerSegment::query()
            ->whereIn('flight_id', $flights->pluck('id'))
            ->whereNotIn('status', [SegmentStatus::Cancelled->value, SegmentStatus::Waitlisted->value])
            ->whereHas('passenger', fn ($q) => $q->whereNull('infant_of_passenger_id'))
            ->select('flight_id')
            ->selectRaw('COUNT(*) as booked')
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as flown', [SegmentStatus::Boarded->value, SegmentStatus::Flown->value])
            ->selectRaw('SUM(CASE WHEN is_go_show = 1 THEN 1 ELSE 0 END) as go_show')
            ->selectRaw('SUM(CASE WHEN checkin_channel = ? THEN 1 ELSE 0 END) as online_checkin', [CheckinChannel::Online->value])
            ->groupBy('flight_id')
            ->get()->keyBy('flight_id');

        $baggage = DB::table('checked_baggage')->whereIn('flight_id', $flights->pluck('id'))
            ->select('flight_id')->selectRaw('SUM(pieces) as pieces, SUM(weight_kg) as weight')
            ->groupBy('flight_id')->get()->keyBy('flight_id');

        return $flights->map(function (Flight $flight) use ($counts, $baggage) {
            $c = $counts->get($flight->id);
            $b = $baggage->get($flight->id);
            $booked = (int) ($c->booked ?? 0);
            $flown = (int) ($c->flown ?? 0);
            $online = (int) ($c->online_checkin ?? 0);
            $departed = $flight->status === FlightStatus::Departed;

            return [
                'flight_id' => $flight->id,
                'flight_number' => $flight->flight_number,
                'route' => $flight->route(),
                'departure_at' => $flight->departureAt()->toIso8601String(),
                'status' => $flight->status->value,
                'aircraft_type' => $flight->aircraft?->type,
                'capacity' => (int) $flight->aircraft?->total_seats,
                'booked' => $booked,
                'flown' => $flown,
                'no_show' => $departed ? max(0, $booked - $flown) : null,
                'go_show' => (int) ($c->go_show ?? 0),
                'online_checkin' => $online,
                'online_checkin_percent' => $booked > 0 ? round($online * 100 / $booked, 1) : 0,
                'load_factor_percent' => $flight->aircraft?->total_seats ? round($flown * 100 / $flight->aircraft->total_seats, 1) : null,
                'baggage_pieces' => (int) ($b->pieces ?? 0),
                'baggage_weight_kg' => (float) ($b->weight ?? 0),
            ];
        })->values();
    }

    /**
     * Agency sales priced from fare quotas, per flight and fare class (legacy "fare ticket report").
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function fareSales(User $user, array $filters): Collection
    {
        $flightIds = $this->flights($user, $filters)->pluck('flights.id');

        return PassengerSegment::query()
            ->join('flights', 'flights.id', '=', 'passenger_segments.flight_id')
            ->join('passengers', 'passengers.id', '=', 'passenger_segments.passenger_id')
            ->join('bookings', 'bookings.id', '=', 'passengers.booking_id')
            ->leftJoin('agencies', 'agencies.id', '=', 'bookings.agency_id')
            ->whereIn('passenger_segments.flight_id', $flightIds)
            ->whereNotIn('passenger_segments.status', [SegmentStatus::Cancelled->value])
            ->whereNull('passenger_segments.deleted_at')
            ->where(fn ($q) => $q->where('passenger_segments.fare_amount', '>', 0)->orWhere('passenger_segments.tax_amount', '>', 0))
            ->groupBy('flights.id', 'flights.flight_number', 'flights.scheduled_departure_at', 'agencies.name', 'passenger_segments.fare_class', 'passenger_segments.currency')
            ->orderBy('flights.scheduled_departure_at')
            ->get([
                'flights.id as flight_id', 'flights.flight_number', 'flights.scheduled_departure_at',
                'agencies.name as agency', 'passenger_segments.fare_class', 'passenger_segments.currency',
                DB::raw('COUNT(*) as tickets'),
                DB::raw('SUM(passenger_segments.fare_amount) as fare_total'),
                DB::raw('SUM(passenger_segments.tax_amount) as tax_total'),
                DB::raw('SUM(passenger_segments.agency_sale_amount) as agency_sale_total'),
            ])
            ->map(fn ($r) => [
                'flight_id' => $r->flight_id,
                'flight_number' => $r->flight_number,
                'departure_at' => Carbon::parse($r->scheduled_departure_at)->toIso8601String(),
                'agency' => $r->agency,
                'fare_class' => $r->fare_class,
                'currency' => $r->currency,
                'tickets' => (int) $r->tickets,
                'fare_total' => (float) $r->fare_total,
                'tax_total' => (float) $r->tax_total,
                'agency_sale_total' => (float) $r->agency_sale_total,
            ]);
    }

    /**
     * Flown passengers and online check-in share per month (legacy "load factor / OCI" report).
     *
     * @return array<int, array{month: int, flown: int, online_checkin: int, percent: float}>
     */
    public function onlineCheckinByMonth(User $user, int $year, ?string $origin = null, ?string $destination = null): array
    {
        $flightIds = $this->flights($user, [
            'from' => "{$year}-01-01", 'to' => "{$year}-12-31", 'origin' => $origin, 'destination' => $destination, 'round_trip' => true,
        ])->pluck('flights.id');

        $rows = PassengerSegment::query()
            ->join('flights', 'flights.id', '=', 'passenger_segments.flight_id')
            ->whereIn('passenger_segments.flight_id', $flightIds)
            ->whereIn('passenger_segments.status', [SegmentStatus::Boarded->value, SegmentStatus::Flown->value])
            ->whereNull('passenger_segments.deleted_at')
            ->get(['flights.scheduled_departure_at', 'passenger_segments.checkin_channel'])
            ->groupBy(fn ($r) => Carbon::parse($r->scheduled_departure_at)->month);

        return collect(range(1, 12))->map(function (int $month) use ($rows) {
            $group = $rows->get($month, collect());
            $flown = $group->count();
            $online = $group->filter(fn ($r) => $r->checkin_channel === CheckinChannel::Online)->count();

            return ['month' => $month, 'flown' => $flown, 'online_checkin' => $online, 'percent' => $flown ? round($online * 100 / $flown, 1) : 0.0];
        })->all();
    }

    /**
     * Find passengers by name, PNR, ticket or document number.
     *
     * @param  array{query: string, from?: ?string, to?: ?string}  $filters
     */
    public function passengerSearch(User $user, array $filters, int $perPage = 50): LengthAwarePaginator
    {
        $term = trim($filters['query']);
        $flightIds = $this->flights($user, $filters)->pluck('flights.id');

        return PassengerSegment::query()->with(['passenger.booking.agency', 'flight.origin', 'flight.destination'])
            ->whereIn('flight_id', $flightIds)
            ->where(function (Builder $q) use ($term) {
                $q->where('ticket_number', $term)
                    ->orWhereHas('passenger', fn (Builder $p) => $p
                        ->where('document_number', $term)
                        ->orWhere('person_code', $term)
                        ->orWhere('first_name', 'like', "%{$term}%")
                        ->orWhere('last_name', 'like', "%{$term}%"))
                    ->orWhereHas('passenger.booking', fn (Builder $b) => $b->where('pnr', strtoupper($term)));
            })
            ->latest('id')
            ->paginate($perPage);
    }

    /** Routes the user flies (for report filters). */
    public function routes(User $user): array
    {
        return $this->access->flights(Flight::query(), $user)
            ->join('airports as o', 'o.id', '=', 'flights.origin_airport_id')
            ->join('airports as d', 'd.id', '=', 'flights.destination_airport_id')
            ->distinct()
            ->orderBy('o.iata_code')
            ->get(['o.iata_code as origin', 'd.iata_code as destination'])
            ->map(fn ($r) => ['origin' => $r->origin, 'destination' => $r->destination])
            ->all();
    }

    /** @param array<string, mixed> $filters */
    private function flights(User $user, array $filters): Builder
    {
        $from = isset($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : now()->startOfMonth();
        $to = isset($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : now()->endOfMonth();

        $query = $this->access->flights(Flight::query(), $user)
            ->whereBetween('flights.scheduled_departure_at', [$from, $to]);

        $origin = $filters['origin'] ?? null;
        $destination = $filters['destination'] ?? null;

        if ($origin && $destination) {
            $ids = fn ($code) => DB::table('airports')->where('iata_code', strtoupper($code))->value('id');
            [$o, $d] = [$ids($origin), $ids($destination)];
            $query->where(function (Builder $q) use ($o, $d, $filters) {
                $q->where(fn ($x) => $x->where('origin_airport_id', $o)->where('destination_airport_id', $d));
                if ($filters['round_trip'] ?? false) {
                    $q->orWhere(fn ($x) => $x->where('origin_airport_id', $d)->where('destination_airport_id', $o));
                }
            });
        }

        return $query;
    }
}
