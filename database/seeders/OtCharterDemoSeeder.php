<?php

namespace Database\Seeders;

use App\Enums\BookingSource;
use App\Enums\FlightServiceType;
use App\Enums\FlightStatus;
use App\Models\Agency;
use App\Models\AgencyAllocation;
use App\Models\Airline;
use App\Models\Airport;
use App\Models\Flight;
use App\Models\Role;
use App\Models\User;
use App\Services\Allocation\AllocationService;
use App\Services\Booking\BookingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Legacy OT (Oyu Tolgoi) on a plain charter: Hunnu Air charter MR701 UBN → ULG in 6 days, 30 economy seats for
 * "OT Travel" with the Excel import and seat reservation rights, and 6 staff booked with their SAP numbers
 * (person_code, used by online check-in). Agency login: mr.ot.agent@demo.flightmode.mn / DEMO_USERS_PASSWORD.
 * Re-runnable: the charter is re-created with its bookings.
 */
class OtCharterDemoSeeder extends Seeder
{
    private const STAFF = [
        ['BAT', 'ERDENE', 'M', '100231'], ['SARANGEREL', 'BOLD', 'F', '100245'], ['GANZORIG', 'TUMUR', 'M', '100262'],
        ['OYUNAA', 'DASH', 'F', '100278'], ['TEMUUJIN', 'NARAN', 'M', '100291'], ['ANAND', 'BAYAR', 'M', '100305'],
    ];

    public function run(): void
    {
        config(['services.chinggis.agency_ids' => []]);
        $airline = Airline::query()->where('iata_code', 'MR')->first();
        $admin = User::query()->where('email', 'mr.admin@demo.flightmode.mn')->first();
        $ubn = Airport::query()->where('iata_code', 'UBN')->first();
        $ulg = Airport::query()->where('iata_code', 'ULG')->first();
        $aircraft = $airline?->aircraft()->where('registration', 'JU-8250')->first();
        $role = Role::query()->where('slug', 'agency-agent')->first();
        if (! $airline || ! $admin || ! $ubn || ! $ulg || ! $aircraft || ! $role) {
            $this->command?->warn('OtCharterDemoSeeder: needs Hunnu Air (MR) with JU-8250, UBN / ULG and the demo users — run DcsDemoSeeder and DemoUsersSeeder first.');

            return;
        }
        Auth::login($admin);

        $agency = Agency::query()->withTrashed()->updateOrCreate(['code' => 'DEMO-MR-OT'], [
            'name' => 'OT Travel', 'email' => 'ot@demo.flightmode.mn', 'is_active' => true, 'deleted_at' => null,
        ]);
        $agency->airlines()->syncWithoutDetaching([$airline->id => ['is_active' => true, 'can_import_passengers' => true, 'can_assign_seats' => true]]);
        $agent = User::query()->withTrashed()->updateOrCreate(['email' => 'mr.ot.agent@demo.flightmode.mn'], [
            'name' => 'OT agent', 'password' => env('DEMO_USERS_PASSWORD', 'Demo2026!'), 'role_id' => $role->id,
            'agency_id' => $agency->id, 'airline_id' => null, 'airport_id' => null, 'deleted_at' => null,
        ]);

        $flight = DB::transaction(function () use ($airline, $aircraft, $ubn, $ulg) {
            foreach (Flight::query()->where('flight_number', 'MR701')->where('airline_id', $airline->id)->get() as $old) {
                $bookingIds = DB::table('passenger_segments')->join('passengers', 'passengers.id', '=', 'passenger_segments.passenger_id')
                    ->where('passenger_segments.flight_id', $old->id)->distinct()->pluck('passengers.booking_id');
                DB::table('bookings')->whereIn('id', $bookingIds)->delete();
                AgencyAllocation::query()->where('outbound_flight_id', $old->id)->delete();
                $old->delete();
            }

            $departure = Carbon::parse(today('Asia/Ulaanbaatar')->addDays(6)->toDateString().' 07:30', 'Asia/Ulaanbaatar')->utc();

            return Flight::create([
                'airline_id' => $airline->id, 'aircraft_id' => $aircraft->id, 'flight_number' => 'MR701',
                'service_type' => FlightServiceType::Charter, 'origin_airport_id' => $ubn->id, 'destination_airport_id' => $ulg->id,
                'scheduled_departure_at' => $departure, 'scheduled_arrival_at' => $departure->copy()->addMinutes(150),
                'status' => FlightStatus::Scheduled, 'online_checkin_enabled' => true,
                'online_checkin_opens_minutes' => 1440, 'online_checkin_closes_minutes' => 120,
            ]);
        });

        $allocation = app(AllocationService::class)->save([
            'airline_id' => $airline->id, 'agency_id' => $agency->id, 'outbound_flight_id' => $flight->id,
            'seats' => 30, 'cabins' => ['Y' => 30], 'deadline' => today('Asia/Ulaanbaatar')->addDays(5)->toDateString(),
        ]);

        Auth::login($agent);
        foreach (self::STAFF as $i => [$first, $last, $gender, $sap]) {
            app(BookingService::class)->create([
                'allocation_id' => $allocation->id,
                'contact_phone' => '99110000',
                'passengers' => [[
                    'first_name' => $first, 'last_name' => $last, 'gender' => $gender, 'date_of_birth' => (1985 + $i).'-03-1'.$i,
                    'nationality' => 'MN', 'document_type' => 'passport', 'document_number' => 'E'.(5550000 + $i),
                    'document_issuing_country' => 'MN', 'document_expires_at' => now()->addYears(4)->toDateString(),
                    'person_code' => $sap, 'cabin_class' => 'Y',
                ]],
            ], $agent, BookingSource::Agency);
        }
        Auth::logout();

        $this->command?->info("OT charter MR701 {$flight->scheduled_departure_at->timezone('Asia/Ulaanbaatar')->format('Y-m-d H:i')} UBN-ULG: OT Travel 30 Y, ".count(self::STAFF).' staff booked.');
        $this->command?->info('Login mr.ot.agent@demo.flightmode.mn (Excel import + seats); SAP numbers '.implode(', ', array_column(self::STAFF, 3)).' for online check-in.');
    }
}
