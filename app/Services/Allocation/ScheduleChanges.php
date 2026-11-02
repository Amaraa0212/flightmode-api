<?php

namespace App\Services\Allocation;

use App\Models\ActivityLog;
use App\Models\AgencyAllocation;
use App\Models\Airport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Schedule changes of an allocation's flights since the allocation was given to the agency —
 * departure / arrival time, flight number, route — read from the `flight.updated` activity logs.
 */
class ScheduleChanges
{
    /** Flight fields that make a schedule change for the agency */
    public const FIELDS = ['scheduled_departure_at', 'scheduled_arrival_at', 'flight_number', 'origin_airport_id', 'destination_airport_id'];

    /**
     * @param  Collection<int, AgencyAllocation>  $allocations
     * @return array<int, array{unseen: int, changes: list<array<string, mixed>>}> keyed by allocation id
     */
    public function forMany(Collection $allocations): array
    {
        $flightIds = $allocations->flatMap(fn (AgencyAllocation $a) => array_filter([$a->outbound_flight_id, $a->return_flight_id]))->unique()->values();
        if ($flightIds->isEmpty()) {
            return [];
        }

        $logs = ActivityLog::query()
            ->where('event', 'flight.updated')
            ->whereIn('flight_id', $flightIds)
            ->where('created_at', '>=', $allocations->min('created_at') ?? now()->subYear())
            ->orderBy('created_at')
            ->get(['id', 'flight_id', 'description', 'properties', 'created_at'])
            ->map(fn (ActivityLog $log) => [$log, $this->scheduleFields($log)])
            ->filter(fn ($row) => $row[1] !== []);

        $airports = Airport::query()
            ->whereIn('id', $logs->flatMap(fn ($row) => collect($row[1])->only(['origin_airport_id', 'destination_airport_id'])->flatten())->filter()->unique())
            ->pluck('iata_code', 'id');

        $result = [];
        foreach ($allocations as $allocation) {
            $changes = $logs
                ->filter(fn ($row) => in_array($row[0]->flight_id, [$allocation->outbound_flight_id, $allocation->return_flight_id], true)
                    && $row[0]->created_at->gt($allocation->created_at))
                ->map(fn ($row) => [
                    'id' => $row[0]->id,
                    'flight_id' => $row[0]->flight_id,
                    'leg' => $row[0]->flight_id === $allocation->outbound_flight_id ? 'outbound' : 'return',
                    'flight_number' => $row[0]->description,
                    'changed_at' => $row[0]->created_at->toIso8601String(),
                    'seen' => $allocation->schedule_change_seen_at !== null && $row[0]->created_at->lte($allocation->schedule_change_seen_at),
                    'changes' => collect($row[1])->map(fn ($pair, $field) => [
                        'field' => $field,
                        'from' => $this->display($field, $pair[0], $airports),
                        'to' => $this->display($field, $pair[1], $airports),
                    ])->values()->all(),
                ])
                ->values();

            $result[$allocation->id] = [
                'unseen' => $changes->where('seen', false)->count(),
                'changes' => $changes->all(),
            ];
        }

        return $result;
    }

    /** @return array<string, array{0: mixed, 1: mixed}> */
    private function scheduleFields(ActivityLog $log): array
    {
        return collect($log->properties['changes'] ?? [])
            ->only(self::FIELDS)
            ->filter(fn ($pair) => is_array($pair) && $this->normalise($pair[0] ?? null) !== $this->normalise($pair[1] ?? null))
            ->all();
    }

    private function normalise(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $value)) {
            return Carbon::parse($value)->utc()->toIso8601String();
        }

        return (string) $value;
    }

    /** @param  Collection<int, string>  $airports */
    private function display(string $field, mixed $value, Collection $airports): ?string
    {
        if (in_array($field, ['origin_airport_id', 'destination_airport_id'], true)) {
            return $value ? ($airports[$value] ?? (string) $value) : null;
        }

        return $this->normalise($value);
    }
}
