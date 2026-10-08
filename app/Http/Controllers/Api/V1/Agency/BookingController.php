<?php

namespace App\Http\Controllers\Api\V1\Agency;

use App\Enums\ActivityChannel;
use App\Enums\BookingSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shared\StoreBookingRequest;
use App\Http\Requests\Shared\UpdatePassengerRequest;
use App\Http\Resources\BookingResource;
use App\Http\Resources\PassengerResource;
use App\Models\AgencyAllocation;
use App\Models\Passenger;
use App\Services\Access\AccessScope;
use App\Services\Booking\BookingService;
use App\Services\Booking\PassengerImportService;
use App\Services\Documents\ETicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Agency bookings. Used by the agency portal and, with a partner token, by the agency API
 * (the request tells which one through the `source`).
 */
class BookingController extends Controller
{
    public function __construct(
        private readonly AccessScope $access,
        private readonly BookingService $bookings,
        private readonly ETicketService $etickets,
    ) {}

    public function store(StoreBookingRequest $request): JsonResponse
    {
        if (! $request->filled('allocation_id')) {
            throw ValidationException::withMessages(['allocation_id' => 'Choose the allocation to book on.']);
        }
        $this->access->ensureAllocation($request->user(), AgencyAllocation::query()->findOrFail($request->integer('allocation_id')));

        $booking = $this->bookings->create(
            collect($request->validated())->except('flight_ids')->all(),
            $request->user(),
            $this->source($request),
        );

        if ($request->boolean('send_eticket')) {
            foreach ($booking->passengers->whereNull('infant_of_passenger_id') as $passenger) {
                if ($passenger->email || $booking->contact_email) {
                    $this->etickets->email($passenger);
                }
            }
        }

        return $this->created('Booking created.', new BookingResource($booking->load('agency')));
    }

    public function showPassenger(Request $request, Passenger $passenger): PassengerResource
    {
        $this->ensureOwn($request, $passenger);

        return new PassengerResource($passenger->load(['booking', 'infant.segments', 'segments.flight.origin',
            'segments.flight.destination', 'segments.ssrCodes', 'segments.prepaidBaggage']));
    }

    public function updatePassenger(UpdatePassengerRequest $request, Passenger $passenger): JsonResponse
    {
        $this->ensureOwn($request, $passenger);
        $passenger = $this->bookings->updatePassenger($passenger, $request->validated(), $request->user(), $this->channel($request));

        return $this->success('Passenger updated.', new PassengerResource($passenger));
    }

    public function cancelPassenger(Request $request, Passenger $passenger): JsonResponse
    {
        $this->ensureOwn($request, $passenger);
        if ($passenger->booking->allocation && ! $passenger->booking->allocation->isOpenForNames()) {
            throw ValidationException::withMessages(['passenger' => 'The name list deadline has passed; contact the airline.']);
        }

        $this->bookings->cancelPassenger($passenger, $this->channel($request));

        return $this->deleted('Passenger cancelled.');
    }

    public function eticket(Request $request, Passenger $passenger): Response
    {
        $this->ensureOwn($request, $passenger);
        $file = $this->etickets->pdf($passenger);

        return response($file['content'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
        ]);
    }

    public function emailEticket(Request $request, Passenger $passenger): JsonResponse
    {
        $this->ensureOwn($request, $passenger);
        $data = $request->validate(['email' => ['nullable', 'email']]);

        return $this->success('E-ticket sent to '.$this->etickets->email($passenger, $data['email'] ?? null).'.');
    }

    /** Excel upload of a passenger list into an allocation. */
    public function import(Request $request, AgencyAllocation $allocation, PassengerImportService $importer): JsonResponse
    {
        $this->access->ensureAllocation($request->user(), $allocation);
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120'],
            'replace_existing' => ['boolean'],
        ]);

        $result = $importer->import($request->file('file'), $allocation->load('outboundFlight'), $request->user(), $request->boolean('replace_existing'));

        return $this->created("{$result['created']} passenger(s) imported.", $result);
    }

    /** Pre-fill the form from the agency's last booking of the same document number. */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate(['document_number' => ['required', 'string', 'max:50']]);

        $passenger = Passenger::query()->where('document_number', strtoupper($data['document_number']))
            ->whereHas('booking', fn ($q) => $q->where('agency_id', $request->user()->agency_id ?? 0))
            ->latest('id')->first();

        return response()->json(['data' => $passenger ? (new PassengerResource($passenger))->resolve() : null]);
    }

    private function ensureOwn(Request $request, Passenger $passenger): void
    {
        $this->access->ensureBooking($request->user(), $passenger->booking);
    }

    private function source(Request $request): BookingSource
    {
        return $request->user()->usesPartnerToken()
            ? BookingSource::AgencyApi
            : BookingSource::Agency;
    }

    private function channel(Request $request): ActivityChannel
    {
        return $this->source($request) === BookingSource::AgencyApi ? ActivityChannel::AgencyApi : ActivityChannel::Agency;
    }
}
