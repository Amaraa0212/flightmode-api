<?php

namespace Tests\Feature\Admin;

use App\Models\Aircraft;
use App\Models\BaggageRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

/** Seat rules (legacy seat_rule) and service templates applied to flights. */
class AdminSeatPriceTemplateTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->actingAsRole('airline-admin');
    }

    private function seatPrice(array $attributes): int
    {
        return $this->postJson('/api/v1/admin/seat-prices', array_merge(['name' => 'Rule', 'cabin_class' => 'Y', 'price' => 10000], $attributes))
            ->assertCreated()->json('data.id');
    }

    public function test_seat_prices_by_rows_and_position(): void
    {
        $exit = $this->seatPrice(['name' => 'Exit rows', 'rows' => '7-8', 'position' => 1, 'price' => 45000]);
        $window = $this->seatPrice(['name' => 'Window', 'seat_type' => 'window', 'position' => 5, 'price' => 20000]);
        $front = $this->seatPrice(['name' => 'Front', 'rows' => '3,5-6', 'position' => 5, 'price' => 30000]);
        $any = $this->seatPrice(['name' => 'Economy', 'price' => 5000]);

        $this->getJson("/api/v1/admin/seat-prices/{$exit}")->assertOk()
            ->assertJsonPath('data.name', 'Exit rows')
            ->assertJsonPath('data.rows', '7-8')
            ->assertJsonPath('data.position', 1);
        $this->postJson('/api/v1/admin/seat-prices', ['name' => 'Bad', 'cabin_class' => 'Y', 'price' => 1, 'rows' => '7 to 8'])
            ->assertStatus(422)->assertJsonValidationErrors('rows');

        $flight = $this->makeFlight();
        $flight->seatPrices()->attach([$exit, $window, $front, $any]);
        $prices = collect($this->getJson("/api/v1/admin/flights/{$flight->id}/seat-map")->assertOk()->json('data.seats'))
            ->pluck('price', 'seat_number');

        $this->assertSame('45000.00', $prices['7A']);   // position 1 beats the window rule
        $this->assertSame('30000.00', $prices['3A']);   // rows beat seat type at the same position
        $this->assertSame('20000.00', $prices['4A']);
        $this->assertSame('5000.00', $prices['4B']);
        $this->assertNull($prices['1A'] ?? null);       // business: no rule
    }

    public function test_template_without_aircraft_applies_to_any_flight(): void
    {
        $rule = BaggageRule::create(['airline_id' => $this->airline->id, 'name' => '1PC', 'type' => 'piece', 'pieces' => 1, 'weight_kg' => 23]);
        $price = $this->seatPrice([]);
        $profile = $this->aircraft->seatAssignmentProfiles()->create(['name' => 'Back first']);

        $id = $this->postJson('/api/v1/admin/service-templates', [
            'name' => 'Domestic', 'checkin_opens_minutes' => 720, 'checkin_closes_minutes' => 60,
            'baggage_rule_ids' => [$rule->id], 'seat_price_ids' => [$price],
        ])->assertCreated()
            ->assertJsonPath('data.aircraft_id', null)
            ->assertJsonPath('data.baggage_rule_ids', [$rule->id])
            ->json('data.id');

        // a seat profile needs the template's aircraft
        $this->putJson("/api/v1/admin/service-templates/{$id}", ['seat_assignment_profile_id' => $profile->id])
            ->assertStatus(422)->assertJsonValidationErrors('seat_assignment_profile_id');

        $flight = $this->makeFlight();
        $flight->update(['seat_assignment_profile_id' => $profile->id]);
        $this->postJson("/api/v1/admin/service-templates/{$id}/apply", ['flight_ids' => [$flight->id]])->assertOk();

        $flight->refresh();
        $this->assertTrue($flight->online_checkin_enabled);
        $this->assertSame(720, (int) $flight->online_checkin_opens_minutes);
        $this->assertSame($profile->id, $flight->seat_assignment_profile_id); // kept
        $this->assertSame([$rule->id], $flight->baggageRules()->pluck('baggage_rules.id')->all());
        $this->assertSame([$price], $flight->seatPrices()->pluck('seat_prices.id')->all());
    }

    public function test_template_with_aircraft_and_profile(): void
    {
        $profile = $this->aircraft->seatAssignmentProfiles()->create(['name' => 'Back first']);
        $id = $this->postJson('/api/v1/admin/service-templates', [
            'name' => 'B737', 'aircraft_id' => $this->aircraft->id, 'seat_assignment_profile_id' => $profile->id,
        ])->assertCreated()
            ->assertJsonPath('data.aircraft', 'JU-1001')
            ->assertJsonPath('data.seat_assignment_profile', 'Back first')
            ->json('data.id');

        $other = Aircraft::create(['airline_id' => $this->airline->id, 'registration' => 'JU-2002', 'type' => 'E190', 'total_seats' => 10]);
        $this->postJson("/api/v1/admin/service-templates/{$id}/apply", ['flight_ids' => [$this->makeFlight(null, ['aircraft_id' => $other->id])->id]])
            ->assertStatus(422);

        $flight = $this->makeFlight(null, ['flight_number' => 'TA102']);
        $this->postJson("/api/v1/admin/service-templates/{$id}/apply", ['flight_ids' => [$flight->id]])->assertOk();
        $this->assertSame($profile->id, $flight->refresh()->seat_assignment_profile_id);

        // moving the template to another aircraft drops the profile that belonged to the old one
        $this->putJson("/api/v1/admin/service-templates/{$id}", ['aircraft_id' => $other->id])->assertOk()
            ->assertJsonPath('data.seat_assignment_profile_id', null);
    }
}
