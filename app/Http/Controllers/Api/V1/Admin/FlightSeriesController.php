<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FlightSeriesRequest;
use App\Http\Resources\FlightSeriesResource;
use App\Models\FlightSeries;
use App\Services\Access\AccessScope;
use App\Services\Flight\FlightSeriesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Recurring schedules that generate flights (legacy "scheduled flights"). */
class FlightSeriesController extends Controller
{
    public function __construct(
        private readonly AccessScope $access,
        private readonly FlightSeriesService $series,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return FlightSeriesResource::collection(
            $this->access->airlineOwned(FlightSeries::query(), $request->user())
                ->with(['origin', 'destination'])->withCount('flights')
                ->when($request->boolean('current'), fn ($q) => $q->whereDate('period_end', '>=', today()))
                ->orderByDesc('period_start')
                ->paginate($request->integer('per_page', 50))
        );
    }

    public function store(FlightSeriesRequest $request): JsonResponse
    {
        $airlineId = $this->access->airlineIdForWrite($request->user(), $request->integer('airline_id') ?: null);
        $result = $this->series->save($request->seriesData($airlineId));

        return $this->created($this->summary($result), new FlightSeriesResource($result['series']->loadCount('flights')));
    }

    public function show(Request $request, FlightSeries $flightSeries): FlightSeriesResource
    {
        $this->access->ensureAirlineOwned($request->user(), $flightSeries);

        return new FlightSeriesResource($flightSeries->load(['origin', 'destination'])->loadCount('flights'));
    }

    public function update(FlightSeriesRequest $request, FlightSeries $flightSeries): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $flightSeries);
        $result = $this->series->save($request->seriesData($flightSeries->airline_id), $flightSeries);

        return $this->success($this->summary($result), new FlightSeriesResource($result['series']->loadCount('flights')));
    }

    public function destroy(Request $request, FlightSeries $flightSeries): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $flightSeries);
        $result = $this->series->delete($flightSeries);

        $message = "Series deleted, {$result['removed']} flight(s) removed.";
        if ($result['kept']) {
            $message .= ' Kept (have passengers): '.implode(', ', $result['kept']).'.';
        }

        return $this->deleted($message);
    }

    /** @param array{created: int, updated: int, removed: int, kept: array<string>} $result */
    private function summary(array $result): string
    {
        $text = "Flights: {$result['created']} created, {$result['updated']} updated, {$result['removed']} removed.";

        return $result['kept'] ? $text.' Not removed (have passengers or started): '.implode(', ', $result['kept']).'.' : $text;
    }
}
