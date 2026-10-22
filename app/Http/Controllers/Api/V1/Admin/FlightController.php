<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ActivityChannel;
use App\Enums\FlightStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FlightRequest;
use App\Http\Resources\FlightResource;
use App\Http\Resources\SegmentResource;
use App\Models\Flight;
use App\Services\Access\AccessScope;
use App\Services\ActivityLogger;
use App\Services\Allocation\AllocationService;
use App\Services\Flight\FlightOperationsService;
use App\Services\Flight\FlightStatistics;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FlightController extends Controller
{
    public function __construct(
        private readonly AccessScope $access,
        private readonly FlightOperationsService $operations,
        private readonly FlightStatistics $statistics,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * Filters: from, to (dates, default: this month), status, airline_id, origin_airport_id, destination_airport_id, flight_number,
     * search (flight number, origin / destination IATA, aircraft registration), source (single | series),
     * service_type (scheduled | charter),
     * open (not departed / cancelled), with_products (baggage rules, seat prices, zone profile, blocked seats).
     */
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
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->string('search')->trim()->upper().'%';
                $q->where(fn ($x) => $x->where('flight_number', 'like', $term)
                    ->orWhereHas('origin', fn ($a) => $a->where('iata_code', 'like', $term))
                    ->orWhereHas('destination', fn ($a) => $a->where('iata_code', 'like', $term))
                    ->orWhereHas('aircraft', fn ($a) => $a->where('registration', 'like', $term)));
            })
            ->when($request->boolean('open'), fn ($q) => $q->whereNotIn('status', [FlightStatus::Departed, FlightStatus::Cancelled]))
            ->when($request->boolean('with_products'), fn ($q) => $q->with(['baggageRules:id,name', 'seatAssignmentProfile:id,name'])
                ->withCount(['seatPrices', 'seatBlocks']))
            ->when($request->filled('service_type'), fn ($q) => $q->where('service_type', $request->string('service_type')))
            ->when($request->input('source') === 'single', fn ($q) => $q->whereNull('flight_series_id'))
            ->when($request->input('source') === 'series', fn ($q) => $q->whereNotNull('flight_series_id'))
            ->orderBy('scheduled_departure_at')
            ->paginate($request->integer('per_page', 100));

        return FlightResource::collection($flights);
    }

    /**
     * Shop items of one flight: its baggage rules, seat prices and online seat profile
     * (a template applies the same to many flights at once).
     */
    public function updateProducts(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $flight);
        $data = $request->validate([
            'baggage_rule_ids' => ['present', 'array'],
            'baggage_rule_ids.*' => ['integer', Rule::exists('baggage_rules', 'id')->where('airline_id', $flight->airline_id)],
            'seat_price_ids' => ['present', 'array'],
            'seat_price_ids.*' => ['integer', Rule::exists('seat_prices', 'id')->where('airline_id', $flight->airline_id)],
            'seat_assignment_profile_id' => ['nullable', 'integer', Rule::exists('seat_assignment_profiles', 'id')->where('aircraft_id', $flight->aircraft_id)],
        ]);

        DB::transaction(function () use ($flight, $data) {
            $flight->baggageRules()->sync($data['baggage_rule_ids']);
            $flight->seatPrices()->sync($data['seat_price_ids']);
            $flight->update(['seat_assignment_profile_id' => $data['seat_assignment_profile_id'] ?? null]);
        });
        $this->logger->log(ActivityChannel::Admin, 'flight.products_updated',
            count($data['baggage_rule_ids']).' baggage rule(s), '.count($data['seat_price_ids']).' seat price(s)', $flight, $flight);

        return $this->success('Shop items saved.', new FlightResource($flight->load(['baggageRules', 'seatPrices', 'seatAssignmentProfile'])->loadCount(['seatPrices', 'seatBlocks'])));
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
