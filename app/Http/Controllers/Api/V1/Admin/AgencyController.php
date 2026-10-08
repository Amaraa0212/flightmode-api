<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AgencyRequest;
use App\Http\Resources\AgencyResource;
use App\Models\Agency;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/** Travel agencies. Airline users see the agencies contracted with their airline. */
class AgencyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return AgencyResource::collection(
            $this->visible($request->user())->with('airlines')
                ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
                ->orderBy('name')
                ->paginate($request->integer('per_page', 50))
        );
    }

    public function store(AgencyRequest $request): JsonResponse
    {
        $user = $request->user();

        $agency = DB::transaction(function () use ($request, $user) {
            $agency = Agency::create($request->validated());
            if ($user->airline_id) {
                $agency->airlines()->attach($user->airline_id, ['is_active' => true]);
            }

            return $agency;
        });

        return $this->created('Agency created.', new AgencyResource($agency->load('airlines')));
    }

    public function show(Request $request, Agency $agency): AgencyResource
    {
        $this->ensureVisible($request->user(), $agency);

        return new AgencyResource($agency->load('airlines'));
    }

    public function update(AgencyRequest $request, Agency $agency): JsonResponse
    {
        $this->ensureVisible($request->user(), $agency);
        $agency->update($request->validated());

        return $this->success('Agency updated.', new AgencyResource($agency->load('airlines')));
    }

    public function destroy(Request $request, Agency $agency): JsonResponse
    {
        if (! $request->user()->isSystemAdmin()) {
            throw new AuthorizationException('Only system administrators can delete agencies.');
        }
        $agency->delete();

        return $this->deleted('Agency deleted.');
    }

    private function visible(User $user): Builder
    {
        return Agency::query()
            ->when(! $user->isSystemAdmin() && $user->airline_id, fn ($q) => $q->whereHas('airlines', fn ($a) => $a->whereKey($user->airline_id)))
            ->when(! $user->isSystemAdmin() && ! $user->airline_id, fn ($q) => $q->whereKey($user->agency_id ?? 0));
    }

    private function ensureVisible(User $user, Agency $agency): void
    {
        if (! $this->visible($user)->whereKey($agency->id)->exists()) {
            throw new AuthorizationException('You do not have access to this agency.');
        }
    }
}
