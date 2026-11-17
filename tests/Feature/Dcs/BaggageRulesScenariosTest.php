<?php

namespace Tests\Feature\Dcs;

use App\Enums\ActivityChannel;
use App\Enums\BookingSource;
use App\Models\Airline;
use App\Models\BaggageRule;
use App\Models\Flight;
use App\Models\PassengerSegment;
use App\Services\Booking\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

/** Every baggage rule the counter uses, through the DCS API (rules → quote → accept → remove). */
class BaggageRulesScenariosTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    private Flight $flight;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->flight = $this->makeFlight(now()->addHours(3), ['excess_baggage_price_per_kg' => 10000, 'excess_baggage_max_kg' => 15]);
    }

    private function rule(array $attributes): BaggageRule
    {
        $rule = BaggageRule::create($attributes + ['airline_id' => $this->airline->id, 'is_active' => true]);
        $this->flight->baggageRules()->attach($rule->id);

        return $rule;
    }

    private function book(array $pax = []): PassengerSegment
    {
        $booking = app(BookingService::class)->create(
            ['flight_ids' => [$this->flight->id], 'passengers' => [$this->passengerPayload($pax)]],
            $this->makeUser('system-admin'), BookingSource::Admin,
        );

        return $booking->passengers->first()->segments()->firstOrFail();
    }

    private function open(): void
    {
        $this->actingAsRole('station-agent');
        $this->postJson("/api/v1/dcs/flights/{$this->flight->id}/open")->assertOk();
    }

    private function bag(PassengerSegment $s, BaggageRule $r, int $pieces, float $kg, array $extra = [])
    {
        return $this->postJson("/api/v1/dcs/segments/{$s->id}/baggage", ['baggage_rule_id' => $r->id, 'pieces' => $pieces, 'weight_kg' => $kg] + $extra);
    }

    private function quote(PassengerSegment $s, BaggageRule $r, int $pieces, float $kg)
    {
        return $this->postJson("/api/v1/dcs/segments/{$s->id}/baggage/quote", ['baggage_rule_id' => $r->id, 'pieces' => $pieces, 'weight_kg' => $kg]);
    }

    public function test_the_counter_sees_the_rules_of_the_passengers_cabin_and_the_free_ssr(): void
    {
        $economy = $this->rule(['name' => 'Y 1PC 23', 'type' => 'piece', 'pieces' => 1, 'weight_kg' => 23, 'max_quantity' => 2, 'cabin_class' => 'Y']);
        $business = $this->rule(['name' => 'C 2PC 32', 'type' => 'piece', 'pieces' => 2, 'weight_kg' => 32, 'max_quantity' => 2, 'cabin_class' => 'C']);
        $free = $this->rule(['name' => 'FBAG', 'type' => 'piece', 'pieces' => 1, 'weight_kg' => 10, 'ssr_code' => 'FBAG', 'cabin_class' => 'C']);
        $segment = $this->book();
        $this->open();

        $ids = array_column($this->getJson("/api/v1/dcs/segments/{$segment->id}/baggage-rules")->assertOk()->json('data.rules'), 'id');
        $this->assertContains($economy->id, $ids);
        $this->assertContains($free->id, $ids);          // the free-bag SSR whatever the cabin
        $this->assertNotContains($business->id, $ids);
    }

    public function test_piece_rule_allowance_price_and_piece_limit(): void
    {
        $rule = $this->rule(['name' => '1PC 23', 'type' => 'piece', 'pieces' => 1, 'weight_kg' => 23, 'max_quantity' => 2, 'price' => 5000]);
        $segment = $this->book();
        $this->open();

        // 2 pieces at once: 46 kg free, priced per piece
        $this->quote($segment, $rule, 2, 40)->assertOk()
            ->assertJsonPath('data.free_allowance_kg', 46)->assertJsonPath('data.excess_kg', 0)->assertJsonPath('data.price', 10000);
        $this->bag($segment, $rule, 2, 40)->assertCreated()->assertJsonCount(2, 'data.tags');
        // the third piece is over max_quantity
        $this->bag($segment, $rule, 1, 5)->assertStatus(422)->assertJsonPath('code', 'baggage_limit_exceeded');
    }

    public function test_excess_rule_and_weight_rule_charge_every_kg(): void
    {
        $ebag = $this->rule(['name' => 'EBAG', 'type' => 'piece', 'pieces' => 1, 'weight_kg' => 0, 'ssr_code' => 'EBAG']);
        $weight = $this->rule(['name' => 'Per kg', 'type' => 'weight', 'pieces' => 0, 'weight_kg' => 0]);
        $segment = $this->book();
        $this->open();

        $this->quote($segment, $ebag, 1, 7)->assertOk()->assertJsonPath('data.free_allowance_kg', 0)
            ->assertJsonPath('data.excess_kg', 7)->assertJsonPath('data.excess_price', 70000);
        $this->quote($segment, $weight, 1, 4)->assertOk()->assertJsonPath('data.excess_kg', 4)->assertJsonPath('data.excess_price', 40000);
    }

    public function test_prepaid_xbag_is_free_and_limited_to_the_pieces_paid(): void
    {
        $xbag = $this->rule(['name' => 'XBAG 23', 'type' => 'piece', 'pieces' => 1, 'weight_kg' => 23, 'ssr_code' => 'XBAG', 'price' => 30000]);
        $segment = $this->book(['prepaid_baggage' => ['pieces' => 1, 'weight_kg' => 23]]);
        $this->open();

        $this->getJson("/api/v1/dcs/segments/{$segment->id}/baggage-rules")->assertJsonPath('data.prepaid_pieces', 1);
        $this->quote($segment, $xbag, 1, 20)->assertOk()->assertJsonPath('data.price', 0)->assertJsonPath('data.excess_price', 0);
        $this->bag($segment, $xbag, 1, 20)->assertCreated();
        $this->bag($segment, $xbag, 1, 10)->assertStatus(422)->assertJsonPath('code', 'baggage_limit_exceeded');
    }

    public function test_a_check_in_group_shares_one_combined_pool(): void
    {
        $rule = $this->rule(['name' => 'Pool 23', 'type' => 'combined', 'pieces' => 1, 'weight_kg' => 23, 'max_quantity' => 3]);
        $lead = $this->book(['first_name' => 'Nandin', 'document_number' => 'E7300001']);
        $kid = $this->book(['first_name' => 'Anu', 'document_number' => 'E7300002']);
        $this->open();
        $this->postJson("/api/v1/dcs/segments/{$lead->id}/check-in")->assertOk();
        $this->postJson("/api/v1/dcs/flights/{$this->flight->id}/groups", ['segment_ids' => [$lead->id, $kid->id]])->assertCreated();

        // 2 × 23 = 46 kg for the two of them, used by whoever's bags
        $this->bag($lead->refresh(), $rule, 2, 40)->assertCreated();
        $this->quote($kid->refresh(), $rule, 1, 10)->assertOk()
            ->assertJsonPath('data.free_allowance_kg', 6)->assertJsonPath('data.excess_kg', 4);
    }

    public function test_excess_must_be_settled_and_the_cap_counts_every_bag_until_one_is_removed(): void
    {
        $rule = $this->rule(['name' => '1PC 20', 'type' => 'piece', 'pieces' => 1, 'weight_kg' => 20, 'max_quantity' => 3]);
        $segment = $this->book();
        $this->open();

        $this->bag($segment, $rule, 1, 30, ['waive_reason' => 'VIP'])->assertStatus(422)->assertJsonPath('code', 'waive_authorizer_required');
        $first = $this->bag($segment, $rule, 1, 30, ['waive_reason' => 'VIP', 'waive_authorized_by' => 'Duty manager'])
            ->assertCreated()->assertJsonPath('data.waived_amount', '100000.00')->json('data.id');
        // 10 kg excess so far, cap 15
        $this->bag($segment, $rule, 1, 26, ['payment_reference' => 'POS-1'])->assertStatus(422)->assertJsonPath('code', 'excess_limit_exceeded');
        $this->deleteJson("/api/v1/dcs/baggage/{$first}")->assertOk();
        $this->bag($segment, $rule, 1, 26, ['payment_reference' => 'POS-1'])->assertCreated()->assertJsonPath('data.excess_price', '60000.00');
    }

    public function test_quote_and_accepted_bag_agree(): void
    {
        $rule = $this->rule(['name' => '1PC 23', 'type' => 'piece', 'pieces' => 1, 'weight_kg' => 23, 'max_quantity' => 2]);
        $segment = $this->book();
        $this->open();

        $quote = $this->quote($segment, $rule, 1, 27.5)->assertOk()->json('data');
        $bag = $this->bag($segment, $rule, 1, 27.5, ['payment_reference' => 'POS-9'])->assertCreated()->json('data');
        $this->assertEquals($quote['free_allowance_kg'], (float) $bag['free_allowance_kg']);
        $this->assertEquals($quote['excess_price'], (float) $bag['excess_price']);
    }

    public function test_bags_are_refused_when_the_counter_is_closed_the_rule_is_another_airlines_or_the_passenger_cancelled(): void
    {
        $rule = $this->rule(['name' => '1PC 23', 'type' => 'piece', 'pieces' => 1, 'weight_kg' => 23, 'max_quantity' => 2]);
        $other = BaggageRule::create(['airline_id' => Airline::create(['name' => 'Other', 'iata_code' => 'OT', 'numeric_code' => '456'])->id,
            'name' => 'Other 1PC', 'type' => 'piece', 'pieces' => 1, 'weight_kg' => 40, 'is_active' => true]);
        $segment = $this->book();
        $this->actingAsRole('station-agent');

        $this->bag($segment, $rule, 1, 10)->assertStatus(422);                     // counter not open
        $this->postJson("/api/v1/dcs/flights/{$this->flight->id}/open")->assertOk();
        $this->bag($segment, $other, 1, 10)->assertStatus(422)->assertJsonValidationErrors('baggage_rule_id');

        app(BookingService::class)->cancelPassenger($segment->passenger, ActivityChannel::Admin);
        $this->bag($segment->refresh(), $rule, 1, 10)->assertStatus(422)->assertJsonPath('code', 'baggage_not_allowed');
    }

    public function test_no_baggage_rule_works_on_charters_only(): void
    {
        $this->flight->update(['no_baggage_rule' => true]);   // scheduled flight: the flag does not apply
        $segment = $this->book();
        $this->open();
        $this->getJson("/api/v1/dcs/segments/{$segment->id}/baggage-rules")->assertJsonPath('data.no_baggage_rule', false);
        $this->postJson("/api/v1/dcs/segments/{$segment->id}/baggage", ['pieces' => 1, 'weight_kg' => 60])
            ->assertStatus(422)->assertJsonValidationErrors('baggage_rule_id');

        $this->flight->update(['service_type' => 'charter']);
        $this->postJson("/api/v1/dcs/segments/{$segment->id}/baggage", ['pieces' => 2, 'weight_kg' => 60])
            ->assertCreated()->assertJsonPath('data.excess_price', '0.00');
    }
}
