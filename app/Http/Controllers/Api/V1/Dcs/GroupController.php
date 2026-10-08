<?php

namespace App\Http\Controllers\Api\V1\Dcs;

use App\Enums\ActivityChannel;
use App\Http\Controllers\Controller;
use App\Models\CheckinGroup;
use App\Models\Flight;
use App\Models\PassengerSegment;
use App\Services\Access\AccessScope;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Passengers checked in together (shared baggage). */
class GroupController extends Controller
{
    public function __construct(
        private readonly AccessScope $access,
        private readonly ActivityLogger $logger,
    ) {}

    public function store(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $flight);
        $data = $request->validate([
            'segment_ids' => ['required', 'array', 'min:2'],
            'segment_ids.*' => ['integer', 'distinct'],
            'name' => ['nullable', 'string', 'max:100'],
        ]);

        $segments = PassengerSegment::query()->where('flight_id', $flight->id)->whereIn('id', $data['segment_ids'])
            ->whereHas('passenger', fn ($q) => $q->whereNull('infant_of_passenger_id'))->get();
        if ($segments->count() !== count($data['segment_ids'])) {
            throw ValidationException::withMessages(['segment_ids' => 'Some passengers are not on this flight or are infants.']);
        }

        $group = DB::transaction(function () use ($flight, $segments, $data, $request) {
            $group = CheckinGroup::create(['flight_id' => $flight->id, 'name' => $data['name'] ?? null, 'created_by' => $request->user()->id]);
            PassengerSegment::query()->whereIn('id', $segments->pluck('id'))->update(['checkin_group_id' => $group->id]);

            return $group;
        });

        $this->logger->log(ActivityChannel::Dcs, 'passenger.grouped', 'Segments: '.$segments->pluck('id')->implode(', '), $group, $flight);

        return $this->created('Group created.', ['id' => $group->id, 'segment_ids' => $segments->pluck('id')]);
    }

    public function destroy(Request $request, PassengerSegment $segment): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $segment->flight);
        $groupId = $segment->checkin_group_id;
        $segment->update(['checkin_group_id' => null]);

        if ($groupId && ! PassengerSegment::query()->where('checkin_group_id', $groupId)->exists()) {
            CheckinGroup::query()->whereKey($groupId)->delete();
        }

        $this->logger->log(ActivityChannel::Dcs, 'passenger.ungrouped', "Group {$groupId}", $segment, $segment->flight_id, $segment->passenger_id);

        return $this->deleted('Removed from group.');
    }
}
