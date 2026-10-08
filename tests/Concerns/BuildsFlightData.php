<?php

namespace Tests\Concerns;

use App\Enums\CabinClass;
use App\Enums\FlightStatus;
use App\Models\Agency;
use App\Models\AgencyAllocation;
use App\Models\Aircraft;
use App\Models\Airline;
use App\Models\Airport;
use App\Models\Country;
use App\Models\Flight;
use App\Models\Role;
use App\Models\User;
use App\Services\Allocation\AllocationService;
use App\Services\Seat\SeatMapGenerator;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/** Small world for feature tests: one airline, two airports, one aircraft with a seat map, one flight. */
trait BuildsFlightData
{
    protected Airline $airline;

    protected Airport $ubn;

    protected Airport $pqc;

    protected Aircraft $aircraft;

    protected Agency $agency;

    protected function buildWorld(): void
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $mn = Country::create(['name' => 'Mongolia', 'alpha2' => 'MN', 'alpha3' => 'MNG']);
        $vn = Country::create(['name' => 'Viet Nam', 'alpha2' => 'VN', 'alpha3' => 'VNM']);

        $this->airline = Airline::create(['name' => 'Test Air', 'iata_code' => 'TA', 'numeric_code' => '123']);
        $this->ubn = Airport::create(['iata_code' => 'UBN', 'name' => 'Chinggis Khaan', 'city' => 'Ulaanbaatar', 'country_id' => $mn->id, 'timezone' => 'Asia/Ulaanbaatar']);
        $this->pqc = Airport::create(['iata_code' => 'PQC', 'name' => 'Phu Quoc', 'city' => 'Phu Quoc', 'country_id' => $vn->id, 'timezone' => 'Asia/Ho_Chi_Minh']);

        $this->aircraft = Aircraft::create(['airline_id' => $this->airline->id, 'registration' => 'JU-1001', 'type' => 'B737', 'total_seats' => 56, 'max_infants' => 5]);
        $this->aircraft->cabins()->create(['cabin_class' => 'C', 'seat_count' => 8]);
        $this->aircraft->cabins()->create(['cabin_class' => 'Y', 'seat_count' => 48]);
        $generator = app(SeatMapGenerator::class);
        $generator->generateBlock($this->aircraft, 1, 2, CabinClass::Business, ['left' => 'AC', 'right' => 'DF'], 'A');
        $generator->generateBlock($this->aircraft, 3, 6, CabinClass::Economy, ['left' => 'ABC', 'right' => 'DEF'], 'B');
        $generator->generateBlock($this->aircraft, 7, 10, CabinClass::Economy, ['left' => 'ABC', 'right' => 'DEF'], 'C');

        $this->agency = Agency::create(['name' => 'Test Travel', 'api_enabled' => true]);
        $this->agency->airlines()->attach($this->airline->id, ['is_active' => true]);
    }

    protected function makeFlight(?Carbon $departure = null, array $attributes = []): Flight
    {
        $departure ??= now()->addDays(3)->setTime(8, 0);

        return Flight::create(array_merge([
            'airline_id' => $this->airline->id,
            'aircraft_id' => $this->aircraft->id,
            'flight_number' => 'TA101',
            'origin_airport_id' => $this->ubn->id,
            'destination_airport_id' => $this->pqc->id,
            'scheduled_departure_at' => $departure,
            'scheduled_arrival_at' => $departure->copy()->addHours(6),
            'status' => FlightStatus::Scheduled,
        ], $attributes));
    }

    protected function makeAllocation(Flight $flight, int $seats = 10, array $cabins = ['Y' => 10], array $extra = []): AgencyAllocation
    {
        return app(AllocationService::class)->save(array_merge([
            'airline_id' => $this->airline->id,
            'agency_id' => $this->agency->id,
            'outbound_flight_id' => $flight->id,
            'seats' => $seats,
            'deadline' => $flight->scheduled_departure_at->copy()->subDay()->toDateString(),
            'cabins' => $cabins,
        ], $extra));
    }

    protected function makeUser(string $roleSlug, array $attributes = []): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $defaults = match ($role->scope->value) {
            'airline' => ['airline_id' => $this->airline->id],
            'airport' => ['airline_id' => $this->airline->id, 'airport_id' => $this->ubn->id],
            'agency' => ['agency_id' => $this->agency->id],
            default => [],
        };

        return User::factory()->create(array_merge(['role_id' => $role->id], $defaults, $attributes));
    }

    protected function actingAsRole(string $roleSlug, array $attributes = []): User
    {
        $user = $this->makeUser($roleSlug, $attributes);
        Sanctum::actingAs($user);

        return $user;
    }

    /** @return array<string, mixed> */
    protected function passengerPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Bat',
            'last_name' => 'Dorj',
            'gender' => 'M',
            'date_of_birth' => '1990-05-01',
            'nationality' => 'MN',
            'document_type' => 'passport',
            'document_number' => 'E'.random_int(1000000, 9999999),
            'document_issuing_country' => 'MN',
            'document_expires_at' => now()->addYears(3)->toDateString(),
            'cabin_class' => 'Y',
            'email' => 'bat@example.com',
        ], $overrides);
    }
}
