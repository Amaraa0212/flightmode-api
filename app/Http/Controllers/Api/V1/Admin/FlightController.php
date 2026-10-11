<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\FlightStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FlightRequest;
use App\Http\Resources\FlightResource;
use App\Http\Resources\SegmentResource;
use App\Models\Flight;
use App\Services\Access\AccessScope;
use App\Services\Allocation\AllocationService;
use App\Services\Flight\FlightOperationsService;
use App\Services\Flight\FlightStatistics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class FlightController extends Controller
{
    public function __construct(
        private readonly AccessScope $access,
        private readonly FlightOperationsService $operations,
        private readonly FlightStatistics $statistics,
    ) {}

    /** Filters: from, to (dates, default: this month), status, airline_id, origin_airport_id, destination_airport_id, flight_number. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? now()->endOfMonth();

        $flights = $this->access->flights(Flight::query(), $request->user())
            ->with(['airline', 'aircraft', 'origin', 'destination'])
            ->withCount(['segments' => fn ($q) => $q->holdingSpace()])
            ->whereBetween('scheduled_departure_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('airline_id'), fn ($q) => $q->where('flights.airline_id', $request->integer('airline_id')))
            ->when($request->filled('origin_airport_id'), fn ($q) => $q->where('origin_airport_id', $request->integer('origin_airport_id')))
            ->when($request->filled('destination_airport_id'), fn ($q) => $q->where('destination_airport_id', $request->integer('destination_airport_id')))
            ->when($request->filled('flight_number'), fn ($q) => $q->where('flight_number', 'like', $request->string('flight_number')->upper().'%'))
            ->orderBy('scheduled_departure_at')
            ->paginate($request->integer('per_page', 100));

        return FlightResource::collection($flights);
    }

    public function store(FlightRequest $request): JsonResponse
    {
        $airlineId = $this->access->airlineIdForWrite($request->user(), $request->integer('airline_id') ?: null);
        $flight = Flight::create($request->flightData() + [
            'airline_id' => $airlineId,
            'status' => FlightStatus::Scheduled,
            'created_by' => $request->user()->id,
        ]);

        return $this->created('Flight created.', new FlightResource($flight->load(['airline', 'aircraft', 'origin', 'destination'])));
    }

    public function show(Request $request, Flight $flight): FlightResource
    {
        $this->access->ensureFlight($request->user(), $flight);

        return new FlightResource($flight->load(['airline', 'aircraft', 'origin', 'destination', 'gateAgents', 'baggageRules', 'seatPrices']));
    }

    public function update(FlightRequest $request, Flight $flight): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $flight);
        $this->operations->update($flight, $request->flightData());

        return $this->success('Flight updated.', new FlightResource($flight->load(['airline', 'aircraft', 'origin', 'destination'])));
    }

    public function destroy(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $flight);
        $this->operations->delete($flight);

        return $this->deleted('Flight deleted.');
    }

    public function statistics(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $flight);

        return response()->json(['data' => $this->statistics->summary($flight) + [
            'allocations' => $this->statistics->allocations($flight),
        ]]);
    }

    /** Seats still free to hand out as agency allocations. */
    public function allocationCapacity(Request $request, Flight $flight, AllocationService $allocations): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $flight);

        return response()->json(['data' => [
            'total_seats' => (int) $flight->aircraft?->total_seats,
            'free_for_allocation' => $allocations->seatsLeftForAllocations($flight),
        ]]);
    }

    /** Passengers of the flight (all statuses), for the back office passenger list. */
    public function passengers(Request $request, Flight $flight): AnonymousResourceCollection
    {
        $this->access->ensureFlight($request->user(), $flight);

        $segments = $flight->segments()
            ->with(['passenger.booking.agency', 'ssrCodes', 'checkedBaggage'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), fn ($q) => $q->whereHas('passenger', fn ($p) => $p
                ->where('last_name', 'like', '%'.$request->string('search').'%')
                ->orWhere('first_name', 'like', '%'.$request->string('search').'%')
                ->orWhere('document_number', $request->string('search'))))
            ->get()
            ->sortBy(fn ($s) => $s->passenger->last_name.' '.$s->passenger->first_name)
            ->values();

        return SegmentResource::collection($segments);
    }
}
