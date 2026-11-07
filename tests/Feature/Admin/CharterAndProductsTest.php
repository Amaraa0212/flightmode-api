<?php

namespace Tests\Feature\Admin;

use App\Enums\BookingSource;
use App\Models\Airline;
use App\Models\BaggageRule;
use App\Models\Flight;
use App\Models\PassengerSegment;
use App\Models\SeatPrice;
use App\Services\Booking\BookingService;
use App\Services\Flight\FlightStatistics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

/** Charter / scheduled flights, free charter baggage, cargo tags, per-flight shop items and agency sales. */
class CharterAndProductsTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function book($flight, array $pax = []): PassengerSegment
    {
        $booking = app(BookingService::class)->create(
            ['flight_ids' => [$flight->id], 'passengers' => [$this->passengerPayload($pax)]],
            $this->makeUser('system-admin'), BookingSource::Admin,
        );

        return $booking->passengers->first()->segments()->firstOrFail();
    }

    public function test_flights_are_scheduled_or_charter_and_filtered_by_type(): void
    {
        $this->actingAsRole('airline-admin');
        $scheduled = $this->makeFlight();
        $charter = $this->makeFlight(now()->addDays(4), ['flight_number' => 'TA900', 'service_type' => 'charter']);

        $range = 'from='.now()->toDateString().'&to='.now()->addWeek()->toDateString();
        $this->getJson("/api/v1/admin/flights?{$range}&service_type=charter")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $charter->id)->assertJsonPath('data.0.service_type', 'charter');
        $this->getJson("/api/v1/admin/flights/{$scheduled->id}")->assertJsonPath('data.service_type', 'scheduled');

        $this->putJson("/api/v1/admin/flights/{$scheduled->id}", ['service_type' => 'charter'])->assertOk()
            ->assertJsonPath('data.service_type', 'charter');
        $this->putJson("/api/v1/admin/flights/{$scheduled->id}", ['service_type' => 'adhoc'])->assertStatus(422);
    }

    public function test_no_baggage_rule_charter_takes_every_bag_free_without_a_rule_or_limit(): void
    {
        $flight = $this->makeFlight(now()->addHours(3), ['service_type' => 'charter', 'no_baggage_rule' => true, 'excess_baggage_price_per_kg' => 18000]);
        $segment = $this->book($flight);
        $this->actingAsRole('station-agent');
        $this->postJson("/api/v1/dcs/flights/{$flight->id}/open")->assertOk();

        $this->getJson("/api/v1/dcs/segments/{$segment->id}/baggage-rules")->assertOk()
            ->assertJsonPath('data.no_baggage_rule', true)->assertJsonCount(0, 'data.rules');

        $this->postJson("/api/v1/dcs/segments/{$segment->id}/baggage", ['pieces' => 4, 'weight_kg' => 95])->assertCreated()
            ->assertJsonPath('data.free_allowance_kg', '95.0')
            ->assertJsonPath('data.excess_price', '0.00')
            ->assertJsonPath('data.baggage_rule', null);
    }

    public function test_charter_without_the_flag_prices_bags_by_baggage_rule(): void
    {
        $flight = $this->makeFlight(now()->addHours(3), ['service_type' => 'charter', 'excess_baggage_price_per_kg' => 18000]);
        $rule = BaggageRule::create(['airline_id' => $this->airline->id, 'name' => '1PC 23KG', 'type' => 'piece', 'pieces' => 1, 'weight_kg' => 23, 'price' => 0, 'is_active' => true]);
        $flight->baggageRules()->attach($rule->id);
        $segment = $this->book($flight);
        $this->actingAsRole('station-agent');
        $this->postJson("/api/v1/dcs/flights/{$flight->id}/open")->assertOk();

        $this->getJson("/api/v1/dcs/segments/{$segment->id}/baggage-rules")->assertOk()
            ->assertJsonPath('data.no_baggage_rule', false)->assertJsonCount(1, 'data.rules');
        $this->postJson("/api/v1/dcs/segments/{$segment->id}/baggage", ['pieces' => 1, 'weight_kg' => 20])
            ->assertStatus(422)->assertJsonValidationErrors('baggage_rule_id');
        $this->postJson("/api/v1/dcs/segments/{$segment->id}/baggage/quote", ['baggage_rule_id' => $rule->id, 'pieces' => 1, 'weight_kg' => 25])
            ->assertOk()->assertJsonPath('data.excess_kg', 2);
    }

    public function test_quota_is_given_on_charter_flights_only(): void
    {
        $scheduled = $this->makeFlight();
        $charter = $this->makeFlight(now()->addDays(4), ['flight_number' => 'TA900', 'service_type' => 'charter']);
        $this->actingAsRole('airline-admin');
        $body = fn (Flight $f) => ['agency_id' => $this->agency->id, 'outbound_flight_id' => $f->id, 'seats' => 5, 'cabins' => ['Y' => 5],
            'deadline' => $f->scheduled_departure_at->copy()->subDay()->toDateString()];

        $this->postJson('/api/v1/admin/allocations', $body($scheduled))->assertStatus(422)->assertJsonPath('code', 'quota_charter_only');
        $this->postJson("/api/v1/admin/flights/{$scheduled->id}/allocations/distribute", ['rows' => [
            ['agency_id' => $this->agency->id, 'mode' => 'fixed', 'seats' => 5, 'cabins' => ['Y' => 5]],
        ]])->assertStatus(422)->assertJsonPath('code', 'quota_charter_only');
        $this->postJson('/api/v1/admin/allocations', $body($charter))->assertCreated();

        // quota already on a scheduled flight keeps working
        $old = $this->makeAllocation($scheduled);
        $this->putJson("/api/v1/admin/allocations/{$old->id}", $body($scheduled))->assertOk();
    }

    public function test_rush_bag_gets_its_own_tag_with_the_typed_details(): void
    {
        $flight = $this->makeFlight(now()->addHours(3));
        $this->actingAsRole('station-agent');
        $this->postJson("/api/v1/dcs/flights/{$flight->id}/open")->assertOk();

        $url = "/api/v1/dcs/flights/{$flight->id}/rush";
        $this->postJson($url, ['weight_kg' => 18])->assertStatus(422)
            ->assertJsonValidationErrors(['passenger_name', 'original_flight', 'phone']);

        $bag = $this->postJson($url, ['passenger_name' => 'John Smith', 'original_flight' => 'om 301 / 07 nov', 'phone' => '+976 99112233', 'weight_kg' => 18])
            ->assertCreated()->assertJsonPath('data.type', 'rush')->assertJsonPath('data.rush_passenger_name', 'JOHN SMITH')->json('data');
        $this->assertStringContainsString('997', $bag['tags'][0]['tag_number']);

        $this->getJson("/api/v1/dcs/baggage-tags/{$bag['tags'][0]['id']}")->assertOk()
            ->assertJsonPath('data.is_rush', true)->assertJsonPath('data.passenger', 'JOHN SMITH')
            ->assertJsonPath('data.original_flight', 'OM 301 / 07 NOV')->assertJsonPath('data.phone', '+976 99112233');
        $this->assertSame(18.0, app(FlightStatistics::class)->baggage($flight)['rush_weight_kg']);
    }

    public function test_cargo_gets_its_own_tags_and_totals(): void
    {
        $flight = $this->makeFlight(now()->addHours(3));
        $this->actingAsRole('station-agent');
        $this->postJson("/api/v1/dcs/flights/{$flight->id}/open")->assertOk();

        $bag = $this->postJson("/api/v1/dcs/flights/{$flight->id}/cargo", ['pieces' => 2, 'weight_kg' => 60, 'description' => 'Mail'])
            ->assertCreated()->assertJsonPath('data.type', 'cargo')->json('data');
        $this->assertStringContainsString('998', $bag['tags'][0]['tag_number']);

        $this->getJson("/api/v1/dcs/baggage-tags/{$bag['tags'][0]['id']}")->assertOk()
            ->assertJsonPath('data.passenger', 'CARGO')->assertJsonPath('data.is_cargo', true)->assertJsonPath('data.is_crew', false);

        $this->actingAsRole('airline-admin');
        $this->getJson("/api/v1/admin/flights/{$flight->id}/statistics")->assertOk()
            ->assertJsonPath('data.baggage.cargo_pieces', 2)
            ->assertJsonPath('data.baggage.crew_pieces', 0)
            ->assertJsonPath('data.baggage.pieces', 0);
    }

    public function test_shop_items_are_edited_per_flight(): void
    {
        $this->actingAsRole('airline-admin');
        $flight = $this->makeFlight();
        $rule = BaggageRule::create(['airline_id' => $this->airline->id, 'name' => '1PC 23KG', 'type' => 'piece', 'pieces' => 1, 'weight_kg' => 23]);
        $price = SeatPrice::create(['airline_id' => $this->airline->id, 'cabin_class' => 'Y', 'price' => 30000]);

        $this->putJson("/api/v1/admin/flights/{$flight->id}/products", ['baggage_rule_ids' => [$rule->id], 'seat_price_ids' => [$price->id]])
            ->assertOk()->assertJsonPath('data.online_checkin.baggage_rule_ids', [$rule->id])
            ->assertJsonPath('data.online_checkin.seat_price_ids', [$price->id]);
        $this->assertSame([$rule->id], $flight->baggageRules()->pluck('baggage_rules.id')->all());

        $this->putJson("/api/v1/admin/flights/{$flight->id}/products", ['baggage_rule_ids' => [], 'seat_price_ids' => []])->assertOk();
        $this->assertSame(0, $flight->baggageRules()->count());

        $this->actingAsRole('station-agent');
        $this->putJson("/api/v1/admin/flights/{$flight->id}/products", ['baggage_rule_ids' => [], 'seat_price_ids' => []])->assertForbidden();
    }

    public function test_flight_board_shows_seats_sold_per_agency(): void
    {
        $flight = $this->makeFlight(now()->addHours(5));
        $allocation = $this->makeAllocation($flight, 10);
        app(BookingService::class)->create(['allocation_id' => $allocation->id, 'passengers' => [
            $this->passengerPayload(['document_number' => 'E1000001']), $this->passengerPayload(['document_number' => 'E1000002', 'first_name' => 'ANU']),
        ]], $this->makeUser('system-admin'), BookingSource::Admin);
        $this->book($flight, ['document_number' => 'E1000003', 'first_name' => 'BOLD']);

        $this->actingAsRole('airline-admin');
        $row = collect($this->getJson('/api/v1/admin/flight-board?date='.$flight->scheduled_departure_at->toDateString())->assertOk()->json('data'))
            ->firstWhere('id', $flight->id);

        $this->assertSame([
            ['agency' => ['id' => $this->agency->id, 'name' => 'Test Travel'], 'seats' => 10, 'sold' => 2],
            ['agency' => null, 'seats' => 0, 'sold' => 1],
        ], $row['agency_sales']);
    }

    public function test_gate_users_of_a_flight_are_only_those_of_its_airline(): void
    {
        $flight = $this->makeFlight();
        $own = $this->makeUser('station-agent');
        $other = Airline::create(['name' => 'Other Air', 'iata_code' => 'OT', 'numeric_code' => '456']);
        $foreign = $this->makeUser('station-agent', ['airline_id' => $other->id]);
        $this->actingAsRole('airline-admin');

        $ids = collect($this->getJson("/api/v1/admin/flights/{$flight->id}/gate-agents/candidates")->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($own->id));
        $this->assertFalse($ids->contains($foreign->id));

        $this->putJson("/api/v1/admin/flights/{$flight->id}/gate-agents", ['user_ids' => [$foreign->id]])
            ->assertStatus(422)->assertJsonValidationErrors('user_ids');
    }

    public function test_a_scheduled_flight_with_tickets_cannot_become_charter(): void
    {
        $flight = $this->makeFlight();
        $allocation = $this->makeAllocation($flight);
        $this->actingAsRole('agency-agent');
        $this->postJson('/api/v1/agency/bookings', ['allocation_id' => $allocation->id, 'passengers' => [$this->passengerPayload()]])->assertCreated();

        $this->actingAsRole('airline-admin');
        $this->putJson("/api/v1/admin/flights/{$flight->id}", ['service_type' => 'charter'])
            ->assertStatus(422)->assertJsonPath('code', 'flight_has_tickets');
        $this->assertSame('scheduled', $flight->fresh()->service_type->value);
    }

    public function test_deleting_a_series_keeps_flights_with_agency_quota(): void
    {
        $this->actingAsRole('airline-admin');
        $id = $this->postJson('/api/v1/admin/flight-series', [
            'flight_number' => 'MN700', 'aircraft_id' => $this->aircraft->id,
            'origin_airport_id' => $this->ubn->id, 'destination_airport_id' => $this->pqc->id,
            'period_start' => today()->addDays(2)->toDateString(), 'period_end' => today()->addDays(4)->toDateString(),
            'days_of_week' => [1, 2, 3, 4, 5, 6, 7], 'departure_time' => '09:00', 'arrival_time' => '14:00',
        ])->assertCreated()->json('data.id');
        $flights = Flight::query()->where('flight_series_id', $id)->orderBy('scheduled_departure_at')->get();
        $this->assertCount(3, $flights);
        $this->makeAllocation($flights[0]);

        $this->deleteJson("/api/v1/admin/flight-series/{$id}")->assertOk();

        $this->assertNotSoftDeleted($flights[0]);
        $this->assertSoftDeleted($flights[1]);
    }
}
