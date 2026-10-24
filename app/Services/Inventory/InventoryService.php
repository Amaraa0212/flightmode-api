<?php

namespace App\Services\Inventory;

use App\Enums\ActivityChannel;
use App\Enums\CabinClass;
use App\Enums\FlightStatus;
use App\Enums\SegmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\AgencyAllocation;
use App\Models\Flight;
use App\Models\InventoryTemplate;
use App\Models\PassengerSegment;
use App\Models\Rbd;
use App\Services\ActivityLogger;
use App\Services\Allocation\AllocationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Seat inventory per flight: seats per cabin and a nested booking limit per RBD (legacy ameliaRES "Seat Allocations").
 *
 * A flight is inventory-controlled once it has cabin rows; until then bookings are only limited by the cabin
 * and agency allocations, as before. RBD availability is nested along rbds.parent_id inside a cabin:
 *
 *   cabin available = seats + oversell - curtail - sold - unsold agency block seats
 *   subtree sold    = sold in this RBD + subtree sold of its children
 *   own             = closed ? 0 : max(0, limit - subtree sold)
 *   available       = min(own, available of the parent)   (a root's parent is the cabin)
 *
 * Agency allocation passengers count as sold, but are not limited by RBD limits (their seats are already blocked).
 */
class InventoryService
{
    public function __construct(
        private readonly AllocationService $allocations,
        private readonly ActivityLogger $logger,
    ) {}

    public function isControlled(Flight $flight): bool
    {
        return $flight->cabinInventory()->exists();
    }

    /**
     * Everything the seat allocation screen shows for one flight.
     *
     * @return array{is_controlled: bool, cabins: array<int, array<string, mixed>>, agencies: array<int, array<string, mixed>>,
     *               rbds: array<int, array<string, mixed>>, warnings: array<int, string>}
     */
    public function availability(Flight $flight, ?int $exceptPassengerId = null): array
    {
        $flight->load(['aircraft.cabins', 'cabinInventory', 'rbdInventory']);
        $controlled = $flight->cabinInventory->isNotEmpty();
        $sold = $this->soldCounts($flight, $exceptPassengerId);
        $agencies = $this->agencyBlocks($flight);

        $cabinCodes = collect($flight->aircraft?->cabins->pluck('cabin_class') ?? [])
            ->merge($flight->cabinInventory->pluck('cabin_class'))
            ->map(fn (CabinClass $c) => $c->value)->unique()
            ->sortBy(fn ($c) => array_search($c, CabinClass::values(), true))->values();

        $cabins = [];
        foreach ($cabinCodes as $code) {
            $row = $flight->cabinInventory->first(fn ($r) => $r->cabin_class->value === $code);
            $aircraftSeats = (int) $flight->aircraft?->cabins->first(fn ($c) => $c->cabin_class->value === $code)?->seat_count;
            $capacity = $controlled ? (int) $row?->capacity() : $aircraftSeats;
            $soldInCabin = array_sum($sold['by_cabin'][$code] ?? []);
            $blocked = (int) collect($agencies)->where('cabin_class', $code)->sum('available');

            $cabins[] = [
                'cabin_class' => $code,
                'aircraft_seats' => $aircraftSeats,
                'is_set' => (bool) $row,
                'seats' => $row?->seats,
                'oversell' => (int) $row?->oversell,
                'curtail' => (int) $row?->curtail,
                'sold' => $soldInCabin,
                'infants' => $sold['infants'][$code] ?? 0,
                'agency_unsold' => $blocked,
                // seats still free to give to agency allocations (AllocationService checks the same); negative = over-allocated
                'free_for_allocation' => $this->allocations->cabinAllocationRoom($flight, CabinClass::from($code)),
                'available' => max(0, $capacity - $soldInCabin - $blocked),
            ];
        }

        $rbds = $this->rbdRows($flight, collect($cabins)->keyBy('cabin_class'), $sold['by_cabin'], $controlled);

        return [
            'is_controlled' => $controlled,
            'cabins' => $cabins,
            'agencies' => $agencies,
            'rbds' => $rbds,
            'warnings' => $this->warnings($cabins, $rbds),
        ];
    }

    /**
     * Seats of an RBD (or of the cabin, when the RBD is unknown) still for sale.
     * Null when the flight is not inventory-controlled.
     */
    public function available(Flight $flight, CabinClass $cabin, ?string $rbdCode, ?int $exceptPassengerId = null): ?int
    {
        $data = $this->availability($flight, $exceptPassengerId);
        if (! $data['is_controlled']) {
            return null;
        }

        $row = $rbdCode ? collect($data['rbds'])->firstWhere('code', $rbdCode) : null;

        return $row ? $row['available'] : (int) collect($data['cabins'])->firstWhere('cabin_class', $cabin->value)['available'];
    }

    /** Throws when the flight cannot sell $count more seat(s) of the RBD. Locks the flight row; call inside a transaction. */
    public function assertCanSell(Flight $flight, CabinClass $cabin, ?string $rbdCode, int $count = 1, ?int $exceptPassengerId = null): void
    {
        Flight::query()->whereKey($flight->id)->lockForUpdate()->first();

        $available = $this->available($flight, $cabin, $rbdCode, $exceptPassengerId);
        if ($available !== null && $available < $count) {
            $what = $rbdCode ? "class {$rbdCode}" : "cabin {$cabin->value}";
            throw BusinessRuleException::make('rbd_sold_out', "Flight {$flight->flight_number}: {$what} is sold out ({$available} left).");
        }
    }

    /**
     * Replace the cabin rows of a flight.
     *
     * @param  array<int, array{cabin_class: string, seats: int, oversell?: int, curtail?: int}>  $rows
     */
    public function saveCabins(Flight $flight, array $rows): array
    {
        return DB::transaction(function () use ($flight, $rows) {
            $this->writeCabins($flight, $rows);

            // a smaller cabin pulls every class limit above it down (min() keeps the nesting valid)
            foreach ($flight->cabinInventory()->get() as $cabin) {
                $flight->rbdInventory()
                    ->whereHas('rbd', fn ($q) => $q->where('cabin_class', $cabin->cabin_class->value))
                    ->where('seats', '>', $cabin->seats + $cabin->oversell)
                    ->update(['seats' => $cabin->seats + $cabin->oversell, 'updated_by' => auth()->id()]);
            }

            $this->assertValid($flight);
            $this->log($flight, 'inventory.cabins_saved', collect($rows)->map(fn ($r) => "{$r['cabin_class']} {$r['seats']}")->implode(', '));

            return $this->availability($flight);
        });
    }

    /**
     * Upsert RBD limits; RBDs not in $rows keep their row.
     *
     * @param  array<int, array{rbd_id: int, seats: int, is_closed?: bool}>  $rows
     */
    public function saveRbds(Flight $flight, array $rows): array
    {
        return DB::transaction(function () use ($flight, $rows) {
            if (! $this->isControlled($flight)) {
                throw BusinessRuleException::make('inventory_not_initialized', 'Set the cabin seats of this flight first.');
            }

            $this->writeRbds($flight, $rows);
            $this->assertValid($flight);
            $codes = Rbd::query()->whereIn('id', array_column($rows, 'rbd_id'))->pluck('code', 'id');
            $this->log($flight, 'inventory.rbds_saved', collect($rows)
                ->map(fn ($r) => ($codes[$r['rbd_id']] ?? $r['rbd_id']).' '.$r['seats'].(! empty($r['is_closed']) ? ' closed' : ''))->implode(', '));

            return $this->availability($flight);
        });
    }

    public function deleteRbd(Flight $flight, Rbd $rbd): array
    {
        return DB::transaction(function () use ($flight, $rbd) {
            $flight->rbdInventory()->where('rbd_id', $rbd->id)->delete();
            $this->log($flight, 'inventory.rbd_deleted', $rbd->code);

            return $this->availability($flight);
        });
    }

    /** Remove all inventory rows: the flight sells without RBD limits again. */
    public function reset(Flight $flight): void
    {
        DB::transaction(function () use ($flight) {
            $flight->rbdInventory()->delete();
            $flight->cabinInventory()->delete();
            $this->log($flight, 'inventory.reset', null);
        });
    }

    /** Create the inventory from a template, or from the aircraft cabins with every active RBD fully open. */
    public function initialize(Flight $flight, ?InventoryTemplate $template = null, bool $overwrite = false): array
    {
        return DB::transaction(function () use ($flight, $template, $overwrite) {
            if (! $overwrite && $this->isControlled($flight)) {
                throw BusinessRuleException::make('inventory_exists', 'This flight already has an inventory.');
            }

            $snapshot = $template ? $this->templateSnapshot($template, $flight) : $this->defaultSnapshot($flight);
            $this->writeSnapshot($flight, $snapshot);
            $this->log($flight, 'inventory.initialized', $template ? "Template {$template->name}" : 'Aircraft cabins');

            return $this->availability($flight);
        });
    }

    /**
     * Copy the inventory of a flight to the later flights with the same number.
     *
     * @param  array{date_to: string, days_of_week?: ?array<int>, overwrite?: bool}  $options
     * @return array<int, array<string, mixed>>
     */
    public function rollForward(Flight $source, array $options): array
    {
        if (! $this->isControlled($source)) {
            throw BusinessRuleException::make('inventory_not_initialized', 'This flight has no inventory to copy.');
        }

        $snapshot = $this->flightSnapshot($source);
        $timezone = $source->origin?->timezone ?? 'UTC';
        $targets = Flight::query()->with('origin')
            ->where('airline_id', $source->airline_id)
            ->where('flight_number', $source->flight_number)
            ->where('origin_airport_id', $source->origin_airport_id)
            ->where('destination_airport_id', $source->destination_airport_id)
            ->where('scheduled_departure_at', '>', $source->scheduled_departure_at)
            ->where('scheduled_departure_at', '<=', Carbon::parse($options['date_to'], $timezone)->endOfDay()->utc())
            ->whereNotIn('status', [FlightStatus::Departed, FlightStatus::Cancelled])
            ->orderBy('scheduled_departure_at')
            ->get()
            ->when(! empty($options['days_of_week']), fn (Collection $flights) => $flights->filter(
                fn (Flight $f) => in_array($f->scheduled_departure_at->copy()->timezone($timezone)->dayOfWeekIso, $options['days_of_week'], false)));

        $results = $targets->map(fn (Flight $target) => $this->applySnapshot($target, $snapshot, (bool) ($options['overwrite'] ?? false)))->values()->all();
        $applied = collect($results)->where('status', 'applied')->count();
        $this->log($source, 'inventory.rolled_forward', "To {$options['date_to']}: {$applied}/".count($results).' flight(s)');

        return $results;
    }

    /**
     * @param  array<int>  $flightIds
     * @return array<int, array<string, mixed>>
     */
    public function applyTemplate(InventoryTemplate $template, array $flightIds, bool $overwrite = false): array
    {
        $template->loadMissing(['cabins', 'rbds']);

        return Flight::query()->with('origin')->whereIn('id', $flightIds)->orderBy('scheduled_departure_at')->get()
            ->map(function (Flight $flight) use ($template, $overwrite) {
                if ($flight->airline_id !== $template->airline_id) {
                    return $this->result($flight, 'skipped', 'The template belongs to another airline.');
                }

                $result = $this->applySnapshot($flight, $this->templateSnapshot($template, $flight), $overwrite);
                if ($result['status'] === 'applied') {
                    $this->log($flight, 'inventory.initialized', "Template {$template->name}");
                }

                return $result;
            })->values()->all();
    }

    /**
     * Save a template with its cabins and RBD limits (replaces both lists).
     *
     * @param  array<int, array<string, mixed>>  $cabins
     * @param  array<int, array<string, mixed>>  $rbds
     */
    public function syncTemplate(InventoryTemplate $template, array $cabins, array $rbds): void
    {
        $template->cabins()->delete();
        foreach ($cabins as $row) {
            $template->cabins()->create([
                'cabin_class' => $row['cabin_class'],
                'seats' => $row['seats'],
                'oversell' => $row['oversell'] ?? 0,
                'curtail' => $row['curtail'] ?? 0,
            ]);
        }

        $template->rbds()->delete();
        foreach ($rbds as $row) {
            $template->rbds()->create(['rbd_id' => $row['rbd_id'], 'seats' => $row['seats'], 'is_closed' => $row['is_closed'] ?? false]);
        }
    }

    /** New template holding the current inventory of a flight. */
    public function templateFromFlight(Flight $flight, string $name, ?string $description = null): InventoryTemplate
    {
        return DB::transaction(function () use ($flight, $name, $description) {
            if (! $this->isControlled($flight)) {
                throw BusinessRuleException::make('inventory_not_initialized', 'This flight has no inventory to save.');
            }
            if (InventoryTemplate::query()->where('airline_id', $flight->airline_id)->where('name', $name)->exists()) {
                throw BusinessRuleException::make('template_exists', 'A template with this name already exists.');
            }

            $template = InventoryTemplate::create([
                'airline_id' => $flight->airline_id,
                'aircraft_id' => $flight->aircraft_id,
                'name' => $name,
                'description' => $description,
                'created_by' => auth()->id(),
            ]);
            $snapshot = $this->flightSnapshot($flight);
            $this->syncTemplate($template, $snapshot['cabins'], $snapshot['rbds']);

            return $template->load(['cabins', 'rbds.rbd']);
        });
    }

    /**
     * Sold seats per cabin and RBD letter (non-infant passengers holding a place), plus infants per cabin.
     *
     * @return array{by_cabin: array<string, array<string, int>>, infants: array<string, int>}
     */
    private function soldCounts(Flight $flight, ?int $exceptPassengerId): array
    {
        $rows = PassengerSegment::query()
            ->join('passengers', 'passengers.id', '=', 'passenger_segments.passenger_id')
            ->whereNull('passengers.deleted_at')
            ->where('passenger_segments.flight_id', $flight->id)
            ->whereNotIn('passenger_segments.status', [SegmentStatus::Cancelled->value, SegmentStatus::Waitlisted->value])
            ->when($exceptPassengerId, fn ($q) => $q->where('passenger_segments.passenger_id', '!=', $exceptPassengerId))
            ->selectRaw('passenger_segments.cabin_class, passenger_segments.fare_class, passengers.infant_of_passenger_id is not null as is_infant, count(*) as total')
            ->groupBy('passenger_segments.cabin_class', 'passenger_segments.fare_class', 'is_infant')
            ->get();

        $byCabin = $infants = [];
        foreach ($rows as $row) {
            $cabin = $row->cabin_class instanceof CabinClass ? $row->cabin_class->value : (string) $row->cabin_class;
            if ($row->is_infant) {
                $infants[$cabin] = ($infants[$cabin] ?? 0) + (int) $row->total;

                continue;
            }
            $key = (string) ($row->fare_class ?? '');
            $byCabin[$cabin][$key] = ($byCabin[$cabin][$key] ?? 0) + (int) $row->total;
        }

        return ['by_cabin' => $byCabin, 'infants' => $infants];
    }

    /** @return array<int, array<string, mixed>> agency allocation seats per cabin on this flight */
    private function agencyBlocks(Flight $flight): array
    {
        $allocations = AgencyAllocation::query()->with(['cabins', 'agency:id,name'])
            ->where(fn ($q) => $q->where('outbound_flight_id', $flight->id)->orWhere('return_flight_id', $flight->id))
            ->orderBy('id')->get();

        $rows = [];
        foreach ($allocations as $allocation) {
            foreach ($allocation->cabins as $cabin) {
                $sold = $this->allocations->usedSeats($allocation, $cabin->cabin_class);
                $rows[] = [
                    'allocation_id' => $allocation->id,
                    'agency_id' => $allocation->agency_id,
                    'agency' => $allocation->agency?->name,
                    'cabin_class' => $cabin->cabin_class->value,
                    'seats' => $cabin->seats,
                    'sold' => $sold,
                    'available' => max(0, $cabin->seats - $sold),
                    'is_waitlist' => $allocation->is_waitlist,
                    // for editing the allocation from the seat allocation screen
                    'allocation_seats' => $allocation->seats,
                    'outbound_flight_id' => $allocation->outbound_flight_id,
                    'return_flight_id' => $allocation->return_flight_id,
                    'deadline' => $allocation->deadline?->toDateString(),
                    'uses_fare_quotas' => $allocation->uses_fare_quotas,
                ];
            }
        }

        return $rows;
    }

    /**
     * RBD rows in tree order (parents first, children by rank) with nested availability.
     *
     * @param  Collection<string, array<string, mixed>>  $cabins
     * @param  array<string, array<string, int>>  $soldByCabin
     * @return array<int, array<string, mixed>>
     */
    private function rbdRows(Flight $flight, Collection $cabins, array $soldByCabin, bool $controlled): array
    {
        $limits = $flight->rbdInventory->keyBy('rbd_id');
        $rbds = Rbd::query()->where('airline_id', $flight->airline_id)
            ->orderBy('rank')->orderBy('code')->get()
            // inactive RBDs stay in the tree (they sell nothing, and so close every class below them)
            ->filter(fn (Rbd $r) => $cabins->has($r->cabin_class->value))
            ->keyBy('id');

        // a parent outside the list or in another cabin makes the RBD a root
        $parentOf = fn (Rbd $r) => $r->parent_id && $rbds->has($r->parent_id) && $rbds[$r->parent_id]->cabin_class === $r->cabin_class
            ? $r->parent_id : null;
        $children = $rbds->groupBy(fn (Rbd $r) => $parentOf($r) ?? 0);

        $subtreeSold = [];
        $sumSold = function (Rbd $r) use (&$sumSold, &$subtreeSold, $children, $soldByCabin): int {
            $total = $soldByCabin[$r->cabin_class->value][$r->code] ?? 0;
            foreach ($children->get($r->id, collect()) as $child) {
                $total += $sumSold($child);
            }

            return $subtreeSold[$r->id] = $total;
        };

        $rows = [];
        $visit = function (Rbd $r, int $level, int $parentAvailable) use (&$visit, &$rows, &$subtreeSold, $children, $limits, $soldByCabin, $controlled, $rbds) {
            $limit = $limits->get($r->id);
            $own = match (true) {
                ! $r->is_active => 0,
                ! $controlled => PHP_INT_MAX,
                ! $limit || $limit->is_closed => 0,
                default => max(0, $limit->seats - $subtreeSold[$r->id]),
            };
            $available = min($own, $parentAvailable);

            $rows[] = [
                'rbd_id' => $r->id,
                'code' => $r->code,
                'cabin_class' => $r->cabin_class->value,
                'rank' => $r->rank,
                'parent_id' => $r->parent_id,
                'parent_code' => $r->parent_id ? $rbds->get($r->parent_id)?->code : null,
                'level' => $level,
                'is_active' => $r->is_active,
                'is_set' => (bool) $limit,
                'seats' => $limit?->seats,
                'is_closed' => (bool) $limit?->is_closed,
                'sold' => $soldByCabin[$r->cabin_class->value][$r->code] ?? 0,
                'subtree_sold' => $subtreeSold[$r->id],
                'available' => $available,
            ];

            foreach ($children->get($r->id, collect()) as $child) {
                $visit($child, $level + 1, $available);
            }
        };

        foreach ($cabins as $code => $cabin) {
            $roots = $children->get(0, collect())->filter(fn (Rbd $r) => $r->cabin_class->value === $code);
            foreach ($roots as $root) {
                $sumSold($root);
                $visit($root, 0, $cabin['available']);
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string, mixed>>  $cabins
     * @param  array<int, array<string, mixed>>  $rbds
     * @return array<int, string>
     */
    private function warnings(array $cabins, array $rbds): array
    {
        $warnings = [];
        foreach ($cabins as $cabin) {
            if ($cabin['is_set'] && $cabin['sold'] > $cabin['seats'] + $cabin['oversell'] - $cabin['curtail']) {
                $warnings[] = "Cabin {$cabin['cabin_class']}: {$cabin['sold']} sold is more than the seats for sale.";
            }
        }
        foreach ($rbds as $rbd) {
            if ($rbd['is_set'] && $rbd['seats'] < $rbd['subtree_sold']) {
                $warnings[] = "Class {$rbd['code']}: limit {$rbd['seats']} is below the {$rbd['subtree_sold']} already sold in it and below it.";
            }
        }

        return $warnings;
    }

    /** Limits must nest: an RBD not above its nearest limited ancestor, a root not above its cabin, cabins not above the aircraft. */
    private function assertValid(Flight $flight): void
    {
        $flight->load(['aircraft', 'cabinInventory', 'rbdInventory.rbd']);
        $cabins = $flight->cabinInventory->keyBy(fn ($c) => $c->cabin_class->value);

        $total = $cabins->sum('seats');
        if ($flight->aircraft && $total > $flight->aircraft->total_seats) {
            throw BusinessRuleException::make('cabins_exceed_aircraft',
                "Cabin seats ({$total}) are more than the aircraft has ({$flight->aircraft->total_seats}).");
        }
        foreach ($cabins as $cabin) {
            if ($cabin->curtail > $cabin->seats) {
                throw BusinessRuleException::make('curtail_exceeds_seats', "Cabin {$cabin->cabin_class->value}: curtail is more than the seats.");
            }
        }

        $limits = $flight->rbdInventory->keyBy('rbd_id');
        $rbds = Rbd::query()->where('airline_id', $flight->airline_id)->get()->keyBy('id');

        foreach ($flight->rbdInventory as $limit) {
            $rbd = $limit->rbd;
            $cabin = $cabins->get($rbd->cabin_class->value);
            if (! $cabin) {
                throw BusinessRuleException::make('rbd_cabin_missing', "Class {$rbd->code}: cabin {$rbd->cabin_class->value} has no seats on this flight.");
            }

            $ancestor = $rbd->parent_id ? $rbds->get($rbd->parent_id) : null;
            while ($ancestor && $ancestor->cabin_class === $rbd->cabin_class && ! $limits->has($ancestor->id)) {
                $ancestor = $ancestor->parent_id ? $rbds->get($ancestor->parent_id) : null;
            }

            if ($ancestor && $ancestor->cabin_class === $rbd->cabin_class) {
                $parentSeats = $limits[$ancestor->id]->seats;
                if ($limit->seats > $parentSeats) {
                    throw BusinessRuleException::make('rbd_exceeds_parent',
                        "Class {$rbd->code}: {$limit->seats} is more than its parent {$ancestor->code} ({$parentSeats}).");
                }
            } elseif ($limit->seats > $cabin->seats + $cabin->oversell) {
                throw BusinessRuleException::make('rbd_exceeds_cabin',
                    "Class {$rbd->code}: {$limit->seats} is more than cabin {$rbd->cabin_class->value} (".($cabin->seats + $cabin->oversell).').');
            }
        }
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function writeCabins(Flight $flight, array $rows): void
    {
        $keep = [];
        foreach ($rows as $row) {
            $flight->cabinInventory()->updateOrCreate(['cabin_class' => $row['cabin_class']], [
                'seats' => $row['seats'],
                'oversell' => $row['oversell'] ?? 0,
                'curtail' => $row['curtail'] ?? 0,
                'updated_by' => auth()->id(),
            ]);
            $keep[] = $row['cabin_class'];
        }
        $flight->cabinInventory()->whereNotIn('cabin_class', $keep)->delete();
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function writeRbds(Flight $flight, array $rows): void
    {
        $owned = Rbd::query()->where('airline_id', $flight->airline_id)->whereIn('id', array_column($rows, 'rbd_id'))->pluck('id')->all();
        foreach ($rows as $row) {
            if (! in_array($row['rbd_id'], $owned, false)) {
                throw BusinessRuleException::make('rbd_other_airline', 'One of the classes belongs to another airline.');
            }
            $flight->rbdInventory()->updateOrCreate(['rbd_id' => $row['rbd_id']], [
                'seats' => $row['seats'],
                'is_closed' => $row['is_closed'] ?? false,
                'updated_by' => auth()->id(),
            ]);
        }
    }

    /** @param array{cabins: array<int, array<string, mixed>>, rbds: array<int, array<string, mixed>>} $snapshot */
    private function writeSnapshot(Flight $flight, array $snapshot): void
    {
        if ($snapshot['cabins'] === []) {
            throw BusinessRuleException::make('no_cabins', 'There are no cabin seats to set on this flight.');
        }

        $flight->rbdInventory()->delete();
        $this->writeCabins($flight, $snapshot['cabins']);
        $this->writeRbds($flight, $snapshot['rbds']);
        $this->assertValid($flight);
    }

    /** @param array{cabins: array<int, array<string, mixed>>, rbds: array<int, array<string, mixed>>} $snapshot */
    private function applySnapshot(Flight $target, array $snapshot, bool $overwrite): array
    {
        if (! $overwrite && $this->isControlled($target)) {
            return $this->result($target, 'skipped', 'The flight already has an inventory.');
        }

        try {
            DB::transaction(function () use ($target, $snapshot) {
                $this->writeSnapshot($target, $snapshot);
                $data = $this->availability($target);
                if ($data['warnings'] !== []) {
                    throw BusinessRuleException::make('below_sold', $data['warnings'][0]);
                }
            });
        } catch (BusinessRuleException $e) {
            return $this->result($target, 'skipped', $e->getMessage());
        }

        return $this->result($target, 'applied');
    }

    private function result(Flight $flight, string $status, ?string $reason = null): array
    {
        return [
            'flight_id' => $flight->id,
            'flight_number' => $flight->flight_number,
            'departure_local' => $flight->scheduled_departure_at->copy()->timezone($flight->origin?->timezone ?? 'UTC')->format('Y-m-d H:i'),
            'status' => $status,
            'reason' => $reason,
        ];
    }

    private function flightSnapshot(Flight $flight): array
    {
        $flight->load(['cabinInventory', 'rbdInventory']);

        return [
            'cabins' => $flight->cabinInventory->map(fn ($c) => [
                'cabin_class' => $c->cabin_class->value, 'seats' => $c->seats, 'oversell' => $c->oversell, 'curtail' => $c->curtail,
            ])->values()->all(),
            'rbds' => $flight->rbdInventory->map(fn ($r) => [
                'rbd_id' => $r->rbd_id, 'seats' => $r->seats, 'is_closed' => $r->is_closed,
            ])->values()->all(),
        ];
    }

    private function templateSnapshot(InventoryTemplate $template, Flight $flight): array
    {
        if ($template->airline_id !== $flight->airline_id) {
            throw BusinessRuleException::make('template_other_airline', 'The template belongs to another airline.');
        }
        $template->loadMissing(['cabins', 'rbds']);

        return [
            'cabins' => $template->cabins->map(fn ($c) => [
                'cabin_class' => $c->cabin_class->value, 'seats' => $c->seats, 'oversell' => $c->oversell, 'curtail' => $c->curtail,
            ])->values()->all(),
            'rbds' => $template->rbds->map(fn ($r) => [
                'rbd_id' => $r->rbd_id, 'seats' => $r->seats, 'is_closed' => $r->is_closed,
            ])->values()->all(),
        ];
    }

    /** Aircraft cabin seats, every active RBD of those cabins open up to the whole cabin. */
    private function defaultSnapshot(Flight $flight): array
    {
        $flight->loadMissing('aircraft.cabins');
        if (! $flight->aircraft) {
            throw BusinessRuleException::make('no_aircraft', 'Assign an aircraft to the flight first.');
        }

        $seats = $flight->aircraft->cabins->mapWithKeys(fn ($c) => [$c->cabin_class->value => $c->seat_count]);
        $rbds = Rbd::query()->where('airline_id', $flight->airline_id)->where('is_active', true)
            ->whereIn('cabin_class', $seats->keys())->get();

        return [
            'cabins' => $seats->map(fn ($n, $cabin) => ['cabin_class' => $cabin, 'seats' => $n])->values()->all(),
            'rbds' => $rbds->map(fn (Rbd $r) => ['rbd_id' => $r->id, 'seats' => $seats[$r->cabin_class->value], 'is_closed' => false])->values()->all(),
        ];
    }

    private function log(Flight $flight, string $event, ?string $description): void
    {
        $this->logger->log(ActivityChannel::Admin, $event, $description, $flight, $flight);
    }
}
