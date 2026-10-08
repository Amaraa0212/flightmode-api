<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\SeatAssignmentProfileResource;
use App\Models\Aircraft;
use App\Models\SeatAssignmentProfile;
use App\Services\Access\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/** Order in which seat zones are filled by automatic seat assignment (legacy seat_zone). */
class SeatAssignmentProfileController extends Controller
{
    public function __construct(private readonly AccessScope $access) {}

    public function index(Request $request, Aircraft $aircraft): AnonymousResourceCollection
    {
        $this->access->ensureAirlineOwned($request->user(), $aircraft);

        return SeatAssignmentProfileResource::collection($aircraft->seatAssignmentProfiles()->with('zones')->get());
    }

    public function store(Request $request, Aircraft $aircraft): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $aircraft);
        $data = $this->validated($request);

        $profile = DB::transaction(function () use ($aircraft, $data) {
            $profile = $aircraft->seatAssignmentProfiles()->create(['name' => $data['name']]);
            $this->syncZones($profile, $data['zones']);

            return $profile;
        });

        return $this->created('Profile created.', new SeatAssignmentProfileResource($profile->load('zones')));
    }

    public function update(Request $request, Aircraft $aircraft, SeatAssignmentProfile $profile): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $aircraft);
        abort_unless($profile->aircraft_id === $aircraft->id, 404);
        $data = $this->validated($request);

        DB::transaction(function () use ($profile, $data) {
            $profile->update(['name' => $data['name']]);
            $this->syncZones($profile, $data['zones']);
        });

        return $this->success('Profile updated.', new SeatAssignmentProfileResource($profile->load('zones')));
    }

    public function destroy(Request $request, Aircraft $aircraft, SeatAssignmentProfile $profile): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $aircraft);
        abort_unless($profile->aircraft_id === $aircraft->id, 404);
        $profile->delete();

        return $this->deleted('Profile deleted.');
    }

    /** @return array{name: string, zones: array<string>} */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'zones' => ['required', 'array', 'min:1'],
            'zones.*' => ['required', 'string', 'max:5', 'distinct'],
        ]);
    }

    /** @param array<string> $zones in fill order */
    private function syncZones(SeatAssignmentProfile $profile, array $zones): void
    {
        $profile->zones()->delete();
        foreach (array_values($zones) as $priority => $zone) {
            $profile->zones()->create(['zone' => strtoupper($zone), 'priority' => $priority + 1]);
        }
    }
}
