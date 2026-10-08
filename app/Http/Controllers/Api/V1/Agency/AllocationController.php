<?php

namespace App\Http\Controllers\Api\V1\Agency;

use App\Http\Controllers\Api\V1\Admin\AllocationController as AdminAllocationController;
use App\Http\Controllers\Controller;
use App\Http\Resources\AllocationResource;
use App\Http\Resources\PassengerResource;
use App\Models\AgencyAllocation;
use App\Models\AirlineSsr;
use App\Services\Access\AccessScope;
use App\Services\Allocation\AllocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** The agency's own allocations (legacy agent "schedule list" / "schedule detail"). */
class AllocationController extends Controller
{
    public function __construct(
        private readonly AccessScope $access,
        private readonly AllocationService $allocations,
    ) {}

    /** ?status=active|past (active = departs more than 2 hours from now). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $past = $request->input('status') === 'past';

        $allocations = $this->access->allocations(AgencyAllocation::query(), $request->user())
            ->with(['airline', 'cabins', 'fares.fare', 'outboundFlight.origin', 'outboundFlight.destination', 'returnFlight.origin', 'returnFlight.destination'])
            ->join('flights', 'flights.id', '=', 'agency_allocations.outbound_flight_id')
            ->where('flights.scheduled_departure_at', $past ? '<' : '>=', now()->addHours(2))
            ->when($past, fn ($q) => $q->where('flights.scheduled_departure_at', '>=', now()->subMonths(3)))
            ->orderBy('flights.scheduled_departure_at', $past ? 'desc' : 'asc')
            ->select('agency_allocations.*')
            ->paginate($request->integer('per_page', 50));

        $allocations->getCollection()->transform(fn ($a) => (new AllocationResource($a))->withUsage($this->allocations->usage($a)));

        return AllocationResource::collection($allocations);
    }

    public function show(Request $request, AgencyAllocation $allocation): AllocationResource
    {
        $this->access->ensureAllocation($request->user(), $allocation);
        $allocation->load(['cabins', 'fares.fare', 'outboundFlight.origin', 'outboundFlight.destination', 'returnFlight.origin', 'returnFlight.destination']);

        return (new AllocationResource($allocation))->withUsage($this->allocations->usage($allocation));
    }

    public function passengers(Request $request, AgencyAllocation $allocation): AnonymousResourceCollection
    {
        $this->access->ensureAllocation($request->user(), $allocation);

        return PassengerResource::collection(AdminAllocationController::allocationPassengers($allocation));
    }

    /** SSR codes the airline of this allocation accepts. */
    public function ssrCodes(Request $request, AgencyAllocation $allocation): JsonResponse
    {
        $this->access->ensureAllocation($request->user(), $allocation);

        $ssrs = AirlineSsr::query()->with('ssrCode')->where('airline_id', $allocation->airline_id)->get()
            ->map(fn (AirlineSsr $s) => ['code' => $s->ssrCode->code, 'name' => $s->ssrCode->name, 'category' => $s->ssrCode->category,
                'price' => $s->price, 'currency' => $s->currency])
            ->sortBy('code')->values();

        return response()->json(['data' => $ssrs]);
    }

    /** Other allocations of the agency on the reverse route after this flight (to sell a return). */
    public function returnOptions(Request $request, AgencyAllocation $allocation): AnonymousResourceCollection
    {
        $this->access->ensureAllocation($request->user(), $allocation);
        $outbound = $allocation->outboundFlight;

        $options = AgencyAllocation::query()->with(['outboundFlight.origin', 'outboundFlight.destination', 'fares.fare', 'cabins'])
            ->where('agency_id', $allocation->agency_id)
            ->whereKeyNot($allocation->id)
            ->whereNull('return_flight_id')
            ->whereHas('outboundFlight', fn ($q) => $q
                ->where('origin_airport_id', $outbound->destination_airport_id)
                ->where('destination_airport_id', $outbound->origin_airport_id)
                ->where('scheduled_departure_at', '>', $outbound->scheduled_arrival_at))
            ->get();

        return AllocationResource::collection($options);
    }
}
