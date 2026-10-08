<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ActivityChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PassengerBanRequest;
use App\Http\Resources\PassengerBanResource;
use App\Models\PassengerBan;
use App\Services\Access\AccessScope;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Ban list used by DCS check-in screening (legacy "Ban Type / Ban Description"). */
class PassengerBanController extends Controller
{
    public function __construct(
        private readonly AccessScope $access,
        private readonly ActivityLogger $logger,
    ) {}

    /** ?search=name|document&active=1 */
    public function index(Request $request): AnonymousResourceCollection
    {
        $term = trim((string) $request->input('search'));

        $bans = $this->visible($request)
            ->with(['airline', 'creator'])
            ->when($term !== '', fn ($q) => $q->where(fn ($x) => $x
                ->where('last_name', 'like', "%{$term}%")->orWhere('first_name', 'like', "%{$term}%")
                ->orWhere('document_number', $term)))
            ->when($request->filled('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->latest('id')
            ->paginate($request->integer('per_page', 50));

        return PassengerBanResource::collection($bans);
    }

    public function show(Request $request, PassengerBan $passengerBan): PassengerBanResource
    {
        $this->ensureVisible($request, $passengerBan);

        return new PassengerBanResource($passengerBan->load(['airline', 'creator']));
    }

    public function store(PassengerBanRequest $request): JsonResponse
    {
        $data = $request->validated();
        // Airline users may only ban for their own airline; system admins may leave it empty (= every airline)
        $data['airline_id'] = $request->user()->isSystemAdmin()
            ? ($data['airline_id'] ?? null)
            : $this->access->airlineIdForWrite($request->user(), $data['airline_id'] ?? null);

        $ban = PassengerBan::create($this->normalise($data) + ['created_by' => $request->user()->id]);
        $this->logger->log(ActivityChannel::Admin, 'passenger_ban.created', "{$ban->last_name}/{$ban->first_name} ({$ban->ban_type->value})", $ban);

        return $this->created('Ban added.', new PassengerBanResource($ban->load(['airline', 'creator'])));
    }

    public function update(PassengerBanRequest $request, PassengerBan $passengerBan): JsonResponse
    {
        $this->ensureVisible($request, $passengerBan);
        $original = $passengerBan->getOriginal();
        $passengerBan->update($this->normalise($request->safe()->except('airline_id')));
        $this->logger->logChanges(ActivityChannel::Admin, 'passenger_ban.updated', $passengerBan, $original);

        return $this->success('Ban updated.', new PassengerBanResource($passengerBan->load(['airline', 'creator'])));
    }

    public function destroy(Request $request, PassengerBan $passengerBan): JsonResponse
    {
        $this->ensureVisible($request, $passengerBan);
        $passengerBan->delete();
        $this->logger->log(ActivityChannel::Admin, 'passenger_ban.deleted', "{$passengerBan->last_name}/{$passengerBan->first_name}");

        return $this->deleted('Ban removed.');
    }

    private function visible(Request $request)
    {
        $user = $request->user();

        return $user->isSystemAdmin()
            ? PassengerBan::query()
            : PassengerBan::query()->where('airline_id', $user->airline_id ?? 0);
    }

    private function ensureVisible(Request $request, PassengerBan $ban): void
    {
        abort_unless($this->visible($request)->whereKey($ban->id)->exists(), 404);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function normalise(array $data): array
    {
        foreach (['last_name', 'first_name', 'document_number', 'nationality'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = mb_strtoupper(trim($data[$field]));
            }
        }

        return $data;
    }
}
