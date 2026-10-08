<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use App\Services\Access\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/** Messages to agencies / staff (legacy "notification required"). */
class AnnouncementController extends Controller
{
    public function __construct(private readonly AccessScope $access) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return AnnouncementResource::collection(
            $this->access->airlineOwned(Announcement::query(), $request->user())
                ->with(['airline', 'agency'])->latest('id')->paginate($request->integer('per_page', 50))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $airlineId = $this->access->airlineIdForWrite($request->user(), $request->integer('airline_id') ?: null);
        $announcement = Announcement::create($this->validated($request, $airlineId) + [
            'airline_id' => $airlineId,
            'created_by' => $request->user()->id,
        ]);

        return $this->created('Announcement created.', new AnnouncementResource($announcement->load(['airline', 'agency'])));
    }

    public function update(Request $request, Announcement $announcement): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $announcement);
        $announcement->update($this->validated($request, $announcement->airline_id, $announcement));

        return $this->success('Announcement updated.', new AnnouncementResource($announcement->load(['airline', 'agency'])));
    }

    public function destroy(Request $request, Announcement $announcement): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $announcement);
        $announcement->delete();

        return $this->deleted('Announcement deleted.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, int $airlineId, ?Announcement $announcement = null): array
    {
        $required = $announcement ? 'sometimes' : 'required';

        return $request->validate([
            'agency_id' => ['nullable', 'integer', function ($attribute, $value, $fail) use ($airlineId) {
                $contracted = DB::table('agency_airline')->where('airline_id', $airlineId)->where('agency_id', $value)->exists();
                if (! $contracted) {
                    $fail('The agency is not contracted with this airline.');
                }
            }],
            'title' => [$required, 'string', 'max:300'],
            'body' => [$required, 'string'],
            'requires_acknowledgement' => ['boolean'],
        ]);
    }
}
