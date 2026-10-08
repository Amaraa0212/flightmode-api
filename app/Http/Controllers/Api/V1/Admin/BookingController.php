<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\BookingSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shared\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\AgencyAllocation;
use App\Models\Booking;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\PassengerSegment;
use App\Services\Access\AccessScope;
use App\Services\Booking\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Back office bookings: search, direct sale, merge / split, waitlist, rebooking. */
class BookingController extends Controller
{
    public function __construct(
        private readonly AccessScope $access,
        private readonly BookingService $bookings,
    ) {}

    /** Filters: search (PNR, name, document, ticket), flight_id, agency_id, status. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $term = trim((string) $request->input('search'));

        $bookings = $this->access->bookings(Booking::query(), $request->user())
            ->with(['agency', 'passengers.segments.flight.origin', 'passengers.segments.flight.destination'])
            ->when($term !== '', fn ($q) => $q->where(fn ($x) => $x
                ->where('pnr', strtoupper($term))
                ->orWhereHas('passengers', fn ($p) => $p
                    ->where('last_name', 'like', "%{$term}%")
                    ->orWhere('first_name', 'like', "%{$term}%")
                    ->orWhere('document_number', $term))
                ->orWhereHas('passengers.segments', fn ($s) => $s->where('ticket_number', $term))))
            ->when($request->filled('flight_id'), fn ($q) => $q->whereHas('passengers.segments', fn ($s) => $s->where('flight_id', $request->integer('flight_id'))))
            ->when($request->filled('agency_id'), fn ($q) => $q->where('agency_id', $request->integer('agency_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('id')
            ->paginate($request->integer('per_page', 50));

        return BookingResource::collection($bookings);
    }

    public function show(Request $request, Booking $booking): BookingResource
    {
        $this->access->ensureBooking($request->user(), $booking);

        return new BookingResource($booking->load([
            'agency', 'creator', 'passengers.infant', 'passengers.segments.flight.origin', 'passengers.segments.flight.destination',
            'passengers.segments.ssrCodes', 'passengers.segments.prepaidBaggage',
        ]));
    }

    /** Direct sale by the airline (flight_ids) or on behalf of an agency (allocation_id). */
    public function store(StoreBookingRequest $request): JsonResponse
    {
        $user = $request->user();
        if ($request->filled('allocation_id')) {
            $this->access->ensureAllocation($user, AgencyAllocation::query()->findOrFail($request->integer('allocation_id')));
        }
        foreach ($request->validated('flight_ids', []) as $flightId) {
            $this->access->ensureAirlineOwned($user, Flight::query()->findOrFail($flightId));
        }

        $booking = $this->bookings->create($request->validated(), $user, BookingSource::Admin);

        return $this->created('Booking created.', new BookingResource($booking));
    }

    /** Put passengers into one PNR (legacy "group"). */
    public function merge(Request $request): JsonResponse
    {
        $data = $request->validate(['passenger_ids' => ['required', 'array', 'min:2'], 'passenger_ids.*' => ['integer', 'exists:passengers,id']]);
        Passenger::query()->with('booking')->whereIn('id', $data['passenger_ids'])->get()
            ->each(fn (Passenger $p) => $this->access->ensureBooking($request->user(), $p->booking));

        $booking = $this->bookings->mergeIntoOneBooking($data['passenger_ids']);

        return $this->success("Passengers merged into {$booking->pnr}.", new BookingResource($booking));
    }

    /** Give a passenger their own PNR (legacy "split group"). */
    public function split(Request $request, Passenger $passenger): JsonResponse
    {
        $this->access->ensureBooking($request->user(), $passenger->booking);
        $booking = $this->bookings->splitPassenger($passenger);

        return $this->success("Passenger moved to {$booking->pnr}.", new BookingResource($booking));
    }

    public function waitlist(Request $request): JsonResponse
    {
        $data = $request->validate([
            'segment_ids' => ['required', 'array', 'min:1'],
            'segment_ids.*' => ['integer', 'exists:passenger_segments,id'],
            'waitlisted' => ['required', 'boolean'],
        ]);
        PassengerSegment::query()->with('passenger.booking')->whereIn('id', $data['segment_ids'])->get()
            ->each(fn (PassengerSegment $s) => $this->access->ensureBooking($request->user(), $s->passenger->booking));

        $count = $this->bookings->setWaitlisted($data['segment_ids'], $data['waitlisted']);

        return $this->success("{$count} passenger(s) updated.");
    }

    public function rebook(Request $request, PassengerSegment $segment): JsonResponse
    {
        $this->access->ensureBooking($request->user(), $segment->passenger->booking);
        $data = $request->validate(['flight_id' => ['required', 'integer', 'exists:flights,id']]);
        $flight = Flight::query()->findOrFail($data['flight_id']);
        $this->access->ensureAirlineOwned($request->user(), $flight);

        $this->bookings->rebook($segment, $flight);

        return $this->success('Passenger rebooked.');
    }
}
