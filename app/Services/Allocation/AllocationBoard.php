<?php

namespace App\Services\Allocation;

use App\Enums\CabinClass;
use App\Exceptions\BusinessRuleException;
use App\Models\AgencyAllocation;
use App\Models\Flight;
use App\Models\FlightSeries;
use App\Models\User;
use App\Services\Access\AccessScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Allocations per flight: how much of a flight is given to which agency (by-flight view), handing out a flight's
 * quota to several agencies at once and moving seats from one agency to another.
 */
class AllocationBoard
{
    public function __construct(
        private readonly AllocationService $allocations,
        private readonly AccessScope $access,
    ) {}

    /**
     * Flights departing between $from and $to that have at least one allocation, with their overview.
     *
     * @return list<array<string, mixed>>
     */
    public function board(User $user, Carbon $from, Carbon $to): array
    {
        return $this->access->flights(Flight::query(), $user)
            ->with(['aircraft.cabins', 'origin', 'destination'])
            ->whereBetween('scheduled_departure_at', [$from, $to])
            ->where(fn ($q) => $q->whereHas('outboundAllocations')->orWhereHas('returnAllocations'))
            ->orderBy('scheduled_departure_at')
            ->get()
            ->map(fn (Flight $flight) => $this->overview($flight))
            ->all();
    }

    /** @return array<string, mixed> */
    public function overview(Flight $flight): array
    {
        $flight->loadMissing(['aircraft.cabins', 'origin', 'destination']);
        $capacity = (int) $flight->aircraft?->total_seats;
        $free = $this->allocations->seatsLeftForAllocations($flight);

        $allocations = AgencyAllocation::query()->with(['agency:id,name', 'cabins', 'outboundFlight'])
            ->where(fn ($q) => $q->where('outbound_flight_id', $flight->id)->orWhere('return_flight_id', $flight->id))
            ->orderBy('mode')->orderBy('id')
            ->get();

        $rows = $allocations->map(function (AgencyAllocation $a) {
            $usage = $this->allocations->usage($a);

            return [
                'allocation_id' => $a->id,
                'agency' => ['id' => $a->agency_id, 'name' => $a->agency?->name],
                'mode' => $a->mode,
                'seats' => $a->seats,
                'cabins' => $a->cabins->mapWithKeys(fn ($c) => [$c->cabin_class->value => $c->seats])->all(),
                'used' => $usage['used'],
                'held' => $usage['held'],
                'remaining' => $usage['remaining'],
                'deadline' => $a->deadline->toDateString(),
                'is_waitlist' => $a->is_waitlist,
                'uses_published_fares' => $a->uses_published_fares,
                'released_seats' => $a->released_seats,
                'outbound_flight_id' => $a->outbound_flight_id,
                'return_flight_id' => $a->return_flight_id,
            ];
        })->values();

        $fixed = $rows->where('mode', AgencyAllocation::FIXED);

        return [
            'flight' => [
                'id' => $flight->id,
                'flight_number' => $flight->flight_number,
                'route' => $flight->route(),
                'scheduled_departure_at' => $flight->scheduled_departure_at->toIso8601String(),
                'status' => $flight->status->value,
                'service_type' => $flight->service_type?->value ?? 'scheduled',
                'flight_series_id' => $flight->flight_series_id,
            ],
            'capacity' => $capacity,
            'cabins' => $flight->aircraft?->cabins->map(fn ($c) => [
                'cabin_class' => $c->cabin_class->value,
                'seats' => (int) $c->seat_count,
                'free' => $this->allocations->cabinSeatsLeftForAllocations($flight, $c->cabin_class),
            ])->values()->all() ?? [],
            'fixed_seats' => (int) $fixed->sum('seats'),
            'fixed_sold' => (int) $fixed->sum('used'),
            // seats nobody has: not in a fixed allocation, not sold, not held by a pool agency
            'free' => $free,
            'given_percent' => $capacity ? (int) round(100 * ($capacity - $free) / $capacity) : 0,
            'allocations' => $rows->all(),
        ];
    }

    /**
     * Sets a flight's allocations in one go. Rows with `allocation_id` change that allocation, the others are new;
     * `remove` deletes allocations. Seats given back are freed before new ones are taken, so moving quota between
     * agencies in the same request works.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<int>  $remove
     */
    public function distribute(Flight $flight, array $rows, array $remove, ?string $deadline = null): void
    {
        DB::transaction(function () use ($flight, $rows, $remove, $deadline) {
            $existing = AgencyAllocation::query()->with(['cabins', 'agency:id,name'])
                ->where(fn ($q) => $q->where('outbound_flight_id', $flight->id)->orWhere('return_flight_id', $flight->id))
                ->get()->keyBy('id');

            foreach ($remove as $id) {
                $allocation = $existing->get($id) ?? throw BusinessRuleException::make('allocation_not_on_flight', 'An allocation to remove is not on this flight.');
                $this->labelled($allocation->agency?->name, fn () => $this->allocations->delete($allocation));
            }

            // smaller first: seats given back make room for the bigger ones
            $ordered = collect($rows)->sortBy(function (array $row) use ($existing) {
                $current = isset($row['allocation_id']) ? (int) $existing->get($row['allocation_id'])?->seats : 0;

                return ($row['mode'] ?? AgencyAllocation::FIXED) === AgencyAllocation::POOL ? -1 : (int) $row['seats'] - $current;
            });

            foreach ($ordered as $row) {
                $allocation = isset($row['allocation_id']) ? $existing->get($row['allocation_id']) : null;
                if (isset($row['allocation_id']) && ! $allocation) {
                    throw BusinessRuleException::make('allocation_not_on_flight', 'An allocation to change is not on this flight.');
                }
                $agencyName = $allocation?->agency?->name ?? DB::table('agencies')->where('id', $row['agency_id'])->value('name');

                $this->labelled($agencyName, fn () => $this->allocations->save([
                    'airline_id' => $flight->airline_id,
                    'agency_id' => $row['agency_id'],
                    'outbound_flight_id' => $allocation?->outbound_flight_id ?? $flight->id,
                    'return_flight_id' => $allocation?->return_flight_id,
                    'mode' => $row['mode'] ?? AgencyAllocation::FIXED,
                    'seats' => (int) $row['seats'],
                    'deadline' => $row['deadline'] ?? $allocation?->deadline?->toDateString() ?? $deadline
                        ?? $flight->departureAt()->subDay()->toDateString(),
                    'is_waitlist' => $allocation?->is_waitlist ?? false,
                    'uses_published_fares' => (bool) ($row['uses_published_fares'] ?? $allocation?->uses_published_fares ?? false),
                    // fixed without cabins: all in economy; pool without cabins: every cabin of the aircraft
                    'cabins' => $row['cabins'] ?? (($row['mode'] ?? AgencyAllocation::FIXED) === AgencyAllocation::POOL ? [] : ['Y' => (int) $row['seats']]),
                ], $allocation));
            }
        });
    }

    /**
     * The same distribution on every flight of a series between two dates: each row updates the agency's allocation on
     * a flight or creates it (other agencies are left as they are). A flight that cannot take it is skipped and reported.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{flights: int, saved: int, failed: array<int, array{flight_id: int, flight_number: string, date: string, message: string}>}
     */
    public function distributeSeries(FlightSeries $series, string $from, string $to, array $rows, int $deadlineDaysBefore): array
    {
        $flights = $series->flights()
            ->whereBetween('scheduled_departure_at', [Carbon::parse($from)->subDay(), Carbon::parse($to)->addDays(2)])
            ->whereNotIn('status', ['departed', 'cancelled'])
            ->where('scheduled_departure_at', '>', now())
            ->with('origin')
            ->orderBy('scheduled_departure_at')
            ->get()
            // local departure date inside the range
            ->filter(fn (Flight $f) => ($day = $this->localDay($f)) >= $from && $day <= $to)
            ->values();

        $saved = 0;
        $failed = [];
        foreach ($flights as $flight) {
            $existing = AgencyAllocation::query()->where('outbound_flight_id', $flight->id)
                ->whereIn('agency_id', array_column($rows, 'agency_id'))->pluck('id', 'agency_id');
            $deadline = Carbon::parse($this->localDay($flight))->subDays($deadlineDaysBefore)->toDateString();

            try {
                $this->distribute($flight, array_map(fn (array $row) => $row + [
                    'allocation_id' => $existing[$row['agency_id']] ?? null,
                    'deadline' => $deadline,
                ], $rows), [], $deadline);
                $saved++;
            } catch (BusinessRuleException $e) {
                $failed[] = ['flight_id' => $flight->id, 'flight_number' => $flight->flight_number, 'date' => $this->localDay($flight), 'message' => $e->getMessage()];
            }
        }

        return ['flights' => $flights->count(), 'saved' => $saved, 'failed' => $failed];
    }

    private function localDay(Flight $flight): string
    {
        return $flight->scheduled_departure_at->copy()->timezone($flight->origin?->timezone ?: config('flightmode.timezone'))->toDateString();
    }

    /** Moves unsold seats of one cabin from a fixed allocation to another on the same flight. */
    public function move(AgencyAllocation $from, AgencyAllocation $to, CabinClass $cabin, int $seats): void
    {
        DB::transaction(function () use ($from, $to, $cabin, $seats) {
            foreach ([$from, $to] as $allocation) {
                if ($allocation->isPool()) {
                    throw BusinessRuleException::make('pool_has_no_seats', 'A pool allocation has no seats of its own to move.');
                }
            }
            if ($from->outbound_flight_id !== $to->outbound_flight_id || $from->id === $to->id) {
                throw BusinessRuleException::make('not_same_flight', 'Seats move between two allocations of the same flight.');
            }

            $from->loadMissing('cabins');
            $to->loadMissing('cabins');
            $unsold = $this->allocations->freeToTake($from, $cabin);
            if ($seats > $unsold) {
                throw BusinessRuleException::make('not_enough_unsold', "Only {$unsold} unsold seat(s) of cabin {$cabin->value} can move.");
            }

            $cabins = fn (AgencyAllocation $a, int $delta) => array_merge(
                $a->cabins->mapWithKeys(fn ($c) => [$c->cabin_class->value => $c->seats])->all(),
                [$cabin->value => (int) $a->cabins->firstWhere('cabin_class', $cabin)?->seats + $delta],
            );
            $data = fn (AgencyAllocation $a, int $delta) => [
                'airline_id' => $a->airline_id,
                'agency_id' => $a->agency_id,
                'outbound_flight_id' => $a->outbound_flight_id,
                'return_flight_id' => $a->return_flight_id,
                'mode' => AgencyAllocation::FIXED,
                'seats' => $a->seats + $delta,
                'deadline' => $a->deadline->toDateString(),
                'is_waitlist' => $a->is_waitlist,
                'uses_published_fares' => $a->uses_published_fares,
                'released_seats' => $a->released_seats,
                'cabins' => $cabins($a, $delta),
            ];

            // give back first, so the seats are free for the other agency
            $this->allocations->save($data($from, -$seats), $from);
            $this->allocations->save($data($to, $seats), $to);
        });
    }

    /** Prefixes a business rule error with the agency it is about (several agencies are saved in one request). */
    private function labelled(?string $agency, callable $run): mixed
    {
        try {
            return $run();
        } catch (BusinessRuleException $e) {
            throw BusinessRuleException::make($e->errorCode, ($agency ? "{$agency}: " : '').$e->getMessage(), $e->context, $e->status);
        }
    }
}
