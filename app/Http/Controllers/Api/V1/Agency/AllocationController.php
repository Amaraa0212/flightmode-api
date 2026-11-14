<?php

namespace App\Http\Controllers\Api\V1\Agency;

use App\Enums\FlightServiceType;
use App\Http\Controllers\Api\V1\Admin\AllocationController as AdminAllocationController;
use App\Http\Controllers\Controller;
use App\Http\Resources\AllocationResource;
use App\Http\Resources\PassengerResource;
use App\Models\AgencyAllocation;
use App\Models\AirlineSsr;
use App\Services\Access\AccessScope;
use App\Services\Agency\AgencySeatService;
use App\Services\Allocation\AllocationService;
use App\Services\Allocation\ScheduleChanges;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** The agency's own allocations (legacy agent "schedule list" / "schedule detail"). */
class AllocationController extends Controller
{
    public function __construct(
        private readonly AccessScope $access,
        private readonly AllocationService $allocations,
        private readonly ScheduleChanges $scheduleChanges,
    ) {}

    /** ?status=active|past (active = departs more than 2 hours from now). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $past = $request->input('status') === 'past';

        $allocations = $this->access->allocations(AgencyAllocation::query(), $request->user())
            ->with(['airline', 'cabins', 'fares.fare', 'outboundFlight.origin', 'outboundFlight.destination', 'returnFlight.origin', 'returnFlight.destination'])
            ->join('flights', 'flights.id', '=', 'agency_allocations.outbound_flight_id')
            // a raw join sees deleted flights too: their allocations have no flight to show
            ->whereNull('flights.deleted_at')
            ->where('flights.scheduled_departure_at', $past ? '<' : '>=', now()->addHours(2))
            ->when($past, fn ($q) => $q->where('flights.scheduled_departure_at', '>=', now()->subMonths(3)))
            ->orderBy('flights.scheduled_departure_at', $past ? 'desc' : 'asc')
            ->select('agency_allocations.*')
            ->paginate($request->integer('per_page', 50));

        $changes = $this->scheduleChanges->forMany($allocations->getCollection());
        $allocations->getCollection()->transform(fn ($a) => (new AllocationResource($a))->withUsage($this->allocations->usage($a))
            ->withScheduleChanges($changes[$a->id] ?? null));

        return AllocationResource::collection($allocations);
    }

    public function show(Request $request, AgencyAllocation $allocation): AllocationResource
    {
        $this->access->ensureAllocation($request->user(), $allocation);
        $allocation->load(['cabins', 'fares.fare', 'outboundFlight.origin', 'outboundFlight.destination', 'returnFlight.origin', 'returnFlight.destination']);

        return (new AllocationResource($allocation))->withUsage($this->allocations->usage($allocation))
            ->withScheduleChanges($this->scheduleChanges->forMany(collect([$allocation]))[$allocation->id] ?? null);
    }

    /** Seat map of a charter flight of the allocation (?flight_id = outbound or return) with the agency's passengers. */
    public function seatMap(Request $request, AgencyAllocation $allocation, AgencySeatService $seats): JsonResponse
    {
        $this->ensureSeatRight($request, $allocation);
        $flight = $seats->flight($allocation, $request->integer('flight_id') ?: null);

        return response()->json(['data' => $seats->seatMap($allocation, $flight)]);
    }

    /** Reserve seats: { flight_id?, seats: [{segment_id, seat_number|null}] } — no check-in. */
    public function saveSeats(Request $request, AgencyAllocation $allocation, AgencySeatService $seats): JsonResponse
    {
        $this->ensureSeatRight($request, $allocation);
        $data = $request->validate([
            'flight_id' => ['nullable', 'integer'],
            'seats' => ['required', 'array', 'min:1'],
            'seats.*.segment_id' => ['required', 'integer', 'distinct'],
            'seats.*.seat_number' => ['nullable', 'string', 'max:5'],
        ]);
        $flight = $seats->flight($allocation, $data['flight_id'] ?? null);
        $changed = $seats->assign($allocation, $flight, $data['seats']);

        return $this->success("{$changed} seat(s) saved.", $seats->seatMap($allocation, $flight));
    }

    /** Seats for every passenger of the agency on the flight without one. */
    public function autoSeats(Request $request, AgencyAllocation $allocation, AgencySeatService $seats): JsonResponse
    {
        $this->ensureSeatRight($request, $allocation);
        $flight = $seats->flight($allocation, $request->integer('flight_id') ?: null);
        $changed = $seats->autoAssign($allocation, $flight);

        return $this->success("{$changed} passenger(s) seated.", $seats->seatMap($allocation, $flight));
    }

    private function ensureSeatRight(Request $request, AgencyAllocation $allocation): void
    {
        $this->access->ensureAllocation($request->user(), $allocation);
        $this->access->ensureAgencyRight($request->user(), $allocation, 'assign_seats');
    }

    /** The agency has seen the schedule changes of this allocation's flights (the badge goes away). */
    public function scheduleChangesSeen(Request $request, AgencyAllocation $allocation): JsonResponse
    {
        $this->access->ensureAllocation($request->user(), $allocation);
        $allocation->update(['schedule_change_seen_at' => now()]);

        return response()->json(['message' => 'Schedule changes acknowledged.']);
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

    /**
     * Allocations of the agency to sell a return on, after this flight arrives: the reverse route on the same airline;
     * an agency allowed open jaw on a charter also gets every charter landing where this one departs, on any airline
     * (that trip is two linked bookings, priced at nothing).
     */
    public function returnOptions(Request $request, AgencyAllocation $allocation): AnonymousResourceCollection
    {
        $this->access->ensureAllocation($request->user(), $allocation);
        $allocation->loadMissing(['agency', 'outboundFlight']);
        $outbound = $allocation->outboundFlight;
        $openJaw = (bool) $allocation->agency?->open_jaw && $outbound->isCharter() && ! $allocation->return_flight_id;

        $options = AgencyAllocation::query()->with(['airline', 'outboundFlight.origin', 'outboundFlight.destination', 'fares.fare', 'cabins'])
            ->where('agency_id', $allocation->agency_id)
            ->whereKeyNot($allocation->id)
            ->whereNull('return_flight_id')
            ->whereHas('outboundFlight', fn ($q) => $q
                ->where('destination_airport_id', $outbound->origin_airport_id)
                ->where('scheduled_departure_at', '>', $outbound->scheduled_arrival_at)
                ->when($openJaw, fn ($c) => $c->where('service_type', FlightServiceType::Charter->value))
                ->when(! $openJaw, fn ($c) => $c->where('origin_airport_id', $outbound->destination_airport_id)))
            ->when(! $openJaw, fn ($q) => $q->where('airline_id', $allocation->airline_id))
            ->get()
            ->sortBy(fn (AgencyAllocation $a) => $a->outboundFlight->scheduled_departure_at)->values();

        return AllocationResource::collection($options);
    }
}
