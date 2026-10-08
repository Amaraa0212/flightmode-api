<?php

namespace App\Services\Integrations\Chinggis;

use App\Models\AgencyAllocation;
use App\Models\AgencyAllocationFare;
use App\Models\Aircraft;
use App\Models\Flight;

/**
 * Keeps the Chinggis agent system in sync with the allocations of the agencies listed in
 * `services.chinggis.agency_ids` (legacy: hard-coded agent 119).
 */
class ChinggisSync
{
    public function __construct(private readonly ChinggisClient $client) {}

    public function isSyncedAgency(int $agencyId): bool
    {
        return in_array($agencyId, (array) config('services.chinggis.agency_ids', []), true);
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

    public function pushAllocation(AgencyAllocation $allocation): void
    {
        if (! $this->isSyncedAgency($allocation->agency_id)) {
            return;
        }

        $this->client->post('/api/agent/schedules', ['schedules' => [$this->payload($allocation, active: true)]]);
    }

    public function cancelAllocation(AgencyAllocation $allocation): void
    {
        if (! $this->isSyncedAgency($allocation->agency_id)) {
            return;
        }

        $this->client->post('/api/agent/schedules', ['schedules' => [$this->payload($allocation, active: false)]]);
    }

    /** A flight changed: re-send every synced allocation that uses it. */
    public function flightChanged(Flight $flight): void
    {
        $ids = (array) config('services.chinggis.agency_ids', []);
        if ($ids === []) {
            return;
        }

        AgencyAllocation::query()
            ->whereIn('agency_id', $ids)
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
            'availability' => ['pax' => ['quota' => $allocation->seats]],
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
