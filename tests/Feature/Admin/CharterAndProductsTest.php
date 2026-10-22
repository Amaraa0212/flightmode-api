<?php

namespace Tests\Feature\Admin;

use App\Enums\BookingSource;
use App\Models\BaggageRule;
use App\Models\PassengerSegment;
use App\Models\SeatPrice;
use App\Services\Booking\BookingService;
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

    public function test_charter_flight_takes_every_bag_free_without_a_rule_or_limit(): void
    {
        $flight = $this->makeFlight(now()->addHours(3), ['service_type' => 'charter', 'excess_baggage_price_per_kg' => 18000]);
        $segment = $this->book($flight);
        $this->actingAsRole('station-agent');
        $this->postJson("/api/v1/dcs/flights/{$flight->id}/open")->assertOk();

        $this->getJson("/api/v1/dcs/segments/{$segment->id}/baggage-rules")->assertOk()
            ->assertJsonPath('data.is_charter', true)->assertJsonCount(0, 'data.rules');

        $this->postJson("/api/v1/dcs/segments/{$segment->id}/baggage", ['pieces' => 4, 'weight_kg' => 95])->assertCreated()
            ->assertJsonPath('data.free_allowance_kg', '95.0')
            ->assertJsonPath('data.excess_price', '0.00')
            ->assertJsonPath('data.baggage_rule', null);
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
}
