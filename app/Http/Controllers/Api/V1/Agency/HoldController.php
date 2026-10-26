<?php

namespace App\Http\Controllers\Api\V1\Agency;

use App\Enums\ActivityChannel;
use App\Enums\CabinClass;
use App\Http\Controllers\Controller;
use App\Http\Resources\AgencyHoldResource;
use App\Http\Resources\AllocationResource;
use App\Models\AgencyAllocation;
use App\Models\AgencyHold;
use App\Services\Access\AccessScope;
use App\Services\Allocation\AllocationService;
use App\Services\Allocation\HoldService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Agency portal: find the agency's flights of a day, hold seats on them, enter the names later. */
class HoldController extends Controller
{
    public function __construct(
        private readonly AccessScope $access,
        private readonly AllocationService $allocations,
        private readonly HoldService $holds,
    ) {}

    /** The agency's allocations whose outbound flight departs on `date` (local date at the origin). */
    public function flights(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        $day = Carbon::parse($data['date']);

        $allocations = $this->access->allocations(AgencyAllocation::query(), $request->user())
            ->with(['airline', 'cabins', 'fares.fare', 'outboundFlight.origin', 'outboundFlight.destination', 'returnFlight.origin', 'returnFlight.destination'])
            ->whereHas('outboundFlight', fn ($q) => $q
                ->whereBetween('scheduled_departure_at', [$day->copy()->subDay(), $day->copy()->addDays(2)])
                ->whereNotIn('status', ['departed', 'cancelled']))
            ->get()
            ->filter(fn (AgencyAllocation $a) => $a->outboundFlight->scheduled_departure_at
                ->copy()->timezone($a->outboundFlight->origin?->timezone ?? 'UTC')->toDateString() === $data['date'])
            ->sortBy(fn (AgencyAllocation $a) => $a->outboundFlight->scheduled_departure_at)
            ->values();

        return AllocationResource::collection($allocations->map(fn ($a) => (new AllocationResource($a))->withUsage($this->allocations->usage($a))));
    }

    /** ?status=active (default) | all */
    public function index(Request $request): AnonymousResourceCollection
    {
        $holds = AgencyHold::query()
            ->with(['fare.fare', 'creator:id,name', 'allocation.outboundFlight.origin', 'allocation.outboundFlight.destination',
                'allocation.returnFlight.origin', 'allocation.returnFlight.destination'])
            ->when(! $request->user()->isSystemAdmin(), fn ($q) => $q->where('agency_id', $request->user()->agency_id ?? 0))
            ->when($request->input('status', 'active') === 'active', fn ($q) => $q->where('status', AgencyHold::ACTIVE)
                ->whereHas('allocation.outboundFlight', fn ($f) => $f->where('scheduled_departure_at', '>=', now())))
            ->latest('id')
            ->limit(200)
            ->get();

        return AgencyHoldResource::collection($holds->map(fn ($h) => (new AgencyHoldResource($h))->withUsage($this->holds->usage($h))));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'allocation_id' => ['required', 'integer', 'exists:agency_allocations,id'],
            'cabin_class' => ['required', Rule::enum(CabinClass::class)],
            'seats' => ['required', 'integer', 'min:1', 'max:99'],
            'allocation_fare_id' => ['nullable', 'integer'],
        ]);
        $allocation = AgencyAllocation::query()->findOrFail($data['allocation_id']);
        $this->access->ensureAllocation($request->user(), $allocation);

        $hold = $this->holds->create($allocation, CabinClass::from($data['cabin_class']), $data['seats'],
            $data['allocation_fare_id'] ?? null, $request->user(), $this->channel($request));

        return $this->created("{$hold->seats} seat(s) held.", $this->resource($hold));
    }

    public function destroy(Request $request, AgencyHold $hold): JsonResponse
    {
        $this->ensureOwn($request, $hold);
        $this->holds->release($hold, $this->channel($request));

        return $this->success('Hold released. Seats without a name are free again.', $this->resource($hold->refresh()));
    }

    private function resource(AgencyHold $hold): AgencyHoldResource
    {
        $hold->load(['fare.fare', 'allocation.outboundFlight.origin', 'allocation.outboundFlight.destination']);

        return (new AgencyHoldResource($hold))->withUsage($this->holds->usage($hold));
    }

    private function ensureOwn(Request $request, AgencyHold $hold): void
    {
        $user = $request->user();
        if (! $user->isSystemAdmin() && (int) $hold->agency_id !== (int) $user->agency_id) {
            throw new AuthorizationException('This hold belongs to another agency.');
        }
    }

    private function channel(Request $request): ActivityChannel
    {
        return $request->user()->usesPartnerToken() ? ActivityChannel::AgencyApi : ActivityChannel::Agency;
    }
}
