<?php

namespace App\Http\Controllers\Api\V1\Dcs;

use App\Enums\ActivityChannel;
use App\Enums\BookingSource;
use App\Enums\SegmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shared\PassengerFields;
use App\Http\Requests\Shared\UpdatePassengerRequest;
use App\Http\Resources\ActivityLogResource;
use App\Http\Resources\PassengerResource;
use App\Http\Resources\SegmentResource;
use App\Models\ActivityLog;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\PassengerSegment;
use App\Services\Access\AccessScope;
use App\Services\Booking\BookingService;
use App\Services\Checkin\CheckinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PassengerController extends Controller
{
    private const SEGMENT_RELATIONS = ['passenger.booking.agency', 'passenger.infant', 'ssrCodes', 'checkedBaggage.tags', 'prepaidBaggage'];

    public function __construct(
        private readonly AccessScope $access,
        private readonly BookingService $bookings,
        private readonly CheckinService $checkin,
    ) {}

    /** Adults on the flight with infant, baggage and SSR. ?status=booked|checked_in|boarded&search= */
    public function index(Request $request, Flight $flight): AnonymousResourceCollection
    {
        $this->access->ensureFlight($request->user(), $flight);
        $term = trim((string) $request->input('search'));

        $segments = $flight->segments()
            ->with(self::SEGMENT_RELATIONS)
            ->whereNotIn('status', [SegmentStatus::Cancelled->value])
            ->whereHas('passenger', fn ($q) => $q->whereNull('infant_of_passenger_id')
                ->when($term !== '', fn ($p) => $p->where(fn ($x) => $x
                    ->where('last_name', 'like', "%{$term}%")->orWhere('first_name', 'like', "%{$term}%")
                    ->orWhere('document_number', $term)->orWhereHas('booking', fn ($b) => $b->where('pnr', strtoupper($term))))))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->get()
            ->sortBy(fn ($s) => [$s->isCheckedIn() ? 1 : 0, $s->passenger->last_name, $s->passenger->first_name])
            ->values();

        return SegmentResource::collection($segments);
    }

    /** One passenger on the flight (same shape as a row of index) — the DCS "General Info" panel. */
    public function show(Request $request, PassengerSegment $segment): SegmentResource
    {
        $this->access->ensureFlight($request->user(), $segment->flight);

        return new SegmentResource($segment->load(self::SEGMENT_RELATIONS));
    }

    /** Go-show: a passenger added at the counter. */
    public function store(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $flight);
        $this->checkin->assertCounterOpen($flight);
        $data = $request->validate(PassengerFields::rules());

        $booking = $this->bookings->create(['flight_ids' => [$flight->id], 'passengers' => [$data]],
            $request->user(), BookingSource::Counter, goShow: true);

        return $this->created('Passenger added.', (new PassengerResource($booking->passengers->first()->load('segments')))->resolve());
    }

    public function update(UpdatePassengerRequest $request, Passenger $passenger): JsonResponse
    {
        $this->access->ensureBooking($request->user(), $passenger->booking);
        $passenger = $this->bookings->updatePassenger($passenger, $request->validated(), $request->user(), ActivityChannel::Dcs);

        return $this->success('Passenger updated.', new PassengerResource($passenger));
    }

    public function activity(Request $request, Passenger $passenger): AnonymousResourceCollection
    {
        $this->access->ensureBooking($request->user(), $passenger->booking);

        return ActivityLogResource::collection(
            ActivityLog::query()->with('user')->where('passenger_id', $passenger->id)->latest('id')->get()
        );
    }
}
