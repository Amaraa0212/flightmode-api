<?php

namespace Database\Seeders;

use App\Enums\ActivityChannel;
use App\Enums\BookingSource;
use App\Enums\CabinClass;
use App\Enums\FlightStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Agency;
use App\Models\AgencyAccount;
use App\Models\AgencyAllocation;
use App\Models\AgencyHold;
use App\Models\Airline;
use App\Models\Fare;
use App\Models\Flight;
use App\Models\User;
use App\Services\Agency\AccountService;
use App\Services\Allocation\AllocationService;
use App\Services\Allocation\HoldService;
use App\Services\Booking\BookingService;
use Illuminate\Database\Seeder;

/**
 * Demo data for the agency hold / prepaid account flow (run after DemoUsersSeeder):
 * each demo agency (DEMO-OM, DEMO-MR) gets
 *  - quotas with priced fares (M / B economy, C business) on the airline's next flights,
 *  - a topped-up account (5 000 000),
 *  - a hold with one name already entered (charged to the account) and a hold without names.
 * Safe to re-run: flights that already have a quota of the agency, an existing account and existing holds are kept.
 */
class AgencyHoldDemoSeeder extends Seeder
{
    private const FLIGHTS = 4;

    private const TOP_UP = 5_000_000;

    /** fare class => [cabin, base, tax, seats on the quota (0 = no limit)] */
    private const FARES = [
        'M' => ['Y', 450_000, 35_000, 6],
        'B' => ['Y', 650_000, 35_000, 0],
        'C' => ['C', 1_500_000, 35_000, 2],
    ];

    public function run(): void
    {
        // demo quotas never go to an external agent system
        config(['services.chinggis.agency_ids' => []]);
        $rows = [];

        foreach (Airline::query()->whereIn('iata_code', ['OM', 'MR'])->orderByDesc('iata_code')->get() as $airline) {
            $code = strtolower($airline->iata_code);
            $agency = Agency::query()->where('code', "DEMO-{$airline->iata_code}")->first();
            $agent = User::query()->where('email', "{$code}.agency.agent@demo.flightmode.mn")->first();
            $admin = User::query()->where('email', "{$code}.admin@demo.flightmode.mn")->first();
            if (! $agency || ! $agent || ! $admin) {
                $this->command?->warn("AgencyHoldDemoSeeder: no demo agency / users for {$airline->iata_code} — run DemoUsersSeeder first.");

                continue;
            }

            $flights = Flight::query()->with(['aircraft.cabins', 'origin'])
                ->where('airline_id', $airline->id)
                ->where('status', FlightStatus::Scheduled->value)
                ->where('scheduled_departure_at', '>', now()->addDays(2))
                ->orderBy('scheduled_departure_at')
                ->limit(self::FLIGHTS)
                ->get();
            if ($flights->isEmpty()) {
                $this->command?->warn("AgencyHoldDemoSeeder: {$airline->iata_code} has no scheduled flight more than 2 days ahead.");

                continue;
            }

            if (! AgencyAccount::query()->where('agency_id', $agency->id)->where('airline_id', $airline->id)->exists()) {
                app(AccountService::class)->topUp($agency->id, $airline->id, self::TOP_UP, 'topup', 'Demo: эхний цэнэглэлт', $admin);
            }

            $hasHolds = AgencyHold::query()->where('agency_id', $agency->id)->where('status', AgencyHold::ACTIVE)->exists();
            foreach ($flights->values() as $i => $flight) {
                $allocation = AgencyAllocation::query()->where('agency_id', $agency->id)->where('outbound_flight_id', $flight->id)->first()
                    ?? $this->quota($airline, $agency, $flight);
                if (! $allocation) {
                    continue;
                }

                if (! $hasHolds && $i < 2) {
                    $this->hold($allocation, $agent, $i === 0);
                }

                $usage = app(AllocationService::class)->usage($allocation);
                $rows[] = [$airline->iata_code, $flight->flight_number,
                    $flight->scheduled_departure_at->copy()->timezone($flight->origin?->timezone ?? 'UTC')->format('m-d H:i'),
                    "{$usage['used']} / {$usage['held']} / {$usage['seats']}"];
            }

            $balance = AgencyAccount::query()->where('agency_id', $agency->id)->where('airline_id', $airline->id)->value('balance');
            $this->command?->info("{$agency->name}: balance ".number_format((float) $balance).' '.$airline->currency);
        }

        $this->command?->table(['Airline', 'Flight', 'Departure', 'Sold / held / quota'], $rows);
    }

    /** Quota with priced fares, as large as the cabins still allow (Y up to 12, C up to 2). */
    private function quota(Airline $airline, Agency $agency, Flight $flight): ?AgencyAllocation
    {
        $allocations = app(AllocationService::class);
        $cabins = array_filter([
            'Y' => min(12, $allocations->cabinSeatsLeftForAllocations($flight, CabinClass::Economy)),
            'C' => min(2, $allocations->cabinSeatsLeftForAllocations($flight, CabinClass::Business)),
        ]);
        if (! $cabins) {
            return null;
        }

        $fares = [];
        foreach (self::FARES as $fareClass => [$cabin, $base, $tax, $seats]) {
            if (! isset($cabins[$cabin])) {
                continue;
            }
            $fare = Fare::query()->firstOrCreate(
                ['airline_id' => $airline->id, 'origin_airport_id' => $flight->origin_airport_id,
                    'destination_airport_id' => $flight->destination_airport_id, 'trip_type' => 'OW', 'fare_class' => $fareClass],
                ['cabin_class' => $cabin, 'valid_from' => today()->subDay(), 'valid_to' => today()->addMonths(3),
                    'base_amount' => $base, 'tax_amount' => $tax, 'currency' => $airline->currency ?? 'MNT', 'is_active' => true],
            );
            $fares[] = ['fare_id' => $fare->id, 'cabin_class' => $cabin, 'seats' => min($seats, $cabins[$cabin])];
        }

        $local = $flight->scheduled_departure_at->copy()->timezone($flight->origin?->timezone ?? 'UTC');

        return $allocations->save([
            'airline_id' => $airline->id,
            'agency_id' => $agency->id,
            'outbound_flight_id' => $flight->id,
            'seats' => array_sum($cabins),
            'deadline' => $local->copy()->subDay()->toDateString(),
            'cabins' => $cabins,
            'fares' => $fares,
        ]);
    }

    /** A hold on the cheapest economy fare; the first one also gets a passenger (charged to the account). */
    private function hold(AgencyAllocation $allocation, User $agent, bool $withName): void
    {
        $allocation->load('fares.fare');
        $bucket = $allocation->fares->first(fn ($f) => $f->fare?->fare_class === 'M');

        try {
            $hold = app(HoldService::class)->create($allocation, CabinClass::Economy, $withName ? 4 : 2, $bucket?->id, $agent, ActivityChannel::Agency);
            if (! $withName) {
                return;
            }

            app(BookingService::class)->create([
                'allocation_id' => $allocation->id,
                'hold_id' => $hold->id,
                'contact_phone' => '+976 8811 2233',
                'passengers' => [[
                    'first_name' => 'ANU',
                    'last_name' => 'BATTULGA',
                    'gender' => 'F',
                    'date_of_birth' => now()->subYears(29)->toDateString(),
                    'nationality' => 'MN',
                    'document_type' => 'passport',
                    'document_number' => 'E'.random_int(1000000, 9999999),
                    'document_issuing_country' => 'MN',
                    'document_expires_at' => now()->addYears(5)->toDateString(),
                    'cabin_class' => 'Y',
                ]],
            ], $agent, BookingSource::Agency);
        } catch (BusinessRuleException $e) {
            $this->command?->warn("AgencyHoldDemoSeeder: {$allocation->id}: {$e->getMessage()}");
        }
    }
}
