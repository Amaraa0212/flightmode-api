<?php

namespace Database\Seeders;

use App\Enums\FlightStatus;
use App\Models\Agency;
use App\Models\AgencyAllocation;
use App\Models\Airline;
use App\Models\Airport;
use App\Models\Fare;
use App\Models\FareClass;
use App\Models\FareCombination;
use App\Models\FareFamily;
use App\Models\FareTax;
use App\Models\Flight;
use App\Models\FlightSeries;
use App\Models\InventoryTemplate;
use App\Models\Rbd;
use App\Models\Role;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\Allocation\AllocationBoard;
use App\Services\Fare\FareService;
use App\Services\Inventory\InventoryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Demo for fare settings + agency sales on Hunnu Air (MR) MR950 UBN → ULG (C 8 / Y 92) and its return MR951:
 * fare families, booking class rules, fare bases with min / max stay, fares, OW / RT airport taxes, combinations,
 * an inventory template applied to the route, and the fixed + pool split:
 *   Blue Sky Travel: fixed C 4 / Y 10 · Hunnu Air Sales Agency: pool (the other C 4 / Y 82), both at published fares.
 * Needs RbdDemoSeeder and DemoUsersSeeder first. Re-runnable: rows are matched by their codes.
 */
class FareSettingsDemoSeeder extends Seeder
{
    /** RBD code => [fare family, advance booking days, upgrade] */
    private const RBD_RULES = [
        'C' => ['FLEX', null, false], 'D' => ['FLEX', null, false], 'I' => [null, null, true],
        'Y' => ['FLEX', null, false], 'B' => ['FLEX', null, false],
        'H' => ['STANDARD', null, false], 'K' => ['STANDARD', null, false], 'L' => ['STANDARD', 2, false], 'M' => ['STANDARD', 3, false],
        'N' => ['BASIC', 3, false], 'Q' => ['BASIC', 5, false], 'T' => ['BASIC', 14, false],
    ];

    /** fare base => [RBD, trip, OW base amount (RT = ×1.8), min stay, max stay, TL hours] */
    private const FARE_BASES = [
        'COW' => ['C', 'OW', 650000, null, null, null], 'CRT' => ['C', 'RT', 650000, null, null, null],
        'YOW' => ['Y', 'OW', 420000, null, null, null], 'YRT' => ['Y', 'RT', 420000, null, null, null],
        'BOW' => ['B', 'OW', 380000, null, null, 72],
        'HOW' => ['H', 'OW', 330000, null, null, 48], 'HRT' => ['H', 'RT', 330000, 2, 30, 48],
        'MOW' => ['M', 'OW', 280000, null, null, 24], 'MRT' => ['M', 'RT', 280000, 3, 14, 24],
        'NOW' => ['N', 'OW', 240000, null, null, 24],
        'TOW' => ['T', 'OW', 190000, null, null, 12], 'TRT' => ['T', 'RT', 190000, 5, 10, 12],
    ];

    /** pairs that may be sold together (out + back on two OW fares) */
    private const COMBINATIONS = [['YOW', 'BOW'], ['BOW', 'HOW'], ['HOW', 'MOW'], ['MOW', 'NOW']];

    /** [airport, tax code, trip, ADT, CHD] — OW: departure airport; RT: both ends added, 70 % charged */
    private const TAXES = [
        ['UBN', 'MN', 'OW', 15000, 10000], ['UBN', 'MN', 'RT', 25000, 18000], ['UBN', 'YQ', 'OW', 20000, 20000], ['UBN', 'YQ', 'RT', 30000, 30000],
        ['ULG', 'MN', 'OW', 8000, 5000], ['ULG', 'MN', 'RT', 12000, 8000],
    ];

    /** Inventory template "MR950 standard": cabins + nested booking class limits */
    private const CABINS = ['C' => 8, 'Y' => 92];

    private const LIMITS = ['C' => 8, 'D' => 6, 'I' => 2, 'Y' => 92, 'B' => 80, 'S' => 0, 'H' => 70, 'K' => 60, 'L' => 50, 'M' => 40, 'N' => 30, 'Q' => 20, 'T' => 10];

    public function run(): void
    {
        config(['services.chinggis.agency_ids' => []]);
        $airline = Airline::query()->where('iata_code', 'MR')->first();
        $admin = User::query()->where('email', 'mr.admin@demo.flightmode.mn')->first();
        $series = FlightSeries::query()->where('airline_id', $airline?->id)->where('flight_number', 'MR950')->first();
        if (! $airline || ! $admin || ! $series || ! Rbd::query()->where('airline_id', $airline->id)->exists()) {
            $this->command?->warn('FareSettingsDemoSeeder: needs Hunnu Air (MR), its MR950 series, mr.admin and RBDs — run DcsDemoSeeder, RbdDemoSeeder and DemoUsersSeeder first.');

            return;
        }
        Auth::login($admin);

        $rbds = $this->bookingClasses($airline);
        $classes = $this->fareBases($airline, $rbds);
        $this->fares($airline, $classes);
        $this->taxes($airline);
        $this->combinations($airline, $classes);
        $returnSeries = $this->returnSeries($series);
        $this->inventory($airline, $rbds, [$series, $returnSeries]);
        $this->allocations($airline, [$series, $returnSeries]);

        Auth::logout();
    }

    /** @return array<string, Rbd> */
    private function bookingClasses(Airline $airline): array
    {
        $families = [];
        foreach ([['BASIC', 'Basic', 1, 'Hand baggage only, no changes'], ['STANDARD', 'Standard', 2, '20 kg, changes with a fee'],
            ['FLEX', 'Flex', 3, '30 kg, free changes and refund']] as [$code, $name, $rank, $description]) {
            $families[$code] = FareFamily::query()->updateOrCreate(['airline_id' => $airline->id, 'code' => $code],
                ['name' => $name, 'rank' => $rank, 'description' => $description, 'is_active' => true])->id;
        }

        $rbds = Rbd::query()->where('airline_id', $airline->id)->get()->keyBy('code');
        foreach (self::RBD_RULES as $code => [$family, $advance, $upgrade]) {
            $rbds[$code]?->update([
                'fare_family_id' => $family ? $families[$family] : null,
                'advance_booking_days' => $advance,
                'is_upgrade' => $upgrade,
            ]);
        }

        return $rbds->all();
    }

    /**
     * @param  array<string, Rbd>  $rbds
     * @return array<string, FareClass>
     */
    private function fareBases(Airline $airline, array $rbds): array
    {
        $classes = [];
        foreach (self::FARE_BASES as $code => [$rbd, $trip, , $min, $max, $tl]) {
            $classes[$code] = FareClass::query()->updateOrCreate(['airline_id' => $airline->id, 'booking_code' => $code], [
                'rbd_id' => $rbds[$rbd]->id,
                'description' => ($trip === 'RT' ? 'Round trip ' : 'One way ').$rbds[$rbd]->description,
                'status' => 'HK',
                'trip_type' => $trip,
                'waitlist' => in_array($rbd, ['Y', 'C'], true),
                'is_visible' => true,
                'min_stay_days' => $min,
                'max_stay_days' => $max,
                'ticket_time_limit_hours' => $tl,
                'is_active' => true,
            ]);
            $classes[$code]->discounts()->delete();
            $classes[$code]->discounts()->createMany([
                ['passenger_type' => 'CHD', 'age_from' => 2, 'age_to' => 11, 'percentage' => 25],
                ['passenger_type' => 'INF', 'age_from' => 0, 'age_to' => 1, 'percentage' => 90],
            ]);
        }

        return $classes;
    }

    /** OW fares both ways, RT fares from UBN, valid for 90 days. @param array<string, FareClass> $classes */
    private function fares(Airline $airline, array $classes): void
    {
        $ubn = Airport::query()->where('iata_code', 'UBN')->firstOrFail();
        $ulg = Airport::query()->where('iata_code', 'ULG')->firstOrFail();

        foreach (self::FARE_BASES as $code => [, $trip, $amount]) {
            $routes = $trip === 'OW' ? [[$ubn, $ulg], [$ulg, $ubn]] : [[$ubn, $ulg]];
            foreach ($routes as [$from, $to]) {
                $fare = Fare::query()->updateOrCreate(
                    ['airline_id' => $airline->id, 'fare_class_id' => $classes[$code]->id, 'origin_airport_id' => $from->id, 'destination_airport_id' => $to->id],
                    ['valid_from' => today()->subDay(), 'valid_to' => today()->addDays(90), 'base_amount' => $trip === 'RT' ? round($amount * 1.8, -3) : $amount,
                        'currency' => 'MNT', 'is_active' => true,
                        // flat columns, re-synced from the fare base below
                        'cabin_class' => $classes[$code]->rbd->cabin_class, 'fare_class' => $classes[$code]->rbd->code, 'trip_type' => $trip],
                );
                app(FareService::class)->sync($fare);
            }
        }
    }

    private function taxes(Airline $airline): void
    {
        $codes = [];
        foreach ([['MN', 'Airport fee'], ['YQ', 'Fuel surcharge']] as [$code, $name]) {
            $codes[$code] = TaxCode::query()->updateOrCreate(['airline_id' => $airline->id, 'code' => $code], ['name' => $name, 'is_active' => true])->id;
        }

        foreach (self::TAXES as [$airport, $code, $trip, $adult, $child]) {
            $airportId = Airport::query()->where('iata_code', $airport)->value('id');
            foreach (['ADT' => $adult, 'CHD' => $child] as $pax => $amount) {
                FareTax::query()->updateOrCreate(
                    ['airline_id' => $airline->id, 'tax_code_id' => $codes[$code], 'airport_id' => $airportId, 'trip_type' => $trip, 'passenger_type' => $pax, 'fare_id' => null],
                    ['amount' => $amount, 'currency' => 'MNT', 'refundable' => $code === 'MN'],
                );
            }
        }

        app(FareService::class)->syncWhere(fn ($q) => $q->where('airline_id', $airline->id));
    }

    /** @param array<string, FareClass> $classes */
    private function combinations(Airline $airline, array $classes): void
    {
        foreach (self::COMBINATIONS as [$a, $b]) {
            if (! FareCombination::query()->where(fn ($q) => $q->pair($classes[$a]->id, $classes[$b]->id))->exists()) {
                FareCombination::create(['airline_id' => $airline->id, 'fare_class_id' => $classes[$a]->id,
                    'combinable_fare_class_id' => $classes[$b]->id, 'created_by' => Auth::id()]);
            }
        }
    }

    /** MR951 ULG → UBN on the same days, leaving 90 minutes after MR950 lands. */
    private function returnSeries(FlightSeries $out): FlightSeries
    {
        $series = FlightSeries::query()->updateOrCreate(
            ['airline_id' => $out->airline_id, 'flight_number' => 'MR951'],
            ['aircraft_id' => $out->aircraft_id, 'origin_airport_id' => $out->destination_airport_id, 'destination_airport_id' => $out->origin_airport_id,
                'departure_time' => '13:30', 'arrival_time' => '16:30', 'days_of_week' => $out->days_of_week,
                'period_start' => $out->period_start, 'period_end' => $out->period_end, 'service_type' => $out->service_type ?? 'scheduled'],
        );

        foreach ($out->flights()->where('scheduled_departure_at', '>', now())->whereNot('status', FlightStatus::Cancelled->value)->get() as $flight) {
            $departure = Carbon::parse($flight->scheduled_arrival_at)->addMinutes(90);
            Flight::query()->updateOrCreate(
                ['flight_series_id' => $series->id, 'scheduled_departure_at' => $departure],
                ['airline_id' => $flight->airline_id, 'aircraft_id' => $flight->aircraft_id, 'flight_number' => 'MR951',
                    'origin_airport_id' => $series->origin_airport_id, 'destination_airport_id' => $series->destination_airport_id,
                    'scheduled_arrival_at' => $departure->copy()->addMinutes(180), 'status' => FlightStatus::Scheduled, 'service_type' => $flight->service_type],
            );
        }

        return $series;
    }

    /**
     * Template "MR950 standard" applied to every upcoming flight of both series (kept if a flight already has one).
     *
     * @param  array<string, Rbd>  $rbds
     * @param  array<int, FlightSeries>  $series
     */
    private function inventory(Airline $airline, array $rbds, array $series): void
    {
        $inventory = app(InventoryService::class);
        $template = InventoryTemplate::query()->updateOrCreate(
            ['airline_id' => $airline->id, 'name' => 'MR950 standard'],
            ['aircraft_id' => $series[0]->aircraft_id, 'description' => 'C 8 / Y 92, nested booking class limits', 'created_by' => Auth::id()],
        );
        $inventory->syncTemplate($template,
            collect(self::CABINS)->map(fn ($seats, $cabin) => ['cabin_class' => $cabin, 'seats' => $seats])->values()->all(),
            collect(self::LIMITS)->filter(fn ($s, $code) => isset($rbds[$code]))
                ->map(fn ($seats, $code) => ['rbd_id' => $rbds[$code]->id, 'seats' => $seats, 'is_closed' => $code === 'S'])->values()->all());

        $flightIds = Flight::query()->whereIn('flight_series_id', array_map(fn ($s) => $s->id, $series))
            ->where('scheduled_departure_at', '>', now())->pluck('id')->all();
        $results = collect($inventory->applyTemplate($template, $flightIds));
        $this->command?->info('Inventory template MR950 standard: '.$results->countBy('status')->map(fn ($n, $s) => "{$n} {$s}")->implode(', '));
    }

    /** @param array<int, FlightSeries> $series */
    private function allocations(Airline $airline, array $series): void
    {
        $pool = Agency::query()->where('code', 'DEMO-MR')->first();
        $fixed = Agency::query()->withTrashed()->updateOrCreate(['code' => 'DEMO-MR-BLUE'], [
            'name' => 'Blue Sky Travel', 'email' => 'bluesky@demo.flightmode.mn', 'is_active' => true, 'deleted_at' => null,
        ]);
        $fixed->airlines()->syncWithoutDetaching([$airline->id => ['is_active' => true]]);

        $role = Role::query()->where('slug', 'agency-agent')->first();
        if ($role) {
            User::query()->withTrashed()->updateOrCreate(['email' => 'mr.bluesky.agent@demo.flightmode.mn'], [
                'name' => 'Blue Sky agent', 'password' => env('DEMO_USERS_PASSWORD', 'Demo2026!'), 'role_id' => $role->id,
                'agency_id' => $fixed->id, 'airline_id' => null, 'airport_id' => null, 'deleted_at' => null,
            ]);
        }

        $rows = [['agency_id' => $fixed->id, 'mode' => AgencyAllocation::FIXED, 'seats' => 14, 'cabins' => ['C' => 4, 'Y' => 10], 'uses_published_fares' => true]];
        if ($pool) {
            $rows[] = ['agency_id' => $pool->id, 'mode' => AgencyAllocation::POOL, 'seats' => 0, 'uses_published_fares' => true];
        }

        $table = [];
        foreach ($series as $s) {
            $first = $s->flights()->where('scheduled_departure_at', '>', now())->orderBy('scheduled_departure_at')->first();
            $last = $s->flights()->where('scheduled_departure_at', '>', now())->orderByDesc('scheduled_departure_at')->first();
            if (! $first) {
                continue;
            }
            $result = app(AllocationBoard::class)->distributeSeries($s,
                $first->scheduled_departure_at->timezone('Asia/Ulaanbaatar')->toDateString(),
                $last->scheduled_departure_at->timezone('Asia/Ulaanbaatar')->toDateString(), $rows, 1);
            $table[] = [$s->flight_number, $result['flights'], $result['saved'], collect($result['failed'])->pluck('message')->unique()->implode('; ') ?: '-'];
        }

        $this->command?->table(['Series', 'Flights', 'Saved', 'Skipped'], $table);
        $this->command?->info('Agency logins: mr.bluesky.agent@ (fixed C 4 / Y 10) and mr.agency.agent@ (pool) @demo.flightmode.mn, password DEMO_USERS_PASSWORD.');
    }
}
