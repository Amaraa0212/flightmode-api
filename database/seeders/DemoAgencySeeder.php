<?php

namespace Database\Seeders;

use App\Enums\BookingSource;
use App\Enums\FlightStatus;
use App\Models\Agency;
use App\Models\AgencyAllocation;
use App\Models\Aircraft;
use App\Models\Airline;
use App\Models\Airport;
use App\Models\BaggageRule;
use App\Models\Flight;
use App\Models\User;
use App\Services\Allocation\AllocationService;
use App\Services\Booking\BookingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Data for the demo agency users (DemoUsersSeeder): each airline's demo sales agency gets a seat quota on every
 * open / scheduled flight of that airline from UBN, and its ticketing agent has already sold a few bookings on it.
 * The agencies also get upcoming flights a few days ahead (SALES_FLIGHTS) — today's board is too close to departure.
 * Re-runnable: quotas already there are kept, missing ones are added; the sales flights are re-created.
 */
class DemoAgencySeeder extends Seeder
{
    private const SEATS = 20;

    private const BOOKINGS_PER_QUOTA = 4;

    /** Upcoming flights the agencies sell: [airline, flight number, destination, days ahead, UB departure time, minutes] */
    private const SALES_FLIGHTS = [
        ['OM', 'OM225', 'PEK', 2, '10:00', 150],
        ['OM', 'OM305', 'ICN', 5, '09:30', 210],
        ['MR', 'MR215', 'ICN', 2, '13:00', 185],
        ['MR', 'MR105', 'ULG', 5, '08:00', 180],
    ];

    private const NAMES = [['ENKH', 'BOLD'], ['SARNAI', 'DORJ'], ['TEMUULEN', 'BAYAR'], ['NOMIN', 'TSEND'], ['ANU', 'GANBOLD'], ['BILGUUN', 'NARAN']];

    public function run(): void
    {
        // demo quotas never go to an external agent system
        config(['services.chinggis.agency_ids' => []]);
        mt_srand(20261011);

        $allocations = app(AllocationService::class);
        $bookings = app(BookingService::class);
        $rows = [];
        $this->salesFlights();

        foreach (Airline::query()->whereIn('iata_code', ['OM', 'MR'])->orderByDesc('iata_code')->get() as $airline) {
            $agency = Agency::query()->where('code', "DEMO-{$airline->iata_code}")->first();
            $agent = User::query()->where('email', strtolower($airline->iata_code).'.agency.agent@demo.flightmode.mn')->first();
            if (! $agency || ! $agent) {
                $this->command?->warn("DemoAgencySeeder: no demo agency / agent for {$airline->iata_code} — run DemoUsersSeeder first.");

                continue;
            }

            $flights = Flight::query()->where('airline_id', $airline->id)
                ->whereHas('origin', fn ($q) => $q->where('iata_code', 'UBN'))
                ->whereIn('status', [FlightStatus::Scheduled->value, FlightStatus::CheckinOpen->value])
                ->where('scheduled_departure_at', '>', now())
                ->orderBy('scheduled_departure_at')->get();

            foreach ($flights as $flight) {
                $allocation = AgencyAllocation::query()->where('agency_id', $agency->id)->where('outbound_flight_id', $flight->id)->first();
                if (! $allocation) {
                    $seats = min(self::SEATS, $allocations->seatsLeftForAllocations($flight));
                    if ($seats < 1) {
                        continue;
                    }
                    $allocation = $allocations->save([
                        'airline_id' => $airline->id,
                        'agency_id' => $agency->id,
                        'outbound_flight_id' => $flight->id,
                        'seats' => $seats,
                        'deadline' => $flight->scheduled_departure_at->toDateString(),
                        'cabins' => ['Y' => $seats],
                    ]);
                    $this->sell($bookings, $allocation, $agent);
                }

                $usage = $allocations->usage($allocation);
                $rows[] = [$airline->iata_code, $agency->name, $flight->flight_number, $flight->scheduled_departure_at->timezone('Asia/Ulaanbaatar')->format('m-d H:i'), "{$usage['used']} / {$usage['seats']}"];
            }
        }

        $this->command?->table(['Airline', 'Agency', 'Flight', 'Departure (UB)', 'Sold / quota'], $rows);
    }

    /**
     * Today's demo board departs within hours (names deadline = STD − 2 h), so the agencies also get flights
     * a few days ahead. Re-created on every run, relative to today, with their quotas and bookings.
     */
    private function salesFlights(): void
    {
        $ubn = Airport::query()->where('iata_code', 'UBN')->first();
        if (! $ubn) {
            return;
        }

        DB::transaction(function () use ($ubn) {
            foreach (Flight::query()->whereIn('flight_number', array_column(self::SALES_FLIGHTS, 1))->where('origin_airport_id', $ubn->id)->get() as $old) {
                $bookingIds = DB::table('passenger_segments')->join('passengers', 'passengers.id', '=', 'passenger_segments.passenger_id')
                    ->where('passenger_segments.flight_id', $old->id)->distinct()->pluck('passengers.booking_id');
                DB::table('bookings')->whereIn('id', $bookingIds)->delete();
                AgencyAllocation::query()->where('outbound_flight_id', $old->id)->orWhere('return_flight_id', $old->id)->delete();
                $old->baggageRules()->detach();
                $old->delete();
            }

            foreach (self::SALES_FLIGHTS as [$code, $number, $dest, $days, $time, $minutes]) {
                $airline = Airline::query()->where('iata_code', $code)->first();
                $aircraft = $airline ? Aircraft::query()->where('airline_id', $airline->id)->where('is_active', true)->first() : null;
                $to = Airport::query()->where('iata_code', $dest)->first();
                if (! $airline || ! $aircraft || ! $to) {
                    continue;
                }
                $departure = Carbon::parse(today('Asia/Ulaanbaatar')->addDays($days)->toDateString().' '.$time, 'Asia/Ulaanbaatar')->utc();
                $flight = Flight::create([
                    'airline_id' => $airline->id,
                    'aircraft_id' => $aircraft->id,
                    'flight_number' => $number,
                    'origin_airport_id' => $ubn->id,
                    'destination_airport_id' => $to->id,
                    'scheduled_departure_at' => $departure,
                    'scheduled_arrival_at' => $departure->copy()->addMinutes($minutes),
                    'terminal' => 'T1',
                    'status' => FlightStatus::Scheduled,
                    'excess_baggage_max_kg' => 50,
                    'excess_baggage_price_per_kg' => $dest === 'ULG' ? 6000 : 18000,
                    'online_checkin_enabled' => true,
                    'online_checkin_opens_minutes' => 1440,
                    'online_checkin_closes_minutes' => 120,
                ]);
                $flight->baggageRules()->sync(BaggageRule::query()->where('airline_id', $airline->id)->pluck('id'));
            }
        });
    }

    /** A few bookings by the agency's ticketing agent (1–3 passengers each) */
    private function sell(BookingService $bookings, AgencyAllocation $allocation, User $agent): void
    {
        for ($b = 0; $b < self::BOOKINGS_PER_QUOTA; $b++) {
            [$first, $last] = self::NAMES[array_rand(self::NAMES)];
            $size = mt_rand(1, 3);
            $passengers = [];
            for ($i = 0; $i < $size; $i++) {
                $passengers[] = [
                    'first_name' => $i === 0 ? $first : self::NAMES[array_rand(self::NAMES)][0],
                    'last_name' => $last,
                    'gender' => mt_rand(0, 1) ? 'M' : 'F',
                    'date_of_birth' => now()->subYears(mt_rand(19, 65))->subDays(mt_rand(0, 300))->toDateString(),
                    'nationality' => 'MN',
                    'document_type' => 'passport',
                    'document_number' => 'A'.mt_rand(1000000, 9999999),
                    'document_issuing_country' => 'MN',
                    'document_expires_at' => now()->addYears(mt_rand(2, 8))->toDateString(),
                    'cabin_class' => 'Y',
                    'phone' => $i === 0 ? '+976 8'.mt_rand(100, 999).' '.mt_rand(1000, 9999) : null,
                    'email' => $i === 0 ? strtolower($last).mt_rand(10, 99).'@agency.example.mn' : null,
                ];
            }

            try {
                $bookings->create(['allocation_id' => $allocation->id, 'passengers' => $passengers,
                    'contact_phone' => $passengers[0]['phone']], $agent, BookingSource::Agency);
            } catch (\Throwable $e) {
                $this->command?->warn("Booking on {$allocation->id} skipped: {$e->getMessage()}");
            }
        }
    }
}
