<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\FlightStatus;
use App\Enums\SegmentStatus;
use App\Http\Controllers\Controller;
use App\Models\AgencyAllocation;
use App\Models\Booking;
use App\Models\Flight;
use App\Models\PassengerSegment;
use App\Models\User;
use App\Services\Access\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
            'summary' => $this->summary($user, $today),
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

    /**
     * Back office dashboard cards and charts — every figure within the user's airline / station.
     *
     * @param  Collection<int, Flight>  $today
     * @return array<string, mixed>
     */
    private function summary(User $user, Collection $today): array
    {
        $todayIds = $today->pluck('id');
        $capacity = (int) Flight::query()->whereIn('flights.id', $todayIds)
            ->join('aircraft', 'aircraft.id', '=', 'flights.aircraft_id')->sum('aircraft.total_seats');
        $passengersToday = PassengerSegment::query()->whereIn('flight_id', $todayIds)->holdingSpace()->count();

        // bookings made per day over the last 14 days (and their passengers)
        $from = today()->subDays(13);
        $bookings = $this->access->bookings(Booking::query(), $user)
            ->where('bookings.created_at', '>=', $from)->withCount('passengers')->get(['bookings.id', 'bookings.created_at']);
        $daily = collect(range(0, 13))->map(function (int $i) use ($from, $bookings) {
            $day = $from->copy()->addDays($i);
            $onDay = $bookings->filter(fn (Booking $b) => $b->created_at->isSameDay($day));

            return ['date' => $day->format('m-d'), 'bookings' => $onDay->count(), 'passengers' => (int) $onDay->sum('passengers_count')];
        });

        $weekBookingIds = $bookings->filter(fn (Booking $b) => $b->created_at->gte(today()->subDays(6)))->pluck('id');
        $revenue = (float) PassengerSegment::query()
            ->whereHas('passenger', fn ($q) => $q->whereIn('booking_id', $weekBookingIds))
            ->whereNot('status', SegmentStatus::Cancelled->value)
            ->sum(DB::raw('fare_amount + tax_amount'));

        // busiest routes of the last 30 days by passengers holding a seat
        $routes = $this->access->flights(Flight::query(), $user)
            ->with(['origin:id,iata_code', 'destination:id,iata_code'])
            ->where('scheduled_departure_at', '>=', today()->subDays(30))
            ->withCount(['segments' => fn ($q) => $q->holdingSpace()])
            ->get(['flights.id', 'origin_airport_id', 'destination_airport_id'])
            ->groupBy(fn (Flight $f) => $f->origin->iata_code.'-'.$f->destination->iata_code)
            ->map(fn (Collection $flights, string $route) => ['route' => $route, 'passengers' => (int) $flights->sum('segments_count')])
            ->sortByDesc('passengers')->take(6)->values();

        return [
            'flights_today' => $today->count(),
            'passengers_today' => $passengersToday,
            'bookings_week' => $weekBookingIds->count(),
            'revenue_week' => $revenue,
            'load_factor' => $capacity ? round($passengersToday / $capacity, 3) : 0,
            'checkin_open' => $today->where('status', FlightStatus::CheckinOpen)->count(),
            'daily' => $daily->values(),
            'top_routes' => $routes,
        ];
    }
}
