<?php

namespace App\Http\Controllers\Api\V1\Gate;

use App\Enums\FlightStatus;
use App\Enums\SegmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\FlightResource;
use App\Http\Resources\SegmentResource;
use App\Models\Flight;
use App\Models\PassengerSegment;
use App\Services\Checkin\BoardingPassService;
use App\Services\Checkin\CheckinService;
use App\Services\Flight\FlightStatistics;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Gate (boarding) app: the flights a gate agent is assigned to, boarding by scan, offloading. */
class GateController extends Controller
{
    public function __construct(
        private readonly CheckinService $checkin,
        private readonly BoardingPassService $boardingPasses,
        private readonly FlightStatistics $statistics,
    ) {}

    public function flights(Request $request): AnonymousResourceCollection
    {
        $flights = $request->user()->isSystemAdmin()
            ? Flight::query()
            : Flight::query()->whereHas('gateAgents', fn ($q) => $q->whereKey($request->user()->id));

        return FlightResource::collection($flights->with(['airline', 'aircraft', 'origin', 'destination'])
            ->whereNotIn('status', [FlightStatus::Departed->value, FlightStatus::Cancelled->value])
            ->upcoming(24)
            ->orderBy('scheduled_departure_at')
            ->get());
    }

    public function show(Request $request, Flight $flight): JsonResponse
    {
        $this->ensureAssigned($request, $flight);

        return response()->json(['data' => [
            'flight' => (new FlightResource($flight->load(['airline', 'aircraft', 'origin', 'destination'])))->resolve(),
            'statistics' => $this->statistics->summary($flight),
        ]]);
    }

    /** ?state=checked_in|boarded&search= */
    public function passengers(Request $request, Flight $flight): AnonymousResourceCollection
    {
        $this->ensureAssigned($request, $flight);
        $term = trim((string) $request->input('search'));
        $states = match ($request->input('state')) {
            'boarded' => [SegmentStatus::Boarded],
            'checked_in' => [SegmentStatus::CheckedIn],
            default => [SegmentStatus::CheckedIn, SegmentStatus::Boarded],
        };

        return SegmentResource::collection($flight->segments()
            ->with(['passenger.booking.agency', 'ssrCodes'])
            ->whereIn('status', array_map(fn ($s) => $s->value, $states))
            ->when($term !== '', fn ($q) => $q->where(fn ($x) => $x
                ->where('ticket_number', 'like', "%{$term}%")
                ->orWhereHas('passenger', fn ($p) => $p->where('first_name', 'like', "%{$term}%")->orWhere('last_name', 'like', "%{$term}%"))
                ->orWhereHas('passenger.booking', fn ($b) => $b->where('pnr', strtoupper($term)))))
            ->orderBy('boarding_sequence')
            ->get());
    }

    /** Scan a boarding pass. The barcode ends with "<seat> <segment id>". */
    public function board(Request $request, Flight $flight): JsonResponse
    {
        $this->ensureAssigned($request, $flight);
        $data = $request->validate(['barcode' => ['required', 'string', 'max:255']]);
        [$segmentId, $seatOnPass] = $this->boardingPasses->parseScan($data['barcode']);

        $segment = PassengerSegment::query()->with('passenger.infant')->find($segmentId);
        if (! $segment) {
            throw BusinessRuleException::make('passenger_not_found', 'No passenger found for this boarding pass.', status: 404);
        }
        if ($segment->flight_id !== $flight->id) {
            throw BusinessRuleException::make('wrong_flight', 'This passenger is not booked on this flight.');
        }
        if ($seatOnPass && $segment->seat_number && $seatOnPass !== $segment->seat_number) {
            throw BusinessRuleException::make('seat_mismatch', "The boarding pass seat ({$seatOnPass}) is not the current seat ({$segment->seat_number}); reprint it.");
        }
        // A second scan of the same pass: someone else may be using it, so the agent must see it was already used
        if ($segment->status === SegmentStatus::Boarded) {
            $at = $segment->boarded_at?->copy()->timezone($flight->origin?->timezone ?? config('app.timezone'))->format('H:i');
            throw BusinessRuleException::make('already_boarded', 'Already boarded'.($at ? " at {$at}" : '').': '
                .$segment->passenger->last_name.' '.$segment->passenger->first_name.'.', ['boarded_at' => $segment->boarded_at?->toIso8601String()], 409);
        }

        $segment = $this->checkin->board($segment);

        return $this->success('Boarded: '.$segment->passenger->last_name.' '.$segment->passenger->first_name
            .($segment->passenger->infant ? ' with infant' : '').'.', new SegmentResource($segment->load('passenger')));
    }

    public function offload(Request $request, PassengerSegment $segment): JsonResponse
    {
        $this->ensureAssigned($request, $segment->flight);
        $data = $request->validate([
            'type' => ['required', 'string', 'max:20'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->success('Passenger offloaded.', new SegmentResource($this->checkin->offload($segment, $data['type'], $data['reason'] ?? null)));
    }

    private function ensureAssigned(Request $request, Flight $flight): void
    {
        $user = $request->user();
        if (! $user->isSystemAdmin() && ! $flight->gateAgents()->whereKey($user->id)->exists()) {
            throw new AuthorizationException('You are not assigned to this flight.');
        }
    }
}
