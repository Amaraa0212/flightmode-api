<?php

namespace App\Console\Commands;

use App\Models\AgencyAllocation;
use App\Models\Airline;
use App\Models\Role;
use App\Services\Allocation\AllocationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A second airline set up like an existing one, for demos: its aircraft and seat maps, fares, baggage rules, SSRs,
 * service and inventory templates, flight series and its future flights (with their inventory), an agency of its own
 * with a pool quota on every future charter, and users (airline admin, agency admin, DCS) for each given name.
 *
 * Rows are copied with new ids; every foreign key is read from the database, so the copy follows the real schema.
 * Nothing is sold or booked. It stops when the airline code is taken (run it once).
 *
 *   php artisan demo:airline-like MR --name="Skythium Air" --iata=SY --numeric=999 \
 *       --agency="Skythium Agency" --users=yadamsuren,amartuvshin,enerel --domain=skythium.mn
 */
class SetupDemoAirline extends Command
{
    protected $signature = 'demo:airline-like {source : IATA code of the airline to copy}
        {--name= : name of the new airline}
        {--iata= : 2-letter code of the new airline}
        {--numeric= : 3-digit ticket code of the new airline}
        {--agency= : name of the new airline\'s agency}
        {--users= : comma separated names; each gets an airline admin, an agency admin and a DCS user}
        {--domain=example.com : e-mail domain of the users}
        {--dcs-role= : role slug of the DCS users (default: dcs, else station-agent)}
        {--dry-run : do everything, show the counts, then roll back}';

    protected $description = 'Set up a demo airline copied from an existing one (future flights, agency, users)';

    /** @var array<string, array<int, int>> table => old id => new id */
    private array $maps = [];

    /** @var array<string, array<string, string>> table => column => referenced table */
    private array $foreignKeys = [];

    private int $airlineId;

    public function handle(): int
    {
        $source = Airline::query()->where('iata_code', strtoupper($this->argument('source')))->first();
        $iata = strtoupper((string) $this->option('iata'));
        $numeric = (string) $this->option('numeric');
        $names = array_values(array_filter(array_map(fn ($n) => Str::lower(trim($n)), explode(',', (string) $this->option('users')))));

        if (! $source || ! preg_match('/^[A-Z0-9]{2}$/', $iata) || ! preg_match('/^\d{3}$/', $numeric) || ! $this->option('name') || ! $this->option('agency')) {
            $this->error('Give an existing source airline, --name, --iata (2 letters), --numeric (3 digits) and --agency.');

            return self::FAILURE;
        }
        if (Airline::query()->where('iata_code', $iata)->orWhere('numeric_code', $numeric)->exists()) {
            $this->error("An airline with code {$iata} or ticket code {$numeric} already exists: nothing was done.");

            return self::FAILURE;
        }

        $password = null;
        if ($names !== []) {
            // typed on the server by whoever runs it; never stored in the code or printed
            $password = $this->option('dry-run') ? Str::random(16) : $this->secret('Password for the new users');
            if (! $this->option('dry-run') && ($password === null || strlen($password) < 8 || $password !== $this->secret('Repeat the password'))) {
                $this->error('The passwords are empty, shorter than 8 or do not match: nothing was done.');

                return self::FAILURE;
            }
        }

        DB::beginTransaction();
        try {
            $this->copyAirline($source, $iata, $numeric);
            $agencyId = $this->createAgency();
            $this->createUsers($names, $password, $agencyId);
            $this->report();
            if ($this->option('dry-run')) {
                DB::rollBack();
                $this->warn('Dry run: everything was rolled back.');
            } else {
                DB::commit();
                $this->info("{$this->option('name')} ({$iata}) is ready.");
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Failed, nothing was saved: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function copyAirline(Airline $source, string $iata, string $numeric): void
    {
        $airline = (array) DB::table('airlines')->find($source->id);
        unset($airline['id'], $airline['deleted_at']);
        $this->airlineId = DB::table('airlines')->insertGetId(array_merge($airline, [
            'name' => $this->option('name'),
            'iata_code' => $iata,
            'icao_code' => null,
            'numeric_code' => $numeric,
            'logo_path' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
        $this->maps['airlines'] = [$source->id => $this->airlineId];
        $old = fn (string $table) => DB::table($table)->where('airline_id', $source->id);
        $within = fn (string $table, string $column, string $parent) => DB::table($table)->whereIn($column, array_keys($this->maps[$parent] ?? []));

        // aircraft and their seat maps (a registration is unique: the new code in front)
        $this->copy('aircraft', $old('aircraft'), fn ($r) => ['registration' => Str::limit($iata.'-'.$r['registration'], 20, '')]);
        foreach (['aircraft_cabins', 'aircraft_seats', 'aircraft_load_zones', 'seat_assignment_profiles'] as $table) {
            $this->copy($table, $within($table, 'aircraft_id', 'aircraft'));
        }
        $this->copy('seat_assignment_profile_zones', $within('seat_assignment_profile_zones', 'seat_assignment_profile_id', 'seat_assignment_profiles'));

        // fares
        $this->copy('tax_codes', $old('tax_codes'));
        $this->copy('fare_families', $old('fare_families'));
        $this->copy('rbds', $old('rbds')->orderByRaw('parent_id is not null')->orderBy('id'));
        $this->copy('fare_classes', $old('fare_classes'));
        $this->copy('fare_discounts', $within('fare_discounts', 'fare_class_id', 'fare_classes'));
        $this->copy('fares', $old('fares'));
        $this->copy('fare_taxes', $old('fare_taxes'));
        $this->copy('fare_combinations', $old('fare_combinations'));

        // products
        foreach (['baggage_rules', 'baggage_policies', 'airline_ssrs', 'seat_prices', 'service_templates', 'inventory_templates'] as $table) {
            $this->copy($table, $old($table));
        }
        $this->copy('baggage_rule_service_template', $within('baggage_rule_service_template', 'service_template_id', 'service_templates'));
        $this->copy('seat_price_service_template', $within('seat_price_service_template', 'service_template_id', 'service_templates'));
        $this->copy('inventory_template_cabins', $within('inventory_template_cabins', 'inventory_template_id', 'inventory_templates'));
        $this->copy('inventory_template_rbds', $within('inventory_template_rbds', 'inventory_template_id', 'inventory_templates'));

        // schedule: the series and the flights still to come, with the new flight numbers
        $number = fn ($r) => ['flight_number' => preg_replace('/^'.preg_quote($source->iata_code, '/').'/', $iata, (string) $r['flight_number'])];
        $this->copy('flight_series', $old('flight_series'), $number);
        $this->copy('flights', $old('flights')->whereNull('deleted_at')->where('scheduled_departure_at', '>', now()),
            fn ($r) => $number($r) + [
                'status' => 'scheduled', 'status_reason' => null, 'estimated_departure_at' => null, 'off_block_at' => null,
                'takeoff_at' => null, 'estimated_arrival_at' => null,
            ]);
        foreach (['flight_cabin_inventory', 'flight_rbd_inventory', 'baggage_rule_flight', 'flight_seat_price'] as $table) {
            $this->copy($table, $within($table, 'flight_id', 'flights'));
        }
    }

    private function createAgency(): int
    {
        $agencyId = DB::table('agencies')->insertGetId([
            'name' => $this->option('agency'),
            'code' => Str::upper(Str::limit(preg_replace('/[^A-Za-z0-9]/', '', (string) $this->option('agency')), 12, '')),
            'channel' => 'agency',
            'owner_airline_id' => $this->airlineId,
            'email' => 'agency@'.$this->option('domain'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('agency_airline')->insert(['agency_id' => $agencyId, 'airline_id' => $this->airlineId, 'is_active' => true,
            'can_import_passengers' => true, 'can_assign_seats' => true]);

        // a pool on every future charter: the agency sells the free seats, nothing priced
        $charters = DB::table('flights')->whereIn('id', array_values($this->maps['flights'] ?? []))->where('service_type', 'charter')->get();
        foreach ($charters as $flight) {
            $allocation = app(AllocationService::class)->save([
                'airline_id' => $this->airlineId,
                'agency_id' => $agencyId,
                'outbound_flight_id' => $flight->id,
                'mode' => AgencyAllocation::POOL,
                'seats' => 0,
                'cabins' => [],
                'deadline' => Carbon::parse($flight->scheduled_departure_at)->subDay()->toDateString(),
            ]);
            $this->maps['agency_allocations'][$flight->id] = $allocation->id;
        }

        return $agencyId;
    }

    /** @param list<string> $names */
    private function createUsers(array $names, ?string $password, int $agencyId): void
    {
        if ($names === []) {
            return;
        }
        $role = fn (string ...$slugs) => Role::query()->whereIn('slug', $slugs)->orderByRaw('FIELD(slug, "'.implode('","', $slugs).'")')->first()
            ?? throw new RuntimeException('No role '.implode(' / ', $slugs).'.');
        $airlineAdmin = $role('airline-admin');
        $agencyAdmin = $role('agency-admin');
        $dcs = $role(...array_filter([$this->option('dcs-role'), 'dcs', 'station-agent']));
        // an airport-scoped DCS role works at the airline's main station
        $hub = DB::table('flights')->where('airline_id', $this->airlineId)->select('origin_airport_id')
            ->groupBy('origin_airport_id')->orderByRaw('count(*) desc')->value('origin_airport_id');
        $hash = Hash::make($password);

        foreach ($names as $name) {
            $person = Str::title($name);
            foreach ([
                [$person, "{$name}@", $airlineAdmin, ['airline_id' => $this->airlineId]],
                ["{$person} (agency)", "{$name}.agency@", $agencyAdmin, ['agency_id' => $agencyId]],
                ["{$person} (DCS)", "{$name}.dcs@", $dcs, ['airline_id' => $this->airlineId] + ($dcs->scope->value === 'airport' ? ['airport_id' => $hub] : [])],
            ] as [$label, $email, $userRole, $scope]) {
                $email .= $this->option('domain');
                if (DB::table('users')->where('email', $email)->exists()) {
                    throw new RuntimeException("The user {$email} already exists.");
                }
                DB::table('users')->insert($scope + [
                    'name' => $label, 'email' => $email, 'password' => $hash, 'role_id' => $userRole->id,
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->maps['users'][] = $email;
            }
        }
    }

    /**
     * Copy rows to new ids: the airline becomes the new one, a foreign key into a table copied before gets that
     * table's new id; `$change` sets columns of each row (unique values, resets).
     */
    private function copy(string $table, $query, ?\Closure $change = null): void
    {
        if (! DB::getSchemaBuilder()->hasTable($table)) {
            return;
        }
        $keys = $this->foreignKeys($table);
        $hasId = DB::getSchemaBuilder()->hasColumn($table, 'id');
        $this->maps[$table] ??= [];

        foreach ($query->get() as $row) {
            $data = (array) $row;
            $oldId = $data['id'] ?? null;
            unset($data['id']);
            foreach ($keys as $column => $parent) {
                if ($data[$column] === null || ! isset($this->maps[$parent])) {
                    continue;   // e.g. created_by, airports: kept as they are
                }
                if (! isset($this->maps[$parent][$data[$column]])) {
                    // a row tied to something that was not copied (a past flight …) is left out
                    continue 2;
                }
                $data[$column] = $this->maps[$parent][$data[$column]];
            }
            if ($change) {
                $data = array_merge($data, $change($data + ['id' => $oldId]));
            }
            foreach (['created_at', 'updated_at'] as $stamp) {
                if (array_key_exists($stamp, $data)) {
                    $data[$stamp] = now();
                }
            }

            if ($hasId) {
                $this->maps[$table][$oldId] = DB::table($table)->insertGetId($data);
            } else {
                DB::table($table)->insert($data);
                $this->maps[$table][] = 0;
            }
        }
    }

    /** @return array<string, string> column => referenced table */
    private function foreignKeys(string $table): array
    {
        return $this->foreignKeys[$table] ??= collect(DB::select(
            'SELECT COLUMN_NAME c, REFERENCED_TABLE_NAME r FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [DB::getDatabaseName(), $table]
        ))->mapWithKeys(fn ($k) => [$k->c => $k->r])->all();
    }

    private function report(): void
    {
        $this->table(['What', 'Copied'], collect($this->maps)->except('airlines')
            ->map(fn ($rows, $table) => [$table, count($rows)])->values()->all());
    }
}
