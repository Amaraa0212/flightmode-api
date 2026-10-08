<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ActivityChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shared\UpdatePassengerRequest;
use App\Http\Resources\ActivityLogResource;
use App\Http\Resources\PassengerResource;
use App\Models\ActivityLog;
use App\Models\Passenger;
use App\Services\Access\AccessScope;
use App\Services\Booking\BookingService;
use App\Services\Documents\ETicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PassengerController extends Controller
{
    public function __construct(
        private readonly AccessScope $access,
        private readonly BookingService $bookings,
        private readonly ETicketService $etickets,
    ) {}

    public function show(Request $request, Passenger $passenger): PassengerResource
    {
        $this->access->ensureBooking($request->user(), $passenger->booking);

        return new PassengerResource($passenger->load(['booking.agency', 'infant.segments', 'segments.flight.origin',
            'segments.flight.destination', 'segments.ssrCodes', 'segments.prepaidBaggage', 'segments.checkedBaggage.tags']));
    }

    public function update(UpdatePassengerRequest $request, Passenger $passenger): JsonResponse
    {
        $this->access->ensureBooking($request->user(), $passenger->booking);
        $passenger = $this->bookings->updatePassenger($passenger, $request->validated(), $request->user(), ActivityChannel::Admin);

        return $this->success('Passenger updated.', new PassengerResource($passenger));
    }

    public function destroy(Request $request, Passenger $passenger): JsonResponse
    {
        $this->access->ensureBooking($request->user(), $passenger->booking);
        $this->bookings->cancelPassenger($passenger, ActivityChannel::Admin, $request->input('reason'));

        return $this->deleted('Passenger cancelled.');
    }

    public function eticket(Request $request, Passenger $passenger): Response
    {
        $this->access->ensureBooking($request->user(), $passenger->booking);
        $file = $this->etickets->pdf($passenger);

        return response($file['content'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
        ]);
    }

    public function emailEticket(Request $request, Passenger $passenger): JsonResponse
    {
        $this->access->ensureBooking($request->user(), $passenger->booking);
        $data = $request->validate(['email' => ['nullable', 'email']]);
        $to = $this->etickets->email($passenger, $data['email'] ?? null);

        return $this->success("E-ticket sent to {$to}.");
    }

    public function activity(Request $request, Passenger $passenger): AnonymousResourceCollection
    {
        $this->access->ensureBooking($request->user(), $passenger->booking);

        return ActivityLogResource::collection(
            ActivityLog::query()->with('user')->where('passenger_id', $passenger->id)->latest('id')->get()
        );
    }
}
