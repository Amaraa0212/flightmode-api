<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AircraftRequest;
use App\Http\Resources\AircraftResource;
use App\Models\Aircraft;
use App\Services\Access\AccessScope;
use App\Services\Integrations\Chinggis\ChinggisSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AircraftController extends Controller
{
    public function __construct(
        private readonly AccessScope $access,
        private readonly ChinggisSync $chinggis,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return AircraftResource::collection(
            $this->access->airlineOwned(Aircraft::query(), $request->user())
                ->with(['cabins', 'airline'])->withCount('seats')
                ->when($request->filled('airline_id'), fn ($q) => $q->where('airline_id', $request->integer('airline_id')))
                ->orderBy('registration')->get()
        );
    }

    public function store(AircraftRequest $request): JsonResponse
    {
        $airlineId = $this->access->airlineIdForWrite($request->user(), $request->integer('airline_id') ?: null);

        $aircraft = DB::transaction(function () use ($request, $airlineId) {
            $aircraft = Aircraft::create($request->safe()->except(['cabins', 'airline_id']) + ['airline_id' => $airlineId]);
            $this->syncCabins($aircraft, $request->validated('cabins'));

            return $aircraft;
        });

        $this->chinggis->pushAircraft($aircraft);

        return $this->created('Aircraft created.', new AircraftResource($aircraft->load('cabins')));
    }

    public function show(Request $request, Aircraft $aircraft): AircraftResource
    {
        $this->access->ensureAirlineOwned($request->user(), $aircraft);

        return new AircraftResource($aircraft->load(['cabins', 'loadZones', 'airline'])->loadCount('seats'));
    }

    public function update(AircraftRequest $request, Aircraft $aircraft): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $aircraft);

        DB::transaction(function () use ($request, $aircraft) {
            $aircraft->update($request->safe()->except(['cabins', 'airline_id']));
            if ($request->has('cabins')) {
                $this->syncCabins($aircraft, $request->validated('cabins'));
            }
        });

        $this->chinggis->pushAircraft($aircraft);

        return $this->success('Aircraft updated.', new AircraftResource($aircraft->load('cabins')));
    }

    public function destroy(Request $request, Aircraft $aircraft): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $aircraft);
        if ($aircraft->flights()->where('scheduled_departure_at', '>=', now())->exists()) {
            throw ValidationException::withMessages(['aircraft' => 'The aircraft has upcoming flights.']);
        }

        $aircraft->delete();

        return $this->deleted('Aircraft deleted.');
    }

    /** @param array<string, int> $cabins */
    private function syncCabins(Aircraft $aircraft, array $cabins): void
    {
        $aircraft->cabins()->delete();
        foreach ($cabins as $cabin => $seats) {
            if ($seats > 0) {
                $aircraft->cabins()->create(['cabin_class' => $cabin, 'seat_count' => $seats]);
            }
        }
    }
}
