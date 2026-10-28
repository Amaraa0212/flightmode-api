<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CabinClass;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AllocationRequest;
use App\Http\Resources\AllocationResource;
use App\Http\Resources\PassengerResource;
use App\Models\AgencyAllocation;
use App\Models\Flight;
use App\Models\Passenger;
use App\Services\Access\AccessScope;
use App\Services\Allocation\AllocationBoard;
use App\Services\Allocation\AllocationService;
use App\Services\Integrations\Chinggis\ChinggisSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Seats the airline gives to agencies (legacy "agent schedules"). */
class AllocationController extends Controller
{
    public function __construct(
        private readonly AccessScope $access,
        private readonly AllocationService $allocations,
        private readonly AllocationBoard $board,
        private readonly ChinggisSync $chinggis,
    ) {}

    /** By flight: flights with allocations between ?from and ?to (default the next 30 days), who has how much. */
    public function board(Request $request): JsonResponse
    {
        $from = $request->date('from')?->startOfDay() ?? now()->subHours(2);
        $to = $request->date('to')?->endOfDay() ?? now()->addDays(30)->endOfDay();

        return response()->json(['data' => $this->board->board($request->user(), $from, $to)]);
    }

    public function flightOverview(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $flight);

        return response()->json(['data' => $this->board->overview($flight)]);
    }

    /** Hand out a flight's quota to several agencies at once: { rows: [{allocation_id?, agency_id, mode, seats, cabins?, deadline?}], remove: [ids] } */
    public function distribute(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $flight);
        $data = $request->validate([
            'deadline' => ['nullable', 'date'],
            'rows' => ['present', 'array'],
            'rows.*.allocation_id' => ['nullable', 'integer'],
            'rows.*.agency_id' => ['required', 'integer', 'exists:agencies,id', 'distinct'],
            'rows.*.mode' => ['required', Rule::in([AgencyAllocation::FIXED, AgencyAllocation::POOL])],
            'rows.*.seats' => ['required', 'integer', 'min:0'],
            'rows.*.cabins' => ['nullable', 'array'],
            'rows.*.cabins.*' => ['integer', 'min:0'],
            'rows.*.deadline' => ['nullable', 'date'],
            'remove' => ['array'],
            'remove.*' => ['integer'],
        ]);

        $this->board->distribute($flight, $data['rows'], $data['remove'] ?? [], $data['deadline'] ?? null);

        return $this->success('Quota saved.', $this->board->overview($flight->refresh()));
    }

    /** Push the allocation to the Chinggis agent API now (agencies with chinggis_sync). */
    public function pushToChinggis(Request $request, AgencyAllocation $allocation): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $allocation);
        if (! $this->chinggis->isSyncedAgency($allocation->agency_id)) {
            throw BusinessRuleException::make('agency_not_synced', 'This agency is not sent to Chinggis: turn on "Push to Chinggis" on the agency.');
        }
        if (! $this->chinggis->pushAllocation($allocation)) {
            throw BusinessRuleException::make('chinggis_push_failed', (string) $allocation->refresh()->chinggis_error);
        }

        return $this->success('Sent to Chinggis.', (new AllocationResource($allocation->refresh()->load('agency')))->withUsage($this->allocations->usage($allocation)));
    }

    /** Move unsold seats between two fixed allocations of a flight: { from_allocation_id, to_allocation_id, cabin_class, seats } */
    public function move(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_allocation_id' => ['required', 'integer', 'exists:agency_allocations,id'],
            'to_allocation_id' => ['required', 'integer', 'exists:agency_allocations,id', 'different:from_allocation_id'],
            'cabin_class' => ['required', Rule::enum(CabinClass::class)],
            'seats' => ['required', 'integer', 'min:1'],
        ]);
        $from = AgencyAllocation::query()->findOrFail($data['from_allocation_id']);
        $to = AgencyAllocation::query()->findOrFail($data['to_allocation_id']);
        foreach ([$from, $to] as $allocation) {
            $this->access->ensureAirlineOwned($request->user(), $allocation);
        }

        $this->board->move($from, $to, CabinClass::from($data['cabin_class']), $data['seats']);

        return $this->success("{$data['seats']} seat(s) moved.", $this->board->overview($from->outboundFlight));
    }

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
