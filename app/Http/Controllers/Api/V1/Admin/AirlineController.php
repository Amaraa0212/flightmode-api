<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AirlineRequest;
use App\Http\Resources\AgencyResource;
use App\Http\Resources\AirlineResource;
use App\Models\Agency;
use App\Models\Airline;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AirlineController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        return AirlineResource::collection(
            Airline::query()->withCount('agencies')
                ->when(! $user->isSystemAdmin(), fn ($q) => $q->whereKey($user->airline_id))
                ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
                ->orderBy('name')
                ->paginate($request->integer('per_page', 50))
        );
    }

    public function store(AirlineRequest $request): JsonResponse
    {
        $this->ensureSystemAdmin($request);
        $airline = Airline::create($request->validated());

        return $this->created('Airline created.', new AirlineResource($airline));
    }

    public function show(Request $request, Airline $airline): AirlineResource
    {
        $this->ensureOwn($request, $airline);

        return new AirlineResource($airline->load('agencies'));
    }

    public function update(AirlineRequest $request, Airline $airline): JsonResponse
    {
        $this->ensureOwn($request, $airline);
        $airline->update($request->validated());

        return $this->success('Airline updated.', new AirlineResource($airline));
    }

    public function destroy(Request $request, Airline $airline): JsonResponse
    {
        $this->ensureSystemAdmin($request);
        $airline->delete();

        return $this->deleted('Airline deleted.');
    }

    /** Agencies contracted with the airline. */
    public function agencies(Request $request, Airline $airline): AnonymousResourceCollection
    {
        $this->ensureOwn($request, $airline);

        return AgencyResource::collection($airline->agencies()->orderBy('name')->get());
    }

    /** Add / enable / disable the contract between an airline and an agency. */
    public function setAgencyContract(Request $request, Airline $airline, Agency $agency): JsonResponse
    {
        $this->ensureOwn($request, $airline);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        $airline->agencies()->syncWithoutDetaching([$agency->id => ['is_active' => $data['is_active']]]);

        return $this->success('Contract saved.');
    }

    public function removeAgencyContract(Request $request, Airline $airline, Agency $agency): JsonResponse
    {
        $this->ensureOwn($request, $airline);
        $airline->agencies()->detach($agency->id);

        return $this->deleted('Contract removed.');
    }

    private function ensureOwn(Request $request, Airline $airline): void
    {
        $user = $request->user();
        if (! $user->isSystemAdmin() && (int) $user->airline_id !== $airline->id) {
            throw new AuthorizationException('You can only manage your own airline.');
        }
    }

    private function ensureSystemAdmin(Request $request): void
    {
        if (! $request->user()->isSystemAdmin()) {
            throw new AuthorizationException('Only system administrators can do this.');
        }
    }
}
