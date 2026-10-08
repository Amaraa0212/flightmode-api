<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ActivityChannel;
use App\Http\Controllers\Controller;
use App\Models\AircraftSeat;
use App\Models\Flight;
use App\Services\Access\AccessScope;
use App\Services\ActivityLogger;
use App\Services\Seat\SeatMapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Seats that may not be sold or assigned on a flight. */
class SeatBlockController extends Controller
{
    public function __construct(
        private readonly AccessScope $access,
        private readonly SeatMapService $seatMap,
        private readonly ActivityLogger $logger,
    ) {}

    public function seatMap(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $flight);

        return response()->json(['data' => $this->seatMap->forFlight($flight, withOccupants: true)]);
    }

    public function block(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $flight);
        $data = $request->validate([
            'seat_numbers' => ['required', 'array', 'min:1'],
            'seat_numbers.*' => ['string', 'max:4'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $seats = array_map('strtoupper', $data['seat_numbers']);
        $known = AircraftSeat::query()->where('aircraft_id', $flight->aircraft_id)->whereIn('seat_number', $seats)->pluck('seat_number')->all();
        if ($unknown = array_diff($seats, $known)) {
            throw ValidationException::withMessages(['seat_numbers' => 'Unknown seats: '.implode(', ', $unknown)]);
        }
        $occupied = $this->seatMap->occupiedSeats($flight)->keys()->intersect($seats)->all();
        if ($occupied) {
            throw ValidationException::withMessages(['seat_numbers' => 'Seats already taken: '.implode(', ', $occupied)]);
        }

        foreach ($seats as $seat) {
            $flight->seatBlocks()->firstOrCreate(['seat_number' => $seat], ['reason' => $data['reason'] ?? null, 'created_by' => $request->user()->id]);
        }
        $this->logger->log(ActivityChannel::Admin, 'flight.seats_blocked', implode(', ', $seats), $flight, $flight);

        return $this->success(count($seats).' seat(s) blocked.');
    }

    public function unblock(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $flight);
        $data = $request->validate(['seat_numbers' => ['required', 'array', 'min:1'], 'seat_numbers.*' => ['string', 'max:4']]);

        $seats = array_map('strtoupper', $data['seat_numbers']);
        $count = $flight->seatBlocks()->whereIn('seat_number', $seats)->delete();
        $this->logger->log(ActivityChannel::Admin, 'flight.seats_unblocked', implode(', ', $seats), $flight, $flight);

        return $this->success("{$count} seat(s) released.");
    }
}
