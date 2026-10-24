<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CabinClass;
use App\Http\Controllers\Controller;
use App\Http\Resources\FlightResource;
use App\Models\Flight;
use App\Models\InventoryTemplate;
use App\Models\Rbd;
use App\Services\Access\AccessScope;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Seat allocations: cabin seats and nested RBD limits per flight (legacy ameliaRES "Seat Allocations"). */
class InventoryController extends Controller
{
    public function __construct(
        private readonly AccessScope $access,
        private readonly InventoryService $inventory,
    ) {}

    /**
     * Flights with their inventory totals. Filters: from, to (dates, default: next 7 days), flight_number,
     * origin_airport_id, destination_airport_id, airline_id.
     */
    public function flights(Request $request): JsonResponse
    {
        $from = $request->date('from') ?? today();
        $to = $request->date('to') ?? today()->addDays(7);

        $flights = $this->access->flights(Flight::query(), $request->user())
            ->with(['airline', 'aircraft', 'origin', 'destination'])
            ->withSum('cabinInventory as inventory_seats', 'seats')
            ->withCount(['segments as sold_count' => fn ($q) => $q->holdingSpace()
                ->whereHas('passenger', fn ($p) => $p->whereNull('infant_of_passenger_id'))])
            ->whereBetween('scheduled_departure_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])
            ->when($request->filled('airline_id'), fn ($q) => $q->where('flights.airline_id', $request->integer('airline_id')))
            ->when($request->filled('flight_number'), fn ($q) => $q->where('flight_number', 'like', $request->string('flight_number')->upper().'%'))
            ->when($request->filled('origin_airport_id'), fn ($q) => $q->where('origin_airport_id', $request->integer('origin_airport_id')))
            ->when($request->filled('destination_airport_id'), fn ($q) => $q->where('destination_airport_id', $request->integer('destination_airport_id')))
            ->orderBy('scheduled_departure_at')
            ->limit(500)
            ->get();

        return response()->json(['data' => $flights->map(fn (Flight $f) => (new FlightResource($f))->resolve($request) + [
            'inventory' => [
                'is_controlled' => $f->inventory_seats !== null,
                'capacity' => (int) $f->aircraft?->total_seats,
                'allocated' => $f->inventory_seats !== null ? (int) $f->inventory_seats : null,
                'sold' => (int) $f->sold_count,
            ],
        ])->values()]);
    }

    public function show(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $flight);

        return response()->json(['data' => $this->payload($request, $flight)]);
    }

    public function saveCabins(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $flight);
        $data = $request->validate([
            'cabins' => ['required', 'array', 'min:1'],
            'cabins.*.cabin_class' => ['required', Rule::enum(CabinClass::class), 'distinct'],
            'cabins.*.seats' => ['required', 'integer', 'min:0', 'max:999'],
            'cabins.*.oversell' => ['nullable', 'integer', 'min:0', 'max:999'],
            'cabins.*.curtail' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        $this->inventory->saveCabins($flight, $data['cabins']);

        return $this->success('Cabin seats saved.', $this->payload($request, $flight));
    }

    public function saveRbds(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $flight);
        $data = $request->validate([
            'rbds' => ['required', 'array', 'min:1'],
            'rbds.*.rbd_id' => ['required', 'integer', 'distinct', Rule::exists('rbds', 'id')->where('airline_id', $flight->airline_id)],
            'rbds.*.seats' => ['required', 'integer', 'min:0', 'max:999'],
            'rbds.*.is_closed' => ['boolean'],
        ]);

        $this->inventory->saveRbds($flight, $data['rbds']);

        return $this->success('Booking classes saved.', $this->payload($request, $flight));
    }

    /** Quick change of one class (e.g. close / open it). */
    public function updateRbd(Request $request, Flight $flight, Rbd $rbd): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $flight);
        $this->access->ensureAirlineOwned($request->user(), $rbd);
        $current = $flight->rbdInventory()->where('rbd_id', $rbd->id)->first();
        $data = $request->validate([
            'seats' => [$current ? 'sometimes' : 'required', 'integer', 'min:0', 'max:999'],
            'is_closed' => ['sometimes', 'boolean'],
        ]);

        $this->inventory->saveRbds($flight, [[
            'rbd_id' => $rbd->id,
            'seats' => $data['seats'] ?? $current->seats,
            'is_closed' => $data['is_closed'] ?? (bool) $current?->is_closed,
        ]]);

        return $this->success("Class {$rbd->code} saved.", $this->payload($request, $flight));
    }

    public function destroyRbd(Request $request, Flight $flight, Rbd $rbd): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $flight);
        $this->inventory->deleteRbd($flight, $rbd);

        return $this->success("Class {$rbd->code} removed from the flight.", $this->payload($request, $flight));
    }

    public function initialize(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $flight);
        $data = $request->validate([
            'template_id' => ['nullable', 'integer', Rule::exists('inventory_templates', 'id')->where('airline_id', $flight->airline_id)],
            'overwrite' => ['boolean'],
        ]);

        $template = isset($data['template_id']) ? InventoryTemplate::query()->findOrFail($data['template_id']) : null;
        $this->inventory->initialize($flight, $template, $data['overwrite'] ?? false);

        return $this->success('Inventory created.', $this->payload($request, $flight));
    }

    public function reset(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $flight);
        $this->inventory->reset($flight);

        return $this->success('Inventory removed. The flight sells without class limits.', $this->payload($request, $flight));
    }

    public function rollForward(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $flight);
        $data = $request->validate([
            'date_to' => ['required', 'date', 'after_or_equal:'.$flight->scheduled_departure_at->toDateString()],
            'days_of_week' => ['nullable', 'array'],
            'days_of_week.*' => ['integer', 'between:1,7', 'distinct'],
            'overwrite' => ['boolean'],
        ]);

        $results = $this->inventory->rollForward($flight, $data);
        $applied = collect($results)->where('status', 'applied')->count();

        return $this->success("Copied to {$applied} of ".count($results).' flight(s).', $results);
    }

    public function applyTemplate(Request $request): JsonResponse
    {
        $template = InventoryTemplate::query()->findOrFail($request->integer('template_id'));
        $this->access->ensureAirlineOwned($request->user(), $template);
        $data = $request->validate([
            'flight_ids' => ['required', 'array', 'min:1', 'max:500'],
            'flight_ids.*' => ['integer', Rule::exists('flights', 'id')->where('airline_id', $template->airline_id)],
            'overwrite' => ['boolean'],
        ]);

        $results = $this->inventory->applyTemplate($template, $data['flight_ids'], $data['overwrite'] ?? false);
        $applied = collect($results)->where('status', 'applied')->count();

        return $this->success("Applied to {$applied} of ".count($results).' flight(s).', $results);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, Flight $flight): array
    {
        $flight->load(['airline', 'aircraft', 'origin', 'destination']);

        return ['flight' => (new FlightResource($flight))->resolve($request)] + $this->inventory->availability($flight);
    }
}
