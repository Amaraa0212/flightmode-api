<?php

namespace Database\Seeders;

use App\Enums\BanType;
use App\Enums\BookingSource;
use App\Enums\CabinClass;
use App\Enums\CheckinChannel;
use App\Enums\FlightStatus;
use App\Enums\SegmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Aircraft;
use App\Models\Airline;
use App\Models\AirlineSsr;
use App\Models\Airport;
use App\Models\Announcement;
use App\Models\BaggageRule;
use App\Models\Country;
use App\Models\Flight;
use App\Models\PassengerBan;
use App\Models\PassengerSegment;
use App\Models\Role;
use App\Models\SsrCode;
use App\Models\User;
use App\Services\Baggage\BaggageService;
use App\Services\Booking\BookingService;
use App\Services\Checkin\CheckinService;
use App\Services\Seat\SeatMapGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Demo data for the DCS (check-in counter) app: two airlines, two aircraft with seat maps,
 * today's UBN departures in every state, passengers, check-ins and bags.
 *
 *   php artisan db:seed --class=DcsDemoSeeder
 *
 * Station agent login: DCS_DEMO_EMAIL / DCS_DEMO_PASSWORD (see .env.example).
 * Flights are re-created relative to "now" on every run, so the board always looks live.
 */
class DcsDemoSeeder extends Seeder
{
    private const DEMO_BAN_REASONS = ['Disruptive behaviour on a previous flight', 'Verify travel document at the counter'];

    private const FIRST_NAMES = ['BAT', 'BOLD', 'ENKH', 'GANBAATAR', 'MUNKH-ERDENE', 'SARNAI', 'NOMIN', 'ANU', 'TEMUULEN', 'KHULAN', 'ODGEREL', 'TUGULDUR', 'ZOLJARGAL', 'ARIUNAA', 'DULGUUN', 'MINJIN', 'ULZII', 'TSETSEG', 'BILGUUN', 'NANDIN'];

    private const LAST_NAMES = ['DORJ', 'BAYAR', 'TSEND', 'GANBOLD', 'OYUN', 'ERDENE', 'SUKH', 'NARAN', 'OTGON', 'CHULUUN', 'DAVAAJARGAL', 'BATTULGA'];

    private const SSR = ['WCHR', 'VGML', 'CHML', 'MEDA'];

    public function run(): void
    {
        $this->call([PermissionSeeder::class, RoleSeeder::class, ReferenceDataSeeder::class]);
        mt_srand(20260929);
        // hundreds of check-ins: don't push each one to Reverb (counters just reload after a reseed)
        config(['broadcasting.default' => 'null']);

        DB::transaction(function () {
            $ubn = Airport::query()->where('iata_code', 'UBN')->firstOrFail();
            $airports = $this->airports();
            [$miat, $hunnu] = $this->airlines();
            $b737 = $this->aircraft($miat, 'JU-1088', 'Boeing 737-800', [
                [1, 3, CabinClass::Business, ['left' => 'AC', 'right' => 'DF'], 'A'],
                [4, 14, CabinClass::Economy, ['left' => 'ABC', 'right' => 'DEF'], 'B'],
                [15, 28, CabinClass::Economy, ['left' => 'ABC', 'right' => 'DEF'], 'C'],
            ], exitRows: [14, 15]);
            $e190 = $this->aircraft($hunnu, 'JU-8250', 'Embraer E190', [
                [1, 2, CabinClass::Business, ['left' => 'AC', 'right' => 'DF'], 'A'],
                [3, 12, CabinClass::Economy, ['left' => 'AC', 'right' => 'DF'], 'B'],
                [13, 25, CabinClass::Economy, ['left' => 'AC', 'right' => 'DF'], 'C'],
            ], exitRows: [12]);

            $agent = User::query()->updateOrCreate(
                ['email' => env('DCS_DEMO_EMAIL', 'ops@ubn-airport.mn')],
                [
                    'name' => 'Ops Enkh',
                    'password' => env('DCS_DEMO_PASSWORD', 'DcsDemo2026!'),
                    'role_id' => Role::query()->where('slug', 'station-agent')->value('id'),
                    'airport_id' => $ubn->id,
                    'is_active' => true,
                ],
            );
            $admin = User::query()->whereHas('role', fn ($q) => $q->where('slug', 'system-admin'))->first() ?? $agent;

            // [airline, aircraft, number, destination, minutes from now, duration, gate, final status, load]
            $schedule = [
                [$miat, $b737, 'OM223', 'PEK', -130, 150, 'A2', FlightStatus::Departed, 0.9],
                [$hunnu, $e190, 'MR212', 'KHB', 25, 60, '04', FlightStatus::GateClosed, 0.9],
                [$miat, $b737, 'OM301', 'ICN', 55, 210, '06', FlightStatus::CheckinClosed, 0.85],
                [$hunnu, $e190, 'MR101', 'ULG', 95, 180, '01', FlightStatus::CheckinOpen, 0.7],
                [$miat, $b737, 'OM501', 'NRT', 150, 330, '08', FlightStatus::CheckinOpen, 0.6],
                [$hunnu, $e190, 'MR214', 'ICN', 300, 185, null, FlightStatus::Scheduled, 0.55],
                [$miat, $b737, 'OM297', 'PEK', 540, 150, null, FlightStatus::Scheduled, 0.4],
                [$hunnu, $e190, 'MR201', 'ULG', 780, 180, null, FlightStatus::Cancelled, 0.3],
            ];

            // Remove previous demo flights (and their bookings) so the board is always relative to now.
            $this->clearDemoFlights(array_column($schedule, 2));
            // …and the previous run's demo bans first, or they would block the check-ins below
            PassengerBan::query()->whereIn('reason', self::DEMO_BAN_REASONS)->delete();

            foreach ($schedule as [$airline, $aircraft, $number, $dest, $minutes, $duration, $gate, $status, $load]) {
                $this->flight($airline, $aircraft, $admin, $ubn, $airports[$dest], $number,
                    now()->addMinutes($minutes)->setSecond(0), $duration, $gate, $status, $load);
            }
        });

        $this->demoBansAndAnnouncements();

        $this->command?->info('DCS demo data ready. Login: '.env('DCS_DEMO_EMAIL', 'ops@ubn-airport.mn').' (password: DCS_DEMO_PASSWORD).');
    }

    /** One banned and one watch-listed passenger on an open flight, plus station announcements. */
    private function demoBansAndAnnouncements(): void
    {
        $reasons = self::DEMO_BAN_REASONS;

        $flight = Flight::query()->where('status', FlightStatus::CheckinOpen)->orderBy('scheduled_departure_at')->first();
        if ($flight) {
            $segments = PassengerSegment::query()->with('passenger')->where('flight_id', $flight->id)
                ->where('status', SegmentStatus::Booked)
                ->whereHas('passenger', fn ($q) => $q->whereNull('infant_of_passenger_id'))
                ->orderBy('id')->take(2)->get();

            foreach ($segments as $i => $segment) {
                $p = $segment->passenger;
                PassengerBan::query()->updateOrCreate(
                    ['document_number' => $p->document_number],
                    [
                        'airline_id' => $flight->airline_id,
                        'ban_type' => $i === 0 ? BanType::NoFly : BanType::Watch,
                        'last_name' => $p->last_name,
                        'first_name' => $p->first_name,
                        'date_of_birth' => $p->date_of_birth,
                        'reason' => $reasons[$i],
                        'is_active' => true,
                    ],
                );
            }
        }

        foreach (Airline::query()->whereIn('iata_code', ['OM', 'MR'])->get() as $airline) {
            Announcement::query()->updateOrCreate(
                ['airline_id' => $airline->id, 'title' => 'Excess baggage is now priced by the system'],
                ['body' => 'The counter no longer types excess prices. Check the quote before tagging the bag.', 'requires_acknowledgement' => true],
            );
            Announcement::query()->updateOrCreate(
                ['airline_id' => $airline->id, 'title' => 'Counters 08-12 use the new bag tag printers'],
                ['body' => 'Print a test tag at the start of your shift.', 'requires_acknowledgement' => false],
            );
        }
    }

    /** @return array<string, Airport> */
    private function airports(): array
    {
        $countries = Country::query()->pluck('id', 'alpha2');
        $extra = [
            ['iata_code' => 'KHB', 'icao_code' => 'UHHH', 'name' => 'Khabarovsk Novy Airport', 'city' => 'Khabarovsk', 'city_code' => 'KHV', 'country' => 'RU', 'timezone' => 'Asia/Vladivostok'],
            ['iata_code' => 'NRT', 'icao_code' => 'RJAA', 'name' => 'Narita International Airport', 'city' => 'Tokyo', 'city_code' => 'TYO', 'country' => 'JP', 'timezone' => 'Asia/Tokyo'],
            ['iata_code' => 'ULG', 'icao_code' => 'ZMUL', 'name' => 'Ulgii Airport', 'city' => 'Ulgii', 'city_code' => 'ULG', 'country' => 'MN', 'timezone' => 'Asia/Hovd'],
        ];
        foreach ($extra as $airport) {
            $country = $airport['country'];
            unset($airport['country']);
            Airport::query()->updateOrCreate(['iata_code' => $airport['iata_code']], $airport + ['country_id' => $countries[$country] ?? null]);
        }

        return Airport::query()->get()->keyBy('iata_code')->all();
    }

    /** @return array{0: Airline, 1: Airline} */
    private function airlines(): array
    {
        $result = [];
        foreach ([
            ['iata_code' => 'OM', 'name' => 'MIAT Mongolian Airlines', 'icao_code' => 'MGL', 'numeric_code' => '289', 'brand_color' => '#1d4ed8'],
            ['iata_code' => 'MR', 'name' => 'Hunnu Air', 'icao_code' => 'MML', 'numeric_code' => '861', 'brand_color' => '#dc2626'],
        ] as $data) {
            $airline = Airline::query()->updateOrCreate(['iata_code' => $data['iata_code']], $data + ['currency' => 'MNT', 'is_active' => true]);

            foreach (self::SSR as $code) {
                $ssr = SsrCode::query()->where('code', $code)->first();
                if ($ssr) {
                    AirlineSsr::query()->firstOrCreate(['airline_id' => $airline->id, 'ssr_code_id' => $ssr->id]);
                }
            }

            foreach ([
                ['name' => 'Economy 1PC 23KG', 'type' => 'piece', 'cabin_class' => 'Y', 'pieces' => 1, 'weight_kg' => 23, 'max_quantity' => 1],
                ['name' => 'Business 2PC 32KG', 'type' => 'piece', 'cabin_class' => 'C', 'pieces' => 2, 'weight_kg' => 32, 'max_quantity' => 2],
                ['name' => 'Prepaid extra bag', 'type' => 'piece', 'cabin_class' => null, 'pieces' => 1, 'weight_kg' => 23, 'max_quantity' => null, 'ssr_code' => BaggageRule::SSR_PREPAID, 'price' => 150000],
                ['name' => 'Excess weight', 'type' => 'weight', 'cabin_class' => null, 'pieces' => null, 'weight_kg' => 1, 'max_quantity' => null, 'ssr_code' => BaggageRule::SSR_EXCESS],
            ] as $rule) {
                BaggageRule::query()->updateOrCreate(['airline_id' => $airline->id, 'name' => $rule['name']], $rule + ['is_active' => true]);
            }

            $result[] = $airline;
        }

        return $result;
    }

    /** @param  int[]  $exitRows  over-wing emergency exit rows */
    private function aircraft(Airline $airline, string $registration, string $type, array $blocks, array $exitRows = []): Aircraft
    {
        // exit rows are (re)marked on every run — also on an aircraft seeded before they existed
        $markExits = function (Aircraft $a) use ($exitRows): void {
            $a->seats()->whereNotIn('seat_row', $exitRows)->update(['is_emergency_exit' => false]);
            $a->seats()->whereIn('seat_row', $exitRows)->update(['is_emergency_exit' => true]);
        };

        $aircraft = Aircraft::query()->firstOrNew(['registration' => $registration]);
        if ($aircraft->exists && $aircraft->seats()->exists()) {
            $markExits($aircraft);

            return $aircraft;
        }

        $seatCount = fn (array $b) => ($b[1] - $b[0] + 1) * (strlen($b[3]['left']) + strlen($b[3]['right']));
        $byCabin = collect($blocks)->groupBy(fn ($b) => $b[2]->value)->map(fn ($g) => $g->sum($seatCount));

        $aircraft->fill(['airline_id' => $airline->id, 'type' => $type, 'total_seats' => $byCabin->sum(), 'max_infants' => 8, 'is_active' => true])->save();
        foreach ($byCabin as $cabin => $count) {
            $aircraft->cabins()->updateOrCreate(['cabin_class' => $cabin], ['seat_count' => $count]);
        }

        $generator = app(SeatMapGenerator::class);
        foreach ($blocks as [$from, $to, $cabin, $layout, $zone]) {
            $generator->generateBlock($aircraft, $from, $to, $cabin, $layout, $zone);
        }
        $markExits($aircraft);

        return $aircraft;
    }

    /** Only this seeder's flights: its flight numbers from UBN around today. */
    private function clearDemoFlights(array $flightNumbers): void
    {
        $flights = Flight::query()->whereIn('flight_number', $flightNumbers)
            ->whereHas('origin', fn ($q) => $q->where('iata_code', 'UBN'))
            ->whereBetween('scheduled_departure_at', [now()->subDays(2), now()->addDays(2)])
            ->get();
        foreach ($flights as $flight) {
            $bookingIds = DB::table('passenger_segments')
                ->join('passengers', 'passengers.id', '=', 'passenger_segments.passenger_id')
                ->where('passenger_segments.flight_id', $flight->id)->distinct()->pluck('passengers.booking_id');
            DB::table('bookings')->whereIn('id', $bookingIds)->delete();
            $flight->baggageRules()->detach();
            $flight->delete();
        }
    }

    private function flight(Airline $airline, Aircraft $aircraft, User $admin, Airport $from, Airport $to, string $number,
        Carbon $departure, int $duration, ?string $gate, FlightStatus $finalStatus, float $load): void
    {
        $flight = Flight::create([
            'airline_id' => $airline->id,
            'aircraft_id' => $aircraft->id,
            'flight_number' => $number,
            'origin_airport_id' => $from->id,
            'destination_airport_id' => $to->id,
            'scheduled_departure_at' => $departure,
            'scheduled_arrival_at' => $departure->copy()->addMinutes($duration),
            'terminal' => 'T1',
            'gate' => $gate,
            'status' => FlightStatus::CheckinOpen, // open while seeding so check-in / bags go through the services
            'excess_baggage_max_kg' => 50,
            'excess_baggage_price_per_kg' => in_array($to->iata_code, ['ULG'], true) ? 6000 : 18000,
            'online_checkin_enabled' => true,
            'online_checkin_opens_minutes' => 1440,
            'online_checkin_closes_minutes' => 120,
        ]);
        $flight->baggageRules()->sync(BaggageRule::query()->where('airline_id', $airline->id)->pluck('id'));

        $bookings = app(BookingService::class);
        $target = (int) round($aircraft->total_seats * $load);
        $created = 0;
        while ($created < $target) {
            $size = min($target - $created, mt_rand(1, 10) <= 6 ? 1 : mt_rand(2, 3));
            $lastName = self::LAST_NAMES[array_rand(self::LAST_NAMES)];
            $passengers = [];
            for ($i = 0; $i < $size; $i++) {
                $child = $i > 0 && mt_rand(1, 3) === 1;
                $row = [
                    'first_name' => self::FIRST_NAMES[array_rand(self::FIRST_NAMES)],
                    'last_name' => $lastName,
                    'gender' => mt_rand(0, 1) ? 'M' : 'F',
                    'date_of_birth' => now()->subYears($child ? mt_rand(3, 11) : mt_rand(19, 68))->subDays(mt_rand(0, 300))->toDateString(),
                    'nationality' => 'MN',
                    'document_type' => 'passport',
                    'document_number' => 'E'.mt_rand(1000000, 9999999),
                    'document_issuing_country' => 'MN',
                    'document_expires_at' => now()->addYears(mt_rand(1, 8))->toDateString(),
                    'cabin_class' => mt_rand(1, 100) <= 7 ? 'C' : 'Y',
                    'phone' => $i === 0 ? '+976 9'.mt_rand(100, 999).' '.mt_rand(1000, 9999) : null,
                    'email' => $i === 0 ? strtolower($lastName).mt_rand(10, 99).'@example.mn' : null,
                    'ssr_codes' => mt_rand(1, 12) === 1 ? [self::SSR[array_rand(self::SSR)]] : [],
                ];
                if ($i === 0 && mt_rand(1, 18) === 1) {
                    $row['infant'] = ['first_name' => 'BABY', 'last_name' => $lastName, 'gender' => 'F',
                        'date_of_birth' => now()->subMonths(mt_rand(3, 18))->toDateString(), 'nationality' => 'MN',
                        'document_type' => 'passport', 'document_number' => 'E'.mt_rand(1000000, 9999999),
                        'document_expires_at' => now()->addYears(3)->toDateString()];
                }
                $passengers[] = $row;
            }

            try {
                $bookings->create(['flight_ids' => [$flight->id], 'passengers' => $passengers,
                    'contact_phone' => $passengers[0]['phone']], $admin, BookingSource::Admin);
                $created += $size;
            } catch (\Throwable) {
                // duplicate document / capacity → skip this group
                $target--;
            }
        }

        $this->checkIn($flight, $finalStatus);

        $flight->update([
            'status' => $finalStatus,
            'status_reason' => $finalStatus === FlightStatus::Cancelled ? 'Operational reasons' : null,
        ]);
    }

    private function checkIn(Flight $flight, FlightStatus $finalStatus): void
    {
        [$share, $boardShare] = match ($finalStatus) {
            FlightStatus::Departed => [0.97, 1.0],
            FlightStatus::GateClosed => [0.95, 0.75],
            FlightStatus::CheckinClosed => [0.9, 0.0],
            FlightStatus::CheckinOpen => [0.35, 0.0],
            FlightStatus::Scheduled => [0.08, 0.0], // online check-in
            default => [0.0, 0.0],
        };
        if ($share === 0.0) {
            return;
        }

        $checkin = app(CheckinService::class);
        $baggage = app(BaggageService::class);
        $channel = $finalStatus === FlightStatus::Scheduled ? CheckinChannel::Online : CheckinChannel::Dcs;
        $rules = $flight->baggageRules()->whereNull('ssr_code')->get()->keyBy('cabin_class');

        $segments = PassengerSegment::query()->where('flight_id', $flight->id)
            ->whereHas('passenger', fn ($q) => $q->whereNull('infant_of_passenger_id'))
            ->where('status', SegmentStatus::Booked)->get()->shuffle();

        foreach ($segments->take((int) round($segments->count() * $share)) as $segment) {
            try {
                $segment = $checkin->checkIn($segment, null, $channel);
            } catch (BusinessRuleException $e) {
                // e.g. a generated passenger matches a ban someone added — leave them booked
                continue;
            }

            $rule = $rules->get($segment->cabin_class->value);
            if ($channel === CheckinChannel::Dcs && $rule && mt_rand(1, 4) > 1) {
                $pieces = min($rule->max_quantity ?? 1, mt_rand(1, 10) <= 8 ? 1 : 2);
                $baggage->accept($segment, [
                    'baggage_rule_id' => $rule->id,
                    'pieces' => $pieces,
                    'weight_kg' => $pieces * mt_rand(12, 23),
                    'free_allowance_kg' => (float) $rule->weight_kg * $pieces,
                ]);
            }

            if ($boardShare > 0 && mt_rand(1, 100) <= $boardShare * 100) {
                $segment->update(['status' => SegmentStatus::Boarded, 'boarded_at' => now()->subMinutes(mt_rand(1, 20))]);
                PassengerSegment::query()->whereHas('passenger', fn ($q) => $q->where('infant_of_passenger_id', $segment->passenger_id))
                    ->where('flight_id', $flight->id)->update(['status' => SegmentStatus::Boarded, 'boarded_at' => now()]);
            }
        }
    }
}
