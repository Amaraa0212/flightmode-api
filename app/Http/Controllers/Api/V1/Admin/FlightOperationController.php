<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\RoleScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\FlightResource;
use App\Models\Flight;
use App\Models\User;
use App\Services\Access\AccessScope;
use App\Services\Flight\FlightOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        return response()->json(['data' => FlightResource::collection($flights)->resolve()]);
    }

    public function changeStatus(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $flight);
        $data = $request->validate([
            'action' => ['required', Rule::in(['open_checkin', 'close_checkin', 'close_gate', 'depart', 'cancel'])],
            'close_online' => ['boolean'],
            'off_block_at' => ['required_if:action,depart', 'nullable', 'date'],
            'takeoff_at' => ['required_if:action,depart', 'nullable', 'date'],
            'estimated_arrival_at' => ['nullable', 'date'],
            'reason' => ['required_if:action,cancel', 'nullable', 'string', 'max:1000'],
        ]);

        $utc = fn (?string $time) => $time ? Carbon::parse($time)->utc()->toDateTimeString() : null;

        $flight = match ($data['action']) {
            'open_checkin' => $this->operations->openCheckin($flight),
            'close_checkin' => $this->operations->closeCheckin($flight, $request->boolean('close_online', true)),
            'close_gate' => $this->operations->closeGate($flight),
            'depart' => $this->operations->markDeparted($flight, $utc($data['off_block_at']), $utc($data['takeoff_at']),
                $utc($data['estimated_arrival_at'] ?? null), $data['reason'] ?? null),
            'cancel' => $this->operations->cancel($flight, $data['reason']),
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
