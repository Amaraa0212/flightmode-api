<?php

namespace App\Http\Controllers\Api\V1\Dcs;

use App\Http\Controllers\Controller;
use App\Http\Resources\FlightResource;
use App\Models\AirlineSsr;
use App\Models\Flight;
use App\Services\Access\AccessScope;
use App\Services\Flight\FlightOperationsService;
use App\Services\Flight\FlightStatistics;
use App\Services\Seat\SeatMapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Check-in counter: flight list, opening / closing the counter, seat map, statistics. */
class FlightController extends Controller
{
    /** Relations the counter's flight bar reads (FlightResource airline / aircraft / route) */
    private const FLIGHT_RELATIONS = ['airline', 'aircraft', 'origin', 'destination'];

    public function __construct(
        private readonly AccessScope $access,
        private readonly FlightOperationsService $operations,
        private readonly FlightStatistics $statistics,
        private readonly SeatMapService $seatMap,
    ) {}

    /** Flights from yesterday on (station users: departing their airport). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $flights = $this->access->flights(Flight::query(), $request->user())
            ->with(['airline', 'aircraft', 'origin', 'destination'])
            ->withPassengerCounts()
            ->upcoming(24)
            ->when($request->filled('date'), fn ($q) => $q->departingOn($request->date('date')->toDateString()))
            ->orderBy('scheduled_departure_at')
            ->limit(200)
            ->get();

        return FlightResource::collection($flights);
    }

    public function show(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $flight);

        return response()->json(['data' => [
            'flight' => (new FlightResource($flight->load(['airline', 'aircraft', 'origin', 'destination', 'baggageRules'])))->resolve(),
            'statistics' => $this->statistics->summary($flight),
        ]]);
    }

    public function open(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $flight);

        return $this->success('Check-in opened.', new FlightResource($this->operations->openCheckin($flight)->load(self::FLIGHT_RELATIONS)));
    }

    public function close(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $flight);
        $data = $request->validate(['close_online' => ['boolean']]);

        return $this->success('Check-in closed.', new FlightResource($this->operations->closeCheckin($flight, $data['close_online'] ?? true)->load(self::FLIGHT_RELATIONS)));
    }

    public function seatMap(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $flight);

        return response()->json(['data' => $this->seatMap->forFlight($flight, withOccupants: true)]);
    }

    public function statistics(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $flight);

        return response()->json(['data' => $this->statistics->summary($flight)]);
    }

    public function ssrCodes(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $flight);

        return response()->json(['data' => AirlineSsr::query()->with('ssrCode')->where('airline_id', $flight->airline_id)->get()
            ->map(fn (AirlineSsr $s) => ['code' => $s->ssrCode->code, 'name' => $s->ssrCode->name, 'price' => $s->price])
            ->sortBy('code')->values()]);
    }
}
