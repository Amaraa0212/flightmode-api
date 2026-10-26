<?php

namespace App\Services;

use App\Enums\ActivityChannel;
use App\Events\Dcs\FlightUpdated;
use App\Models\ActivityLog;
use App\Models\Flight;
use App\Models\Passenger;
use App\Services\Flight\FlightStatistics;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Writes the audit trail that DCS, gate, admin and online check-in actions leave behind. */
class ActivityLogger
{
    /** @param array<string, mixed> $properties */
    public function log(
        ActivityChannel $channel,
        string $event,
        ?string $description = null,
        ?Model $subject = null,
        Flight|int|null $flight = null,
        Passenger|int|null $passenger = null,
        array $properties = [],
        ?int $userId = null,
    ): ActivityLog {
        $log = ActivityLog::create([
            'channel' => $channel,
            'event' => $event,
            'description' => $description ? mb_substr($description, 0, 500) : null,
            // sign-in events happen before there is an authenticated user
            'user_id' => $userId ?? auth()->id(),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'flight_id' => $flight instanceof Flight ? $flight->id : $flight,
            'passenger_id' => $passenger instanceof Passenger ? $passenger->id : $passenger,
            'properties' => $properties ?: null,
            'ip_address' => request()?->ip(),
        ]);

        if ($log->flight_id) {
            $this->broadcast($log);
        }

        return $log;
    }

    /**
     * Tell the DCS counters of the flight to reload (with the fresh flight figures) — after the transaction commits.
     * A broadcaster that is down must never break check-in, so failures are only reported.
     */
    private function broadcast(ActivityLog $log): void
    {
        $props = $log->properties ?? [];
        $seat = array_key_exists('seat_to', $props)
            ? ['segment_id' => $log->subject_id, 'from' => $props['seat_from'] ?? null, 'to' => $props['seat_to']]
            : null;

        // counters load the flight figures once and then take them from these events
        DB::afterCommit(fn () => rescue(
            fn () => event(new FlightUpdated(
                $log->flight_id, $log->event, $log->passenger_id, $log->user_id, $seat,
                ($flight = Flight::find($log->flight_id)) ? app(FlightStatistics::class)->summary($flight) : null,
                $this->gateAgentIds($log),
            )),
        ));
    }

    /**
     * A flight-level change (status, gate, times, assignment) also goes to the assigned gate agents' flight lists —
     * including agents just taken off the flight, so it drops from their list.
     *
     * @return array<int>
     */
    private function gateAgentIds(ActivityLog $log): array
    {
        if (! str_starts_with($log->event, 'flight.')) {
            return [];
        }

        $current = DB::table('flight_gate_agents')->where('flight_id', $log->flight_id)->pluck('user_id')->all();
        $before = $log->properties['changes']['gate_agents'][0] ?? [];

        return array_values(array_unique(array_map('intval', [...$current, ...$before])));
    }

    /**
     * Log only the attributes that changed, as { field: [old, new] }.
     *
     * @param  array<string, mixed>  $original
     */
    public function logChanges(
        ActivityChannel $channel,
        string $event,
        Model $model,
        array $original,
        ?string $description = null,
        Flight|int|null $flight = null,
        Passenger|int|null $passenger = null,
    ): ?ActivityLog {
        $changes = [];
        foreach ($model->getChanges() as $field => $new) {
            if (in_array($field, ['updated_at', 'created_at'], true)) {
                continue;
            }
            $changes[$field] = [$original[$field] ?? null, $new];
        }

        if ($changes === []) {
            return null;
        }

        return $this->log($channel, $event, $description, $model, $flight, $passenger, ['changes' => $changes]);
    }
}
