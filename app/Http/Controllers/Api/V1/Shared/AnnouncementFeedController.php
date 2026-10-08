<?php

namespace App\Http\Controllers\Api\V1\Shared;

use App\Http\Controllers\Controller;
use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Announcements addressed to the signed-in user (agency portal, DCS, back office). */
class AnnouncementFeedController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $announcements = Announcement::query()->with(['airline', 'agency'])
            ->visibleTo($user)
            ->where(fn ($q) => $q->where('requires_acknowledgement', true)
                ->orWhereDoesntHave('dismissedBy', fn ($d) => $d->whereKey($user->id)))
            ->orderByDesc('requires_acknowledgement')
            ->latest('id')
            ->get();

        return AnnouncementResource::collection($announcements);
    }

    public function dismiss(Request $request, Announcement $announcement): JsonResponse
    {
        abort_unless(Announcement::query()->visibleTo($request->user())->whereKey($announcement->id)->exists(), 404);

        if ($announcement->requires_acknowledgement) {
            return response()->json(['message' => 'This announcement cannot be closed.', 'code' => 'announcement_required'], 422);
        }

        $announcement->dismissedBy()->syncWithoutDetaching([$request->user()->id => ['dismissed_at' => now()]]);

        return response()->json(['message' => 'Dismissed.']);
    }
}
