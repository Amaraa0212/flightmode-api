<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AirportRequest;
use App\Http\Resources\AirportResource;
use App\Models\Airport;
use App\Models\Flight;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class AirportController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return AirportResource::collection(Airport::query()->with('country')
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($x) => $x
                ->where('iata_code', 'like', $request->string('search')->upper().'%')
                ->orWhere('name', 'like', '%'.$request->string('search').'%')))
            ->orderBy('iata_code')->paginate($request->integer('per_page', 100)));
    }

    public function store(AirportRequest $request): JsonResponse
    {
        return $this->created('Airport created.', new AirportResource(Airport::create($request->validated())->load('country')));
    }

    public function show(Airport $airport): AirportResource
    {
        return new AirportResource($airport->load('country'));
    }

    public function update(AirportRequest $request, Airport $airport): JsonResponse
    {
        $airport->update($request->validated());

        return $this->success('Airport updated.', new AirportResource($airport->load('country')));
    }

    public function destroy(Airport $airport): JsonResponse
    {
        $used = Flight::withTrashed()->where('origin_airport_id', $airport->id)->orWhere('destination_airport_id', $airport->id)->exists();
        if ($used) {
            throw ValidationException::withMessages(['airport' => 'The airport is used by flights; deactivate it instead.']);
        }
        $airport->delete();

        return $this->deleted('Airport deleted.');
    }
}
