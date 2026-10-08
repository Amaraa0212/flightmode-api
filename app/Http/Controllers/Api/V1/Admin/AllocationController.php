<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AllocationRequest;
use App\Http\Resources\AllocationResource;
use App\Http\Resources\PassengerResource;
use App\Models\AgencyAllocation;
use App\Models\Flight;
use App\Models\Passenger;
use App\Services\Access\AccessScope;
use App\Services\Allocation\AllocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Seats the airline gives to agencies (legacy "agent schedules"). */
class AllocationController extends Controller
{
    public function __construct(
        private readonly AccessScope $access,
        private readonly AllocationService $allocations,
    ) {}

    /** Filters: agency_id, flight_id, active (departure in the future, default true). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $allocations = $this->access->allocations(AgencyAllocation::query(), $request->user())
            ->with(['agency', 'cabins', 'outboundFlight.origin', 'outboundFlight.destination', 'returnFlight.origin', 'returnFlight.destination'])
            ->when($request->filled('agency_id'), fn ($q) => $q->where('agency_id', $request->integer('agency_id')))
            ->when($request->filled('flight_id'), fn ($q) => $q->where(fn ($x) => $x
                ->where('outbound_flight_id', $request->integer('flight_id'))->orWhere('return_flight_id', $request->integer('flight_id'))))
            ->when($request->boolean('active', true), fn ($q) => $q->whereHas('outboundFlight', fn ($f) => $f->where('scheduled_departure_at', '>=', now()->subHours(2))))
            ->join('flights', 'flights.id', '=', 'agency_allocations.outbound_flight_id')
            ->orderBy('flights.scheduled_departure_at')
            ->select('agency_allocations.*')
            ->paginate($request->integer('per_page', 50));

        $allocations->getCollection()->transform(fn ($a) => (new AllocationResource($a))->withUsage($this->allocations->usage($a)));

        return AllocationResource::collection($allocations);
    }

    public function store(AllocationRequest $request): JsonResponse
    {
        $flight = Flight::query()->findOrFail($request->integer('outbound_flight_id'));
        $this->access->ensureAirlineOwned($request->user(), $flight);

        $allocation = $this->allocations->save($request->validated() + ['airline_id' => $flight->airline_id]);

        return $this->created('Allocation created.', (new AllocationResource($allocation))->withUsage($this->allocations->usage($allocation)));
    }

    public function show(Request $request, AgencyAllocation $allocation): AllocationResource
    {
        $this->access->ensureAllocation($request->user(), $allocation);
        $allocation->load(['agency', 'cabins', 'fares.fare', 'outboundFlight.origin', 'outboundFlight.destination', 'returnFlight.origin', 'returnFlight.destination']);

        return (new AllocationResource($allocation))->withUsage($this->allocations->usage($allocation));
    }

    public function update(AllocationRequest $request, AgencyAllocation $allocation): JsonResponse
    {
        $this->access->ensureAllocation($request->user(), $allocation);
        $this->access->ensureAirlineOwned($request->user(), $allocation);

        $allocation = $this->allocations->save($request->validated() + ['airline_id' => $allocation->airline_id], $allocation);

        return $this->success('Allocation updated.', (new AllocationResource($allocation))->withUsage($this->allocations->usage($allocation)));
    }

    public function destroy(Request $request, AgencyAllocation $allocation): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $allocation);
        $this->allocations->delete($allocation);

        return $this->deleted('Allocation deleted.');
    }

    public function passengers(Request $request, AgencyAllocation $allocation): AnonymousResourceCollection
    {
        $this->access->ensureAllocation($request->user(), $allocation);

        return PassengerResource::collection($this->allocationPassengers($allocation));
    }

    /** Adults of the allocation with their infant and segments, cancelled last. */
    public static function allocationPassengers(AgencyAllocation $allocation)
    {
        return Passenger::query()
            ->with(['booking', 'infant.segments', 'segments.ssrCodes', 'segments.flight'])
            ->whereNull('infant_of_passenger_id')
            ->whereHas('booking', fn ($q) => $q->where('agency_allocation_id', $allocation->id))
            ->orderBy('last_name')->orderBy('first_name')
            ->get();
    }
}
