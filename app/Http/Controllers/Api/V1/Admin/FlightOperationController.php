<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PermissionKey;
use App\Enums\RoleScope;
use App\Enums\SegmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\FlightResource;
use App\Models\Agency;
use App\Models\AgencyAllocation;
use App\Models\Flight;
use App\Models\User;
use App\Services\Access\AccessScope;
use App\Services\Flight\FlightOperationsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Legacy "flight manager": status changes, gate, ETD, gate agents, online check-in settings. */
class FlightOperationController extends Controller
{
    public function __construct(
        private readonly AccessScope $access,
        private readonly FlightOperationsService $operations,
    ) {}

    /** Operational board for a day: flights ordered by ETD / STD. */
    public function board(Request $request): JsonResponse
    {
        $date = ($request->date('date') ?? today())->toDateString();

        $flights = $this->access->flights(Flight::query(), $request->user())
            ->with(['aircraft', 'origin', 'destination', 'gateAgents'])
            ->withPassengerCounts()
            ->departingOn($date)
            ->get()
            ->sortBy(fn (Flight $f) => $f->departureAt())
            ->values();

        $sales = $this->agencySales($flights->pluck('id')->all());
        $rows = FlightResource::collection($flights)->resolve();
        foreach ($rows as $i => $row) {
            $rows[$i]['agency_sales'] = $sales[$row['id']] ?? [];
        }

        return response()->json(['data' => $rows]);
    }

    /**
     * Flight load month calendar (?month=YYYY-MM, default this month): per flight the local departure day,
     * seats, passengers holding a seat by type and free seats. Days are the origin airport's local days.
     */
    public function calendar(Request $request): JsonResponse
    {
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = Carbon::createFromFormat('Y-m-d', ($request->input('month') ?? now()->format('Y-m')).'-01')->startOfDay();
        // a day of margin on both sides: local days of far-away origins cross UTC midnight
        $flights = $this->access->flights(Flight::query(), $request->user())
            ->with(['aircraft:id,total_seats', 'origin:id,iata_code,timezone', 'destination:id,iata_code'])
            ->whereBetween('scheduled_departure_at', [$month->copy()->subDay(), $month->copy()->endOfMonth()->addDay()])
            ->orderBy('scheduled_departure_at')
            ->get();

        $counts = DB::table('passenger_segments')
            ->join('passengers', 'passengers.id', '=', 'passenger_segments.passenger_id')
            ->whereIn('passenger_segments.flight_id', $flights->pluck('id'))
            ->whereIn('passenger_segments.status', [SegmentStatus::Booked->value, SegmentStatus::CheckedIn->value,
                SegmentStatus::Boarded->value, SegmentStatus::Flown->value])
            ->whereNull('passenger_segments.deleted_at')
            ->groupBy('passenger_segments.flight_id', 'passengers.type')
            ->selectRaw('passenger_segments.flight_id, passengers.type, COUNT(*) as total')
            ->get()
            ->groupBy('flight_id');

        $rows = $flights->map(function (Flight $flight) use ($counts) {
            $local = $flight->departureAt()->copy()->timezone($flight->origin?->timezone ?? config('app.timezone'));
            $byType = ['ADT' => 0, 'CHD' => 0, 'INF' => 0];
            foreach ($counts->get($flight->id, collect()) as $row) {
                $byType[$row->type ?? 'ADT'] = ($byType[$row->type ?? 'ADT'] ?? 0) + (int) $row->total;
            }
            $booked = $byType['ADT'] + $byType['CHD'];
            $capacity = (int) $flight->aircraft?->total_seats;

            return [
                'id' => $flight->id,
                'flight_number' => $flight->flight_number,
                'route' => $flight->route(),
                'date' => $local->toDateString(),
                'time' => $local->format('H:i'),
                'status' => $flight->status->value,
                'service_type' => $flight->service_type?->value ?? 'scheduled',
                'capacity' => $capacity,
                'booked' => $byType + ['total' => $booked],
                'free' => max(0, $capacity - $booked),
            ];
        })->filter(fn (array $row) => str_starts_with($row['date'], $month->format('Y-m')))->values();

        return response()->json(['data' => $rows]);
    }

    /**
     * Seats sold per agency on each flight (quota seats and passengers holding a seat, infants left out) —
     * the flight load summary. Direct sales (no agency) are listed as agency null.
     *
     * @param  array<int>  $flightIds
     * @return array<int, list<array{agency: ?array{id: int, name: string}, seats: int, sold: int}>>
     */
    private function agencySales(array $flightIds): array
    {
        if ($flightIds === []) {
            return [];
        }

        $sold = DB::table('passenger_segments')
            ->join('passengers', 'passengers.id', '=', 'passenger_segments.passenger_id')
            ->join('bookings', 'bookings.id', '=', 'passengers.booking_id')
            ->whereIn('passenger_segments.flight_id', $flightIds)
            ->whereNotIn('passenger_segments.status', [SegmentStatus::Cancelled->value, SegmentStatus::Waitlisted->value])
            ->whereNull('passengers.infant_of_passenger_id')
            ->groupBy('passenger_segments.flight_id', 'bookings.agency_id')
            ->selectRaw('passenger_segments.flight_id, bookings.agency_id, COUNT(*) as sold')
            ->get();

        $quotas = AgencyAllocation::query()
            ->where(fn ($q) => $q->whereIn('outbound_flight_id', $flightIds)->orWhereIn('return_flight_id', $flightIds))
            ->get(['agency_id', 'outbound_flight_id', 'return_flight_id', 'seats']);

        $agencies = Agency::query()->whereIn('id', $sold->pluck('agency_id')->merge($quotas->pluck('agency_id'))->filter()->unique())
            ->pluck('name', 'id');

        $result = [];
        foreach ($flightIds as $flightId) {
            $seats = $quotas->filter(fn ($a) => in_array($flightId, [$a->outbound_flight_id, $a->return_flight_id], true))
                ->groupBy('agency_id')->map->sum('seats');
            $soldHere = $sold->where('flight_id', $flightId)->keyBy(fn ($r) => $r->agency_id ?? 0);
            $ids = $seats->keys()->merge($soldHere->keys())->unique();

            $result[$flightId] = $ids->map(fn ($id) => [
                'agency' => $id ? ['id' => (int) $id, 'name' => $agencies[$id] ?? '—'] : null,
                'seats' => (int) ($seats[$id] ?? 0),
                'sold' => (int) ($soldHere[$id]->sold ?? 0),
            ])->sortByDesc('sold')->values()->all();
        }

        return $result;
    }

    public function changeStatus(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $flight);
        $data = $request->validate([
            'action' => ['required', Rule::in(['open_checkin', 'close_checkin', 'close_gate', 'depart', 'cancel', 'reopen'])],
            'close_online' => ['boolean'],
            'off_block_at' => ['required_if:action,depart', 'nullable', 'date'],
            'takeoff_at' => ['required_if:action,depart', 'nullable', 'date'],
            'estimated_arrival_at' => ['nullable', 'date'],
            'reason' => ['required_if:action,cancel', 'nullable', 'string', 'max:1000'],
        ]);

        // closing is OCC's, cancelling / reopening the airline administrator's; opening check-in stays with flights.operate
        $permission = match ($data['action']) {
            'close_checkin', 'close_gate', 'depart' => PermissionKey::FlightsClose,
            'cancel', 'reopen' => PermissionKey::FlightsCancel,
            default => null,
        };
        if ($permission && ! $request->user()->can($permission->value)) {
            throw new AuthorizationException('You do not have permission for this.');
        }

        $utc = fn (?string $time) => $time ? Carbon::parse($time)->utc()->toDateTimeString() : null;

        $flight = match ($data['action']) {
            'open_checkin' => $this->operations->openCheckin($flight),
            'close_checkin' => $this->operations->closeCheckin($flight, $request->boolean('close_online', true)),
            'close_gate' => $this->operations->closeGate($flight),
            'depart' => $this->operations->markDeparted($flight, $utc($data['off_block_at']), $utc($data['takeoff_at']),
                $utc($data['estimated_arrival_at'] ?? null), $data['reason'] ?? null),
            'cancel' => $this->operations->cancel($flight, $data['reason']),
            'reopen' => $this->operations->reopen($flight),
        };

        return $this->success('Flight status changed.', new FlightResource($flight->load(['origin', 'destination'])));
    }

    public function setGate(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $flight);
        $data = $request->validate(['gate' => ['nullable', 'string', 'max:10']]);

        return $this->success('Gate saved.', new FlightResource($this->operations->setGate($flight, $data['gate'])));
    }

    public function setEstimatedDeparture(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $flight);
        $data = $request->validate(['estimated_departure_at' => ['nullable', 'date']]);
        $etd = isset($data['estimated_departure_at']) ? Carbon::parse($data['estimated_departure_at'])->utc()->toDateTimeString() : null;

        return $this->success('ETD saved.', new FlightResource($this->operations->setEstimatedDeparture($flight, $etd)->load(['origin', 'destination'])));
    }

    public function setGateAgents(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $flight);
        $data = $request->validate(['user_ids' => ['present', 'array'], 'user_ids.*' => ['integer', 'exists:users,id']]);

        $allowed = $this->assignableAgents($request->user(), $flight)->pluck('id')->all();
        if (array_diff($data['user_ids'], $allowed)) {
            throw ValidationException::withMessages(['user_ids' => 'Some users cannot be assigned to this flight.']);
        }

        $flight = $this->operations->setGateAgents($flight, $data['user_ids']);

        return $this->success('Gate agents saved.', $flight->gateAgents->map->only(['id', 'name']));
    }

    /** Users that may be put on a flight's gate: the airline's staff and the station's staff. */
    public function gateAgentCandidates(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $flight);

        return response()->json(['data' => $this->assignableAgents($request->user(), $flight)->map->only(['id', 'name', 'email'])->values()]);
    }

    public function setOnlineCheckin(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $flight);
        $data = $request->validate([
            'online_checkin_enabled' => ['required', 'boolean'],
            'online_checkin_opens_minutes' => ['nullable', 'integer', 'min:0'],
            'online_checkin_closes_minutes' => ['nullable', 'integer', 'min:0'],
            'seat_assignment_profile_id' => ['nullable', 'integer', Rule::exists('seat_assignment_profiles', 'id')->where('aircraft_id', $flight->aircraft_id)],
            'baggage_rule_ids' => ['sometimes', 'array'],
            'baggage_rule_ids.*' => ['integer', Rule::exists('baggage_rules', 'id')->where('airline_id', $flight->airline_id)],
            'seat_price_ids' => ['sometimes', 'array'],
            'seat_price_ids.*' => ['integer', Rule::exists('seat_prices', 'id')->where('airline_id', $flight->airline_id)],
        ]);

        $flight = $this->operations->setOnlineCheckin($flight, $data);

        return $this->success('Online check-in settings saved.', new FlightResource($flight->load(['origin', 'destination'])));
    }

    private function assignableAgents(User $actor, Flight $flight)
    {
        return User::query()->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->whereIn('scope', [RoleScope::Airline->value, RoleScope::Airport->value]))
            ->where(fn ($q) => $q->where('airline_id', $flight->airline_id)->orWhere('airport_id', $flight->origin_airport_id))
            ->orderBy('name')
            ->get();
    }
}
