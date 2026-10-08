<?php

namespace Tests\Feature\Dcs;

use App\Enums\BookingSource;
use App\Enums\SegmentStatus;
use App\Models\BaggageRule;
use App\Models\Flight;
use App\Models\PassengerSegment;
use App\Services\Booking\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

/** Counter check-in → baggage → boarding pass → gate boarding → departure. */
class CheckinFlowTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    private Flight $flight;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->flight = $this->makeFlight(now()->addHours(3));
    }

    private function book(array $pax = []): PassengerSegment
    {
        $booking = app(BookingService::class)->create(
            ['flight_ids' => [$this->flight->id], 'passengers' => [$this->passengerPayload($pax)]],
            $this->makeUser('system-admin'), BookingSource::Admin,
        );

        return $booking->passengers->first()->segments()->firstOrFail();
    }

    public function test_counter_must_be_opened_before_check_in(): void
    {
        $segment = $this->book();
        $this->actingAsRole('station-agent');

        $this->postJson("/api/v1/dcs/segments/{$segment->id}/check-in")
            ->assertStatus(422)->assertJsonPath('code', 'checkin_not_started');
    }

    public function test_check_in_assigns_seat_and_seat_can_be_changed(): void
    {
        $segment = $this->book();
        $other = $this->book();
        $this->actingAsRole('station-agent');
        $this->postJson("/api/v1/dcs/flights/{$this->flight->id}/open")->assertOk();

        $seat = $this->postJson("/api/v1/dcs/segments/{$segment->id}/check-in")->assertOk()->json('data.seat_number');
        $this->assertNotNull($seat);
        $this->assertSame(SegmentStatus::CheckedIn, $segment->fresh()->status);

        // Nobody can take someone else's seat
        $this->postJson("/api/v1/dcs/segments/{$other->id}/check-in", ['seat_number' => $seat])
            ->assertStatus(422)->assertJsonPath('code', 'seat_not_available');

        $this->postJson("/api/v1/dcs/segments/{$segment->id}/check-in", ['seat_number' => '10F'])
            ->assertOk()->assertJsonPath('data.seat_number', '10F');

        // The counter may move a passenger to another cabin (upgrade); the segment follows the seat
        $this->postJson("/api/v1/dcs/segments/{$segment->id}/check-in", ['seat_number' => '1A'])
            ->assertOk()->assertJsonPath('data.cabin_class', 'C');

        $this->deleteJson("/api/v1/dcs/segments/{$segment->id}/check-in")->assertOk();
        $this->assertNull($segment->fresh()->seat_number);
        $this->assertSame(SegmentStatus::Booked, $segment->fresh()->status);
    }

    public function test_baggage_tags_boarding_pass_and_gate_boarding(): void
    {
        $segment = $this->book(['first_name' => 'Sara', 'last_name' => 'Bold']);
        $rule = BaggageRule::create(['airline_id' => $this->airline->id, 'name' => '1PC 23KG', 'type' => 'piece',
            'pieces' => 1, 'weight_kg' => 23, 'max_quantity' => 1]);
        $this->flight->baggageRules()->attach($rule->id);

        $this->actingAsRole('station-agent');
        $this->postJson("/api/v1/dcs/flights/{$this->flight->id}/open")->assertOk();
        $this->postJson("/api/v1/dcs/segments/{$segment->id}/check-in", ['seat_number' => '3A'])->assertOk();

        $this->getJson("/api/v1/dcs/segments/{$segment->id}/baggage-rules")->assertOk()
            ->assertJsonPath('data.rules.0.id', $rule->id);

        $tags = $this->postJson("/api/v1/dcs/segments/{$segment->id}/baggage", [
            'baggage_rule_id' => $rule->id, 'pieces' => 1, 'weight_kg' => 20,
        ])->assertCreated()->json('data.tags');
        $this->assertCount(1, $tags);
        $this->assertMatchesRegularExpression('/^1123\d{3}001$/', $tags[0]['tag_number']);
        $this->getJson("/api/v1/dcs/baggage-tags/{$tags[0]['id']}")->assertOk()
            ->assertJsonPath('data.is_crew', false)
            ->assertJsonPath('data.passenger_title', 'MR')
            ->assertJsonPath('data.piece', '1/1');

        // piece limit of the rule
        $this->postJson("/api/v1/dcs/segments/{$segment->id}/baggage", [
            'baggage_rule_id' => $rule->id, 'pieces' => 1, 'weight_kg' => 10,
        ])->assertStatus(422);

        $crew = $this->postJson("/api/v1/dcs/flights/{$this->flight->id}/crew-baggage", ['pieces' => 2, 'weight_kg' => 30])
            ->assertCreated()->json('data.tags');
        $this->assertStringStartsWith('1123999', $crew[0]['tag_number']);

        $pass = $this->getJson("/api/v1/dcs/segments/{$segment->id}/boarding-pass")->assertOk()->json('data');
        $this->assertSame('3A', $pass['seat_number']);
        $this->assertSame(1, $pass['baggage']['pieces']);
        $this->assertCount(1, $pass['bag_tags']);
        $this->assertMatchesRegularExpression('/^\d{10}$/', $pass['bag_tags'][0]);
        $this->assertStringStartsWith('M1BOLD/SARA', $pass['barcode']);
        $this->assertStringEndsWith(' 3A '.$segment->id, $pass['barcode']);

        // Gate: only assigned agents
        $gate = $this->actingAsRole('gate-agent');
        $this->postJson("/api/v1/gate/flights/{$this->flight->id}/board", ['barcode' => $pass['barcode']])->assertForbidden();
        $this->flight->gateAgents()->attach($gate->id);

        $this->postJson("/api/v1/gate/flights/{$this->flight->id}/board", ['barcode' => $pass['barcode']])->assertOk();
        $this->assertSame(SegmentStatus::Boarded, $segment->fresh()->status);

        $this->getJson("/api/v1/gate/flights/{$this->flight->id}/passengers?state=boarded")->assertOk()->assertJsonCount(1, 'data');

        $this->postJson("/api/v1/gate/segments/{$segment->id}/offload", ['type' => 'medical', 'reason' => 'Sick'])->assertOk();
        $this->assertSame(SegmentStatus::CheckedIn, $segment->fresh()->status);
        $this->assertSame('medical', $segment->fresh()->offload_type);
    }

    public function test_go_show_passenger_is_added_at_the_counter(): void
    {
        $this->actingAsRole('station-agent');
        $this->postJson("/api/v1/dcs/flights/{$this->flight->id}/open")->assertOk();

        $this->postJson("/api/v1/dcs/flights/{$this->flight->id}/passengers", $this->passengerPayload(['last_name' => 'Gantulga']))
            ->assertCreated()->assertJsonPath('data.last_name', 'GANTULGA');

        $this->assertTrue(PassengerSegment::query()->where('flight_id', $this->flight->id)->where('is_go_show', true)->exists());
    }

    public function test_station_agent_only_sees_flights_from_own_station(): void
    {
        $foreign = $this->makeFlight(now()->addHours(5), ['flight_number' => 'TA102',
            'origin_airport_id' => $this->pqc->id, 'destination_airport_id' => $this->ubn->id]);
        $this->actingAsRole('station-agent');

        $this->getJson("/api/v1/dcs/flights/{$foreign->id}")->assertForbidden();
        $this->getJson("/api/v1/dcs/flights/{$this->flight->id}")->assertOk();
    }
}
