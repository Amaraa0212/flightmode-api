<?php

namespace App\Services\Integrations\Chinggis;

use App\Models\Agency;
use App\Models\AgencyAllocation;
use App\Models\AgencyAllocationFare;
use App\Models\Aircraft;
use App\Models\Flight;
use App\Services\Allocation\AllocationService;

/**
 * Keeps the Chinggis agent system in sync with the allocations of the agencies marked "chinggis_sync"
 * (or listed in `services.chinggis.agency_ids`, legacy: hard-coded agent 119). Each allocation keeps the
 * result of its last push (chinggis_synced_at / chinggis_error).
 */
class ChinggisSync
{
    public function __construct(private readonly ChinggisClient $client) {}

    public function isSyncedAgency(int $agencyId): bool
    {
        return in_array($agencyId, (array) config('services.chinggis.agency_ids', []), true)
            || (bool) Agency::query()->whereKey($agencyId)->value('chinggis_sync');
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function pushAircraft(Aircraft $aircraft): void
    {
        if (! $this->client->isConfigured()) {
            return;
        }

        $aircraft->loadMissing(['airline', 'cabins']);
        $cabins = $aircraft->cabinSeatCounts();

        $this->client->post('/api/agent/aircrafts', [
            'airplaneID' => (string) $aircraft->id,
            'regNumber' => $aircraft->registration,
            'type' => $aircraft->type,
            'totalSeat' => $aircraft->total_seats,
            'airline' => $aircraft->airline->name,
            'codeIATA' => $aircraft->airline->iata_code,
            'numberIATA' => $aircraft->airline->numeric_code,
            'F' => $cabins['F'] ?? 0,
            'C' => $cabins['C'] ?? 0,
            'W' => $cabins['W'] ?? 0,
            'Y' => $cabins['Y'] ?? 0,
        ]);
    }

    /** Sends the allocation and keeps the result on it. False when the agency is not synced or the push failed. */
    public function pushAllocation(AgencyAllocation $allocation): bool
    {
        if (! $this->isSyncedAgency($allocation->agency_id)) {
            return false;
        }

        return $this->record($allocation, $this->client->post('/api/agent/schedules', ['schedules' => [$this->payload($allocation, active: true)]]));
    }

    public function cancelAllocation(AgencyAllocation $allocation): void
    {
        if (! $this->isSyncedAgency($allocation->agency_id)) {
            return;
        }

        $this->client->post('/api/agent/schedules', ['schedules' => [$this->payload($allocation, active: false)]]);
    }

    /** @param array{ok: bool, status: int, body: mixed}|null $response */
    private function record(AgencyAllocation $allocation, ?array $response): bool
    {
        $error = match (true) {
            ! $this->client->isConfigured() => 'Chinggis API is not configured (CHINGGIS_AGENT_API_BASE_URL / _KEY).',
            $response === null => 'Chinggis API did not answer.',
            ! $response['ok'] => 'Chinggis API refused it (HTTP '.$response['status'].').',
            default => null,
        };
        $allocation->forceFill([
            'chinggis_synced_at' => $error ? $allocation->chinggis_synced_at : now(),
            'chinggis_error' => $error,
        ])->saveQuietly();

        return $error === null;
    }

    /** A flight changed: re-send every synced allocation that uses it. */
    public function flightChanged(Flight $flight): void
    {
        $ids = (array) config('services.chinggis.agency_ids', []);

        AgencyAllocation::query()
            ->where(fn ($q) => $q->whereIn('agency_id', $ids)->orWhereHas('agency', fn ($a) => $a->where('chinggis_sync', true)))
            ->where(fn ($q) => $q->where('outbound_flight_id', $flight->id)->orWhere('return_flight_id', $flight->id))
            ->get()
            ->each(fn (AgencyAllocation $a) => $this->pushAllocation($a));
    }

    /** @return array<string, mixed> */
    private function payload(AgencyAllocation $allocation, bool $active): array
    {
        $allocation->loadMissing(['outboundFlight.origin', 'outboundFlight.destination', 'returnFlight.origin', 'returnFlight.destination', 'fares.fare']);
        $route = $allocation->outboundFlight->route();

        return array_filter([
            'id' => $allocation->id,
            'active' => $active ? 'Y' : 'N',
            'deadline' => $allocation->deadline->toDateString(),
            'departure' => $this->flightPayload($allocation->outboundFlight),
            'arrival' => $allocation->returnFlight ? $this->flightPayload($allocation->returnFlight) : null,
            // a pool has no seats of its own: what it has sold and can still sell
            'availability' => ['pax' => ['quota' => $allocation->isPool() ? $this->poolQuota($allocation) : $allocation->seats]],
            'fare_quotas' => $allocation->fares->map(fn (AgencyAllocationFare $bucket) => [
                'id' => $bucket->id,
                'fare_id' => $bucket->fare_id,
                'fare_count' => $bucket->seats,
                'fare' => [
                    'cabin_class' => $bucket->cabin_class->value,
                    'fare_class' => $bucket->fareClass(),
                    'flight_route' => $route,
                    'base_fare' => (float) ($bucket->fare?->base_amount ?? $bucket->fare_amount ?? 0),
                    'tax' => (float) ($bucket->fare?->tax_amount ?? $bucket->tax_amount ?? 0),
                    'cheapest_seat_count' => $bucket->min_fare_seats,
                    'highest_price' => (float) ($bucket->fare_max_amount ?? 0),
                ],
            ])->values()->all(),
        ], fn ($v) => $v !== null);
    }

    private function poolQuota(AgencyAllocation $allocation): int
    {
        $usage = app(AllocationService::class)->usage($allocation);

        return $usage['used'] + $usage['held'] + $usage['remaining'];
    }

    /** @return array<string, mixed> */
    private function flightPayload(Flight $flight): array
    {
        $tz = config('flightmode.timezone');

        return [
            'id' => $flight->id,
            'flight_no' => $flight->flight_number,
            'dep_datetime' => $flight->scheduled_departure_at->copy()->timezone($flight->origin->timezone ?? $tz)->format('Y-m-d H:i:s'),
            'arr_datetime' => $flight->scheduled_arrival_at->copy()->timezone($flight->destination->timezone ?? $tz)->format('Y-m-d H:i:s'),
            'dep_airport' => $flight->origin->iata_code,
            'arr_airport' => $flight->destination->iata_code,
        ];
    }
}
