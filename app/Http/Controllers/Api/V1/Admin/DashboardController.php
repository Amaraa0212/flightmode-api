<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\FlightStatus;
use App\Enums\SegmentStatus;
use App\Http\Controllers\Controller;
use App\Models\AgencyAllocation;
use App\Models\Flight;
use App\Models\PassengerSegment;
use App\Services\Access\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly AccessScope $access) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = $this->access->flights(Flight::query(), $user)->departingOn(today()->toDateString())->get(['id', 'status']);
        $upcoming = $this->access->flights(Flight::query(), $user)->whereBetween('scheduled_departure_at', [now(), now()->addDays(7)]);

        return response()->json(['data' => [
            'today' => [
                'flights' => $today->count(),
                'by_status' => collect(FlightStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $today->where('status', $s)->count()]),
                'passengers' => PassengerSegment::query()->whereIn('flight_id', $today->pluck('id'))->holdingSpace()->count(),
                'checked_in' => PassengerSegment::query()->whereIn('flight_id', $today->pluck('id'))->checkedIn()->count(),
            ],
            'next_7_days' => [
                'flights' => (clone $upcoming)->count(),
                'passengers' => PassengerSegment::query()->whereIn('flight_id', (clone $upcoming)->select('flights.id'))->holdingSpace()->count(),
                'waitlisted' => PassengerSegment::query()->whereIn('flight_id', (clone $upcoming)->select('flights.id'))
                    ->where('status', SegmentStatus::Waitlisted->value)->count(),
                'allocations' => $this->access->allocations(AgencyAllocation::query(), $user)
                    ->whereIn('outbound_flight_id', (clone $upcoming)->select('flights.id'))->count(),
            ],
        ]]);
    }
}
