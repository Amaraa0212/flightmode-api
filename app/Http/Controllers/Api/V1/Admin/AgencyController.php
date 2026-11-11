<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AgencyRequest;
use App\Http\Resources\AgencyResource;
use App\Models\Agency;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Travel agencies. Airline users see the agencies contracted with their airline; what they add is their own
 * sales channel (ticket office, web, call center, own agents), which no other airline sees.
 */
class AgencyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        // Agents permission: the agencies the airline created, and those it already has a contract with —
        // never an agency another airline created
        $query = $request->boolean('for_contract') && $user->airline_id && $user->hasPermission('agency_access.view')
            ? Agency::query()->where(fn ($o) => $o->where('owner_airline_id', $user->airline_id)
                ->orWhere(fn ($shared) => $shared->whereNull('owner_airline_id')
                    ->whereHas('airlines', fn ($a) => $a->whereKey($user->airline_id))))
            : $this->visible($user);

        return AgencyResource::collection(
            $query->with(['airlines' => $this->ownContracts($user)])
                ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
                ->when($request->filled('channel'), fn ($q) => $q->whereIn('channel', (array) $request->input('channel')))
                ->when($request->filled('owned'), fn ($q) => $request->boolean('owned') ? $q->whereNotNull('owner_airline_id') : $q->whereNull('owner_airline_id'))
                ->orderBy('name')
                ->paginate($request->integer('per_page', 50))
        );
    }

    public function store(AgencyRequest $request): JsonResponse
    {
        $user = $request->user();

        $agency = DB::transaction(function () use ($request, $user) {
            // a system administrator may create it for one airline (owner_airline_id), or leave it shared
            $owner = $user->isSystemAdmin() ? $request->validated('owner_airline_id') : $user->airline_id;
            $agency = Agency::create(collect($request->validated())->except('owner_airline_id')->all() + ['owner_airline_id' => $owner]);
            if ($owner) {
                $agency->airlines()->attach($owner, ['is_active' => true]);
            }

            return $agency;
        });

        return $this->created('Agency created.', new AgencyResource($agency->load('airlines')));
    }

    public function show(Request $request, Agency $agency): AgencyResource
    {
        $this->ensureVisible($request->user(), $agency);

        return new AgencyResource($agency->load(['airlines' => $this->ownContracts($request->user())]));
    }

    public function update(AgencyRequest $request, Agency $agency): JsonResponse
    {
        $data = collect($request->validated())->except('owner_airline_id');
        // a contracted airline may still switch the Chinggis push of a shared agency; the rest is the owner's
        if ($data->keys()->diff(['chinggis_sync'])->isEmpty()) {
            $this->ensureVisible($request->user(), $agency);
        } else {
            $this->ensureOwned($request->user(), $agency);
        }
        $agency->update($data->all());

        return $this->success('Agency updated.', new AgencyResource($agency->load(['airlines' => $this->ownContracts($request->user())])));
    }

    public function destroy(Request $request, Agency $agency): JsonResponse
    {
        $this->ensureOwned($request->user(), $agency);
        $agency->delete();

        return $this->deleted('Agency deleted.');
    }

    /** An airline user sees its own contract of an agency, not those of other airlines. */
    private function ownContracts(User $user): Closure
    {
        return fn ($airlines) => $airlines->when(! $user->isSystemAdmin() && $user->airline_id, fn ($q) => $q->whereKey($user->airline_id));
    }

    private function visible(User $user): Builder
    {
        return Agency::query()
            ->when(! $user->isSystemAdmin() && $user->airline_id, fn ($q) => $q->whereHas('airlines', fn ($a) => $a->whereKey($user->airline_id))
                ->where(fn ($o) => $o->whereNull('owner_airline_id')->orWhere('owner_airline_id', $user->airline_id)))
            ->when(! $user->isSystemAdmin() && ! $user->airline_id, fn ($q) => $q->whereKey($user->agency_id ?? 0));
    }

    /** Only the airline that created an agency changes or deletes it (a system administrator: any agency) */
    private function ensureOwned(User $user, Agency $agency): void
    {
        if (! $user->isSystemAdmin() && (! $user->airline_id || $agency->owner_airline_id !== $user->airline_id)) {
            throw new AuthorizationException('Only the airline that created this agency can change or delete it.');
        }
    }

    private function ensureVisible(User $user, Agency $agency): void
    {
        if (! $this->visible($user)->whereKey($agency->id)->exists()) {
            throw new AuthorizationException('You do not have access to this agency.');
        }
    }
}
