<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CharterRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CharterRequestResource;
use App\Models\CharterRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Charter requests: anyone with charters.manage can ask airlines for a charter;
 * airlines see requests sent to them and change the status.
 */
class CharterRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return CharterRequestResource::collection(
            $this->visible($request)->with(['requester', 'origin', 'destination', 'airlines'])
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
                ->latest('id')->paginate($request->integer('per_page', 50))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'origin_airport_id' => ['required', 'integer', 'exists:airports,id'],
            'destination_airport_id' => ['required', 'integer', 'exists:airports,id', 'different:origin_airport_id'],
            'departure_date' => ['required', 'date', 'after_or_equal:today'],
            'return_date' => ['nullable', 'date', 'after_or_equal:departure_date'],
            'pax_count' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'airline_ids' => ['required', 'array', 'min:1'],
            'airline_ids.*' => ['integer', 'exists:airlines,id'],
        ]);

        $charter = DB::transaction(function () use ($data, $request) {
            $charter = CharterRequest::create(collect($data)->except('airline_ids')->all() + [
                'requested_by' => $request->user()->id,
                'status' => CharterRequestStatus::Requested,
            ]);
            $charter->airlines()->attach($data['airline_ids']);

            return $charter;
        });

        return $this->created('Charter request sent.', new CharterRequestResource($charter->load(['origin', 'destination', 'airlines'])));
    }

    public function show(Request $request, CharterRequest $charterRequest): CharterRequestResource
    {
        abort_unless($this->visible($request)->whereKey($charterRequest->id)->exists(), 404);

        return new CharterRequestResource($charterRequest->load(['requester', 'origin', 'destination', 'airlines']));
    }

    public function updateStatus(Request $request, CharterRequest $charterRequest): JsonResponse
    {
        $user = $request->user();
        $isAddressed = $user->isSystemAdmin() || $charterRequest->airlines()->whereKey($user->airline_id ?? 0)->exists();
        if (! $isAddressed) {
            throw new AuthorizationException('This request was not sent to your airline.');
        }

        $data = $request->validate([
            'status' => ['required', Rule::enum(CharterRequestStatus::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $charterRequest->update($data);

        return $this->success('Status updated.', new CharterRequestResource($charterRequest->load(['origin', 'destination', 'airlines'])));
    }

    private function visible(Request $request): Builder
    {
        $user = $request->user();

        return CharterRequest::query()->when(! $user->isSystemAdmin(), fn ($q) => $q->where(fn ($x) => $x
            ->where('requested_by', $user->id)
            ->when($user->airline_id, fn ($y) => $y->orWhereHas('airlines', fn ($a) => $a->whereKey($user->airline_id)))));
    }
}
