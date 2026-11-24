<?php

namespace App\Http\Controllers\Api\V1\Dcs;

use App\Enums\CheckinChannel;
use App\Enums\SegmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SegmentResource;
use App\Models\PassengerSegment;
use App\Services\Access\AccessScope;
use App\Services\Checkin\BoardingPassService;
use App\Services\Checkin\CheckinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckinController extends Controller
{
    public function __construct(
        private readonly AccessScope $access,
        private readonly CheckinService $checkin,
        private readonly BoardingPassService $boardingPasses,
    ) {}

    /** Check in / change seat. Without seat_number the next free seat is assigned. */
    public function store(Request $request, PassengerSegment $segment): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $segment->flight);
        $this->checkin->assertCounterOpen($segment->flight);
        $data = $request->validate(['seat_number' => ['nullable', 'string', 'max:4']]);

        $firstCheckin = $segment->status === SegmentStatus::Booked;
        $segment = $this->checkin->checkIn($segment, $data['seat_number'] ?? null, CheckinChannel::Dcs);

        // the group's members with held seats come along (a seat change of a checked-in passenger moves only them)
        $group = $firstCheckin ? $this->checkin->checkInHeldGroupMates($segment, CheckinChannel::Dcs) : ['checked_in' => [], 'skipped' => []];
        $message = 'Checked in.';
        if ($group['checked_in']) {
            $message .= ' Group, on their held seats: '.collect($group['checked_in'])
                ->map(fn (PassengerSegment $s) => $s->passenger->last_name.'/'.$s->passenger->first_name.' '.$s->seat_number)->implode(', ').'.';
        }
        if ($group['skipped']) {
            $message .= ' Not checked in: '.implode('; ', $group['skipped']);
        }

        return $this->success($message, (new SegmentResource($segment))->resolve($request) + [
            'group_checked_in' => collect($group['checked_in'])->pluck('id')->all(),
        ]);
    }

    /** Hold a seat for a booked passenger without the check-in (seat_number null releases it). */
    public function holdSeat(Request $request, PassengerSegment $segment): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $segment->flight);
        $this->checkin->assertCounterOpen($segment->flight);
        $data = $request->validate(['seat_number' => ['present', 'nullable', 'string', 'max:4']]);

        $segment = $this->checkin->holdSeat($segment, $data['seat_number']);

        return $this->success($segment->seat_number ? "Seat {$segment->seat_number} held." : 'Seat released.', new SegmentResource($segment));
    }

    public function destroy(Request $request, PassengerSegment $segment): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $segment->flight);
        $this->checkin->assertCounterOpen($segment->flight);

        return $this->success('Check-in cancelled.', new SegmentResource($this->checkin->cancelCheckin($segment)));
    }

    public function boardingPass(Request $request, PassengerSegment $segment): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $segment->flight);

        return response()->json(['data' => $this->boardingPasses->build($segment)]);
    }
}
