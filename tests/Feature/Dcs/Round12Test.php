<?php

namespace Tests\Feature\Dcs;

use App\Enums\BookingSource;
use App\Models\Agency;
use App\Models\Passenger;
use App\Models\Role;
use App\Services\Booking\BookingService;
use App\Services\Documents\ETicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

/** Issue reports round 12: airline admins and agency users, infants in the seat map zones, e-ticket wording. */
class Round12Test extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_an_airline_admin_manages_only_the_users_of_the_agencies_its_airline_created(): void
    {
        // the world's agency is contracted, not created by the airline
        $contracted = $this->makeUser('agency-agent');
        $own = Agency::create(['name' => 'Direct Sales', 'owner_airline_id' => $this->airline->id]);
        $this->airline->agencies()->attach($own->id);
        $direct = $this->makeUser('agency-agent', ['agency_id' => $own->id]);
        $this->actingAsRole('airline-admin');

        $ids = array_column($this->getJson('/api/v1/admin/users?per_page=100')->assertOk()->json('data'), 'id');
        $this->assertContains($direct->id, $ids);
        $this->assertNotContains($contracted->id, $ids);
        $this->putJson("/api/v1/admin/users/{$contracted->id}", ['name' => 'X'])->assertForbidden();

        $agentRole = Role::query()->where('slug', 'agency-agent')->firstOrFail();
        $user = fn (int $agencyId) => ['name' => 'New Agent', 'email' => 'new'.$agencyId.'@example.com', 'password' => 'Secret123!',
            'role_id' => $agentRole->id, 'agency_id' => $agencyId];
        $this->postJson('/api/v1/admin/users', $user($this->agency->id))->assertStatus(422)->assertJsonValidationErrors('agency_id');
        $this->postJson('/api/v1/admin/users', $user($own->id))->assertCreated();
    }

    public function test_a_seat_occupant_with_an_infant_is_marked(): void
    {
        $flight = $this->makeFlight(now()->addHours(3));
        $booking = app(BookingService::class)->create(['flight_ids' => [$flight->id], 'passengers' => [
            $this->passengerPayload(['first_name' => 'Mother', 'infant' => [
                'first_name' => 'Baby', 'last_name' => 'Dorj', 'gender' => 'F', 'date_of_birth' => now()->subMonths(8)->toDateString(),
            ]]),
            $this->passengerPayload(['first_name' => 'Solo']),
        ]], $this->makeUser('system-admin'), BookingSource::Admin);
        [$mother, $solo] = $booking->passengers->whereNull('infant_of_passenger_id')->values()->map(fn ($p) => $p->segments()->first())->all();

        $this->actingAsRole('station-agent');
        $this->postJson("/api/v1/dcs/flights/{$flight->id}/open")->assertOk();
        $this->postJson("/api/v1/dcs/segments/{$mother->id}/check-in")->assertOk();
        $this->postJson("/api/v1/dcs/segments/{$solo->id}/check-in")->assertOk();

        $seats = collect($this->getJson("/api/v1/dcs/flights/{$flight->id}/seat-map")->assertOk()->json('data.seats'))
            ->filter(fn ($s) => isset($s['occupant']))->keyBy(fn ($s) => $s['occupant']['segment_id']);
        $this->assertTrue($seats[$mother->id]['occupant']['has_infant']);
        $this->assertFalse($seats[$solo->id]['occupant']['has_infant']);
    }

    public function test_the_eticket_calls_it_travel_information(): void
    {
        app(BookingService::class)->create(['flight_ids' => [$this->makeFlight()->id], 'passengers' => [$this->passengerPayload()]],
            $this->makeUser('system-admin'), BookingSource::Admin);
        $passenger = Passenger::query()->firstOrFail();

        $mn = view('documents.eticket', app(ETicketService::class)->data($passenger, 'mn'))->render();
        $this->assertStringContainsString('Аяллын мэдээлэл', $mn);
        $this->assertStringNotContainsString('Аяллын чухал мэдээлэл', $mn);
    }
}
