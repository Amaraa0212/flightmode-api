<?php

namespace Tests\Feature\Dcs;

use App\Enums\BookingSource;
use App\Models\BaggageRule;
use App\Models\Flight;
use App\Services\Booking\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

/** Data the DCS app needs besides the core check-in flow: board counts, station, one segment, bag owners. */
class DepartureBoardTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    private Flight $flight;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->flight = $this->makeFlight(now()->addHours(3));
    }

    public function test_flight_list_has_passenger_counts_without_infants(): void
    {
        $admin = $this->makeUser('system-admin');
        $bookings = app(BookingService::class);
        $withInfant = $bookings->create(['flight_ids' => [$this->flight->id], 'passengers' => [
            $this->passengerPayload(['infant' => [
                'first_name' => 'Baby', 'last_name' => 'Dorj', 'gender' => 'F',
                'date_of_birth' => now()->subYear()->toDateString(),
            ]]),
        ]], $admin, BookingSource::Admin);
        $bookings->create(['flight_ids' => [$this->flight->id], 'passengers' => [$this->passengerPayload()]], $admin, BookingSource::Admin);

        $this->actingAsRole('station-agent');
        // the counter re-renders its flight bar from this response
        $this->postJson("/api/v1/dcs/flights/{$this->flight->id}/open")->assertOk()
            ->assertJsonPath('data.status', 'checkin_open')
            ->assertJsonPath('data.airline.iata_code', $this->flight->airline->iata_code)
            ->assertJsonPath('data.origin.iata_code', $this->flight->origin->iata_code);
        $segment = $withInfant->passengers->firstWhere('infant_of_passenger_id', null)->segments()->first();
        $this->postJson("/api/v1/dcs/segments/{$segment->id}/check-in")->assertOk();

        $this->getJson('/api/v1/dcs/flights')->assertOk()
            ->assertJsonPath('data.0.passenger_counts', ['booked' => 2, 'checked_in' => 1, 'boarded' => 0]);
    }

    public function test_me_returns_station_details(): void
    {
        $this->actingAsRole('station-agent');

        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.airport.iata_code', 'UBN')
            ->assertJsonPath('data.airport.timezone', 'Asia/Ulaanbaatar')
            ->assertJsonPath('data.airport.city', 'Ulaanbaatar');
    }

    public function test_single_segment_and_baggage_owner(): void
    {
        $booking = app(BookingService::class)->create(
            ['flight_ids' => [$this->flight->id], 'passengers' => [$this->passengerPayload(['first_name' => 'Sara', 'last_name' => 'Bold'])]],
            $this->makeUser('system-admin'), BookingSource::Admin,
        );
        $segment = $booking->passengers->first()->segments()->first();
        $rule = BaggageRule::create(['airline_id' => $this->airline->id, 'name' => '1PC 23KG', 'type' => 'piece',
            'pieces' => 1, 'weight_kg' => 23, 'max_quantity' => 1]);
        $this->flight->baggageRules()->attach($rule->id);

        $this->actingAsRole('station-agent');
        $this->getJson("/api/v1/dcs/segments/{$segment->id}")->assertOk()
            ->assertJsonPath('data.id', $segment->id)
            ->assertJsonPath('data.passenger.pnr', $booking->pnr)
            ->assertJsonPath('data.passenger.last_name', 'BOLD');

        $this->postJson("/api/v1/dcs/flights/{$this->flight->id}/open")->assertOk();
        $this->postJson("/api/v1/dcs/segments/{$segment->id}/check-in")->assertOk();
        $this->postJson("/api/v1/dcs/segments/{$segment->id}/baggage", ['baggage_rule_id' => $rule->id, 'pieces' => 1, 'weight_kg' => 20])->assertCreated();
        $this->postJson("/api/v1/dcs/flights/{$this->flight->id}/crew-baggage", ['weight_kg' => 12])->assertCreated();

        // Legacy rule: offload the baggage before un-checking
        $this->deleteJson("/api/v1/dcs/segments/{$segment->id}/check-in")
            ->assertStatus(422)->assertJsonPath('code', 'baggage_not_offloaded');

        $bags = collect($this->getJson("/api/v1/dcs/flights/{$this->flight->id}/baggage")->assertOk()->json('data'));
        $this->assertSame(['id' => $booking->passengers->first()->id, 'name' => 'BOLD/SARA', 'pnr' => $booking->pnr],
            $bags->firstWhere('type', 'passenger')['passenger']);
        $this->assertNull($bags->firstWhere('type', 'crew')['passenger']);
    }

    public function test_segment_of_another_station_is_forbidden(): void
    {
        $booking = app(BookingService::class)->create(
            ['flight_ids' => [$this->flight->id], 'passengers' => [$this->passengerPayload()]],
            $this->makeUser('system-admin'), BookingSource::Admin,
        );
        $segment = $booking->passengers->first()->segments()->first();

        $this->actingAsRole('station-agent', ['airport_id' => $this->pqc->id]);
        $this->getJson("/api/v1/dcs/segments/{$segment->id}")->assertForbidden();
    }
}
