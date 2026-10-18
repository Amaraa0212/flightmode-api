<?php

namespace Database\Seeders;

use App\Enums\BookingSource;
use App\Enums\CabinClass;
use App\Enums\CheckinChannel;
use App\Enums\FlightStatus;
use App\Enums\SegmentStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Aircraft;
use App\Models\AircraftSeat;
use App\Models\Airline;
use App\Models\Airport;
use App\Models\BaggageRule;
use App\Models\CheckinGroup;
use App\Models\Flight;
use App\Models\FlightSeries;
use App\Models\PassengerSegment;
use App\Models\User;
use App\Services\Baggage\BaggageService;
use App\Services\Booking\BookingService;
use App\Services\Checkin\CheckinService;
use App\Services\Flight\FlightSeriesService;
use App\Services\Seat\SeatMapGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Hunnu Air (MR) flights for trying the 2026-10 issue-report fixes. Run after DcsDemoSeeder:
 *
 *   php artisan db:seed --class=FlightTestDataSeeder
 *
 *  - MR901 UBN→ICN on a two-aisle Boeing 767 (2-2-2 / 2-3-2): seat map alignment, zone bands with pax counts,
 *    ADT / CHD / INF counts, a check-in group with bags (cannot be ungrouped), paid and waived excess baggage,
 *    a "Piece + kg" baggage rule
 *  - MR902 UBN→ULG tomorrow, scheduled, booked only
 *  - MR950 UBN→ULG series (Mon / Wed / Fri, 3 weeks): one flight moved by hand (Series · edited), one cancelled
 *
 * Re-running replaces these flights and the series; nothing else is touched.
 */
class FlightTestDataSeeder extends Seeder
{
    private const FLIGHTS = ['MR901', 'MR902'];

    private const SERIES = 'MR950';

    private const FIRST_NAMES = ['BAT', 'BOLD', 'ENKH', 'SARNAI', 'NOMIN', 'ANU', 'TEMUULEN', 'KHULAN', 'TUGULDUR', 'ZOLJARGAL', 'ARIUNAA', 'MINJIN', 'BILGUUN', 'NANDIN'];

    private const LAST_NAMES = ['DORJ', 'BAYAR', 'TSEND', 'GANBOLD', 'OYUN', 'ERDENE', 'SUKH', 'NARAN', 'OTGON', 'CHULUUN'];

    public function run(): void
    {
        $hunnu = Airline::query()->where('iata_code', 'MR')->first();
        $airports = Airport::query()->whereIn('iata_code', ['UBN', 'ICN', 'ULG'])->get()->keyBy('iata_code');
        if (! $hunnu || $airports->count() < 3) {
            $this->command?->error('Hunnu Air (MR) and UBN / ICN / ULG are missing — run DcsDemoSeeder first.');

            return;
        }

        mt_srand(20261018);
        config(['broadcasting.default' => 'null']);
        $admin = User::query()->whereHas('role', fn ($q) => $q->where('slug', 'airline-admin'))->where('airline_id', $hunnu->id)->first()
            ?? User::query()->whereHas('role', fn ($q) => $q->where('slug', 'system-admin'))->firstOrFail();
        auth()->setUser($admin);

        DB::transaction(function () use ($hunnu, $airports, $admin) {
            $this->clear($hunnu);

            $b767 = $this->wideBody($hunnu);
            $e190 = Aircraft::query()->where('airline_id', $hunnu->id)->where('registration', 'JU-8250')->first() ?? $b767;
            $combined = BaggageRule::query()->updateOrCreate(
                ['airline_id' => $hunnu->id, 'name' => 'Economy 23KG total (piece + kg)'],
                ['type' => 'combined', 'cabin_class' => 'Y', 'pieces' => 1, 'weight_kg' => 23, 'max_quantity' => 3, 'price' => 0, 'is_active' => true],
            );

            $icn = $this->flight($hunnu, $b767, $airports['UBN'], $airports['ICN'], 'MR901', now()->addHours(3)->setSecond(0), 215, '07');
            $this->book($icn, $admin, 120);
            $this->counter($icn, $combined);

            $ulg = $this->flight($hunnu, $e190, $airports['UBN'], $airports['ULG'], 'MR902', now()->addDay()->setTime(14, 30), 180, null);
            $this->book($ulg, $admin, 35);
            $ulg->update(['status' => FlightStatus::Scheduled]);

            $this->series($hunnu, $e190, $airports['UBN'], $airports['ULG']);
        });

        $this->command?->info('Test flights ready: MR901 (check-in open, 767), MR902 (tomorrow), series MR950.');
    }

    /** Previous run's flights, bookings and series. */
    private function clear(Airline $airline): void
    {
        $flights = Flight::query()->where('airline_id', $airline->id)
            ->where(fn ($q) => $q->whereIn('flight_number', self::FLIGHTS)->orWhere('flight_number', self::SERIES))->get();

        foreach ($flights as $flight) {
            $bookingIds = DB::table('passenger_segments')->join('passengers', 'passengers.id', '=', 'passenger_segments.passenger_id')
                ->where('passenger_segments.flight_id', $flight->id)->distinct()->pluck('passengers.booking_id');
            DB::table('bookings')->whereIn('id', $bookingIds)->delete();
            CheckinGroup::query()->where('flight_id', $flight->id)->delete();
            $flight->baggageRules()->detach();
            $flight->seatBlocks()->delete();
            $flight->delete();
        }

        FlightSeries::query()->where('airline_id', $airline->id)->where('flight_number', self::SERIES)->delete();
    }

    /** Boeing 767-300ER, two aisles: business 2-2-2, economy 2-3-2, three load zones. */
    private function wideBody(Airline $airline): Aircraft
    {
        $aircraft = Aircraft::query()->firstOrNew(['registration' => 'JU-1767']);
        if ($aircraft->exists && $aircraft->seats()->exists()) {
            return $aircraft;
        }

        $aircraft->fill(['airline_id' => $airline->id, 'type' => 'Boeing 767-300ER', 'total_seats' => 24 + 147, 'max_infants' => 12, 'is_active' => true])->save();
        $aircraft->cabins()->updateOrCreate(['cabin_class' => 'C'], ['seat_count' => 24]);
        $aircraft->cabins()->updateOrCreate(['cabin_class' => 'Y'], ['seat_count' => 147]);

        $generator = app(SeatMapGenerator::class);
        $generator->generateBlock($aircraft, 1, 4, CabinClass::Business, ['left' => 'AC', 'middle' => 'DG', 'right' => 'HK'], 'A');
        $generator->generateBlock($aircraft, 10, 19, CabinClass::Economy, ['left' => 'AC', 'middle' => 'DEG', 'right' => 'HK'], 'B');
        $generator->generateBlock($aircraft, 20, 20, CabinClass::Economy, ['left' => 'AC', 'middle' => 'DEG', 'right' => 'HK'], 'C', true);
        $generator->generateBlock($aircraft, 21, 30, CabinClass::Economy, ['left' => 'AC', 'middle' => 'DEG', 'right' => 'HK'], 'C');

        return $aircraft;
    }

    private function flight(Airline $airline, Aircraft $aircraft, Airport $from, Airport $to, string $number,
        Carbon $departure, int $duration, ?string $gate): Flight
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
            'excess_baggage_price_per_kg' => 18000,
            'online_checkin_enabled' => true,
            'online_checkin_opens_minutes' => 1440,
            'online_checkin_closes_minutes' => 120,
        ]);
        $flight->baggageRules()->sync(BaggageRule::query()->where('airline_id', $airline->id)->where('is_active', true)->pluck('id'));

        return $flight;
    }

    /** Families and solo travellers: adults, some children and infants, a few business passengers. */
    private function book(Flight $flight, User $admin, int $target): void
    {
        $bookings = app(BookingService::class);
        $created = 0;
        while ($created < $target) {
            $size = min($target - $created, mt_rand(1, 10) <= 5 ? 1 : mt_rand(2, 4));
            $lastName = self::LAST_NAMES[array_rand(self::LAST_NAMES)];
            $passengers = [];
            for ($i = 0; $i < $size; $i++) {
                $child = $i > 1 || ($i === 1 && mt_rand(1, 3) === 1);
                $row = $this->person($lastName, $child ? mt_rand(3, 11) : mt_rand(20, 66)) + [
                    'cabin_class' => mt_rand(1, 100) <= 10 ? 'C' : 'Y',
                    'phone' => $i === 0 ? '+976 9'.mt_rand(100, 999).' '.mt_rand(1000, 9999) : null,
                ];
                if ($i === 0 && mt_rand(1, 8) === 1) {
                    $row['infant'] = $this->person($lastName, 0) + ['first_name' => 'BABY'];
                }
                $passengers[] = $row;
            }

            try {
                $bookings->create(['flight_ids' => [$flight->id], 'passengers' => $passengers, 'contact_phone' => $passengers[0]['phone']],
                    $admin, BookingSource::Admin);
                $created += $size;
            } catch (BusinessRuleException) {
                $target--; // duplicate document / capacity
            }
        }
    }

    /** @return array<string, mixed> */
    private function person(string $lastName, int $age): array
    {
        $birth = $age === 0 ? now()->subMonths(mt_rand(3, 18)) : now()->subYears($age)->subDays(mt_rand(0, 300));

        return [
            'first_name' => self::FIRST_NAMES[array_rand(self::FIRST_NAMES)],
            'last_name' => $lastName,
            'gender' => mt_rand(0, 1) ? 'M' : 'F',
            'date_of_birth' => $birth->toDateString(),
            'nationality' => 'MN',
            'document_type' => 'passport',
            'document_number' => 'T'.mt_rand(1000000, 9999999),
            'document_issuing_country' => 'MN',
            'document_expires_at' => now()->addYears(mt_rand(2, 8))->toDateString(),
        ];
    }

    /**
     * The counter half-way: ~40 % checked in across all zones, a group of three sharing the piece + kg
     * allowance (2 bags, 22 kg — 1 kg + more left), one paid and one waived excess bag.
     */
    private function counter(Flight $flight, BaggageRule $combined): void
    {
        $checkin = app(CheckinService::class);
        $baggage = app(BaggageService::class);
        $pieceRules = $flight->baggageRules()->where('type', 'piece')->whereNull('ssr_code')->get()->keyBy('cabin_class');

        $adults = fn () => PassengerSegment::query()->with('passenger')->where('flight_id', $flight->id)
            ->where('status', SegmentStatus::Booked)
            ->whereHas('passenger', fn ($q) => $q->whereNull('infant_of_passenger_id'));

        // a family of 3+ in economy → check-in group with shared bags
        $family = $adults()->where('cabin_class', 'Y')->get()->groupBy('passenger.booking_id')->first(fn ($s) => $s->count() >= 3);
        if ($family) {
            $family = $family->take(3)->map(fn ($s) => $checkin->checkIn($s, null, CheckinChannel::Dcs));
            $group = CheckinGroup::create(['flight_id' => $flight->id, 'name' => 'Family '.Str::title($family->first()->passenger->last_name)]);
            PassengerSegment::query()->whereIn('id', $family->pluck('id'))->update(['checkin_group_id' => $group->id]);
            $baggage->accept($family->first()->refresh(), ['baggage_rule_id' => $combined->id, 'pieces' => 2, 'weight_kg' => 22]);
        }

        // seats spread over the whole cabin (not front to back) so every zone has passengers
        $freeSeats = AircraftSeat::query()->where('aircraft_id', $flight->aircraft_id)->where('is_active', true)
            ->whereNotIn('seat_number', PassengerSegment::query()->where('flight_id', $flight->id)->whereNotNull('seat_number')->pluck('seat_number'))
            ->get()->shuffle()->groupBy(fn (AircraftSeat $seat) => $seat->cabin_class->value);

        $rest = $adults()->get()->shuffle();
        $toCheck = $rest->take((int) round($rest->count() * 0.4));
        $excess = 0;
        foreach ($toCheck as $segment) {
            $seat = $freeSeats->get($segment->cabin_class->value)?->pop();
            $segment = $checkin->checkIn($segment, $seat?->seat_number, CheckinChannel::Dcs);
            $rule = $pieceRules->get($segment->cabin_class->value);
            if (! $rule || ($excess >= 2 && mt_rand(1, 4) === 1)) {
                continue;
            }

            // the first two bags are over the allowance: one paid at the cash desk, one waived by the duty manager
            $extra = match ($excess++) {
                0 => ['weight_kg' => (float) $rule->weight_kg + 4, 'payment_reference' => 'POS-'.mt_rand(100000, 999999)],
                1 => ['weight_kg' => (float) $rule->weight_kg + 3, 'waive_reason' => 'Medical equipment', 'waive_authorized_by' => 'Duty manager Bold'],
                default => ['weight_kg' => mt_rand(10, (int) $rule->weight_kg)],
            };
            $baggage->accept($segment, ['baggage_rule_id' => $rule->id, 'pieces' => 1] + $extra);
        }
    }

    /** Mon / Wed / Fri for three weeks; one flight moved by hand, one cancelled. */
    private function series(Airline $airline, Aircraft $aircraft, Airport $from, Airport $to): void
    {
        $start = now()->startOfWeek()->addWeek();
        $result = app(FlightSeriesService::class)->save([
            'airline_id' => $airline->id,
            'aircraft_id' => $aircraft->id,
            'flight_number' => self::SERIES,
            'origin_airport_id' => $from->id,
            'destination_airport_id' => $to->id,
            'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->addDays(20)->toDateString(),
            'days_of_week' => '1,3,5',
            'departure_time' => '09:00',
            'arrival_time' => '11:00',
            'duration_minutes' => 180,
            'excess_baggage_max_kg' => 50,
            'excess_baggage_price_per_kg' => 6000,
        ]);

        $flights = $result['series']->flights()->orderBy('scheduled_departure_at')->get();
        $flights->get(1)?->forceFill([
            'scheduled_departure_at' => $flights->get(1)->scheduled_departure_at->copy()->addHours(2),
            'scheduled_arrival_at' => $flights->get(1)->scheduled_arrival_at->copy()->addHours(2),
            'is_series_override' => true,
        ])->save();
        $flights->get(3)?->update(['status' => FlightStatus::Cancelled, 'status_reason' => 'Aircraft maintenance']);
    }
}
