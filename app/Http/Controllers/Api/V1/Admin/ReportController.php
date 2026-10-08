<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\SegmentResource;
use App\Services\Reports\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    /** Legacy "traffic summary" / "station report". ?from&to&origin&destination&round_trip&departed_only */
    public function traffic(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reports->traffic($request->user(), $this->filters($request) + [
            'departed_only' => $request->boolean('departed_only'),
        ])]);
    }

    public function fareSales(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reports->fareSales($request->user(), $this->filters($request))]);
    }

    public function onlineCheckin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000'],
            'origin' => ['nullable', 'string', 'size:3'],
            'destination' => ['nullable', 'string', 'size:3'],
        ]);

        return response()->json(['data' => $this->reports->onlineCheckinByMonth(
            $request->user(), (int) ($data['year'] ?? now()->year), $data['origin'] ?? null, $data['destination'] ?? null,
        )]);
    }

    public function passengerSearch(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'min:2'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $data['from'] ??= now()->subYear()->toDateString();
        $data['to'] ??= now()->addYear()->toDateString();

        return SegmentResource::collection($this->reports->passengerSearch($request->user(), $data));
    }

    public function routes(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reports->routes($request->user())]);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'origin' => ['nullable', 'string', 'size:3'],
            'destination' => ['nullable', 'string', 'size:3'],
            'round_trip' => ['boolean'],
        ]);
    }
}
