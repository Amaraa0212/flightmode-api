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
        $this->flight = $this->makeFlight(now()->addHours(3));
        // the flight's excess prices: an extra piece 50 000, a kg 10 000
        $this->rule(['name' => 'EBAG piece', 'type' => 'piece', 'pieces' => 1, 'weight_kg' => 0, 'price' => 50000, 'ssr_code' => 'EBAG']);
        $this->rule(['name' => 'EBAG kg', 'type' => 'weight', 'weight_kg' => 1, 'price' => 10000, 'ssr_code' => 'EBAG']);
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

    public function test_pc_rule_free_pieces_extra_piece_and_overweight(): void
    {
        $rule = $this->rule(['name' => '1PC 23', 'type' => 'piece', 'pieces' => 1, 'weight_kg' => 23, 'max_quantity' => 1]);
        $segment = $this->book();
        $this->open();

        // within the free piece and its 23 kg: free
        $this->quote($segment, $rule, 1, 23)->assertOk()->assertJsonPath('data.excess_price', 0)->assertJsonPath('data.free_allowance_kg', 23);
        // 2 pieces of 19 kg in total: the second is an excess piece, priced as a piece (not by kg)
        $this->quote($segment, $rule, 2, 19)->assertOk()
            ->assertJsonPath('data.excess_pieces', 1)->assertJsonPath('data.excess_kg', 0)->assertJsonPath('data.excess_price', 50000);
        // one piece of 26 kg: 3 kg over the piece's 23 kg, priced per kg
        $this->quote($segment, $rule, 1, 26)->assertOk()
            ->assertJsonPath('data.excess_pieces', 0)->assertJsonPath('data.excess_kg', 3)->assertJsonPath('data.excess_price', 30000);

        // the free piece is used by the first bag: the next bag is an excess piece
        $this->bag($segment, $rule, 1, 20)->assertCreated()->assertJsonPath('data.excess_pieces', 0);
        $this->quote($segment, $rule, 1, 10)->assertOk()->assertJsonPath('data.excess_pieces', 1)->assertJsonPath('data.excess_price', 50000);
    }

    public function test_kg_rule_counts_the_total_kg_however_many_pieces(): void
    {
        $rule = $this->rule(['name' => '20 KG', 'type' => 'weight', 'pieces' => 1, 'weight_kg' => 20]);
        $segment = $this->book();
        $this->open();

        // 2 pieces, 19 kg in total: free (not 2 × 20)
        $this->quote($segment, $rule, 2, 19)->assertOk()
            ->assertJsonPath('data.free_allowance_kg', 19)->assertJsonPath('data.excess_kg', 0)->assertJsonPath('data.excess_price', 0);
        // 21 kg: 1 kg excess at the EBAG kg price
        $this->quote($segment, $rule, 2, 21)->assertOk()
            ->assertJsonPath('data.free_allowance_kg', 20)->assertJsonPath('data.excess_kg', 1)->assertJsonPath('data.excess_price', 10000);
        // the pool is shared by every bag of the passenger
        $this->bag($segment, $rule, 1, 15)->assertCreated();
        $this->quote($segment, $rule, 1, 8)->assertOk()->assertJsonPath('data.free_allowance_kg', 5)->assertJsonPath('data.excess_kg', 3);
    }

    public function test_excess_without_an_ebag_rule_is_free(): void
    {
        $this->flight->baggageRules()->detach();
        $rule = $this->rule(['name' => '1PC 23', 'type' => 'piece', 'pieces' => 1, 'weight_kg' => 23]);
        $segment = $this->book();
        $this->open();

        $this->quote($segment, $rule, 2, 50)->assertOk()
            ->assertJsonPath('data.excess_pieces', 1)->assertJsonPath('data.excess_kg', 27)->assertJsonPath('data.excess_price', 0);
        $this->bag($segment, $rule, 2, 50)->assertCreated();   // nothing to pay
    }

    public function test_excess_rule_chosen_directly_charges_everything(): void
    {
        $segment = $this->book();
        $this->open();
        $piece = BaggageRule::query()->where('name', 'EBAG piece')->firstOrFail();
        $kg = BaggageRule::query()->where('name', 'EBAG kg')->firstOrFail();

        $this->quote($segment, $piece, 2, 30)->assertOk()->assertJsonPath('data.excess_pieces', 2)->assertJsonPath('data.excess_price', 100000);
        $this->quote($segment, $kg, 1, 7)->assertOk()->assertJsonPath('data.free_allowance_kg', 0)
            ->assertJsonPath('data.excess_kg', 7)->assertJsonPath('data.excess_price', 70000);
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

    public function test_a_check_in_group_shares_one_kg_pool(): void
    {
        $rule = $this->rule(['name' => 'Pool 23', 'type' => 'combined', 'pieces' => 1, 'weight_kg' => 23]);
        $lead = $this->book(['first_name' => 'Nandin', 'document_number' => 'E7300001']);
        $kid = $this->book(['first_name' => 'Anu', 'document_number' => 'E7300002']);
        $this->open();
        $this->postJson("/api/v1/dcs/segments/{$lead->id}/check-in")->assertOk();
        $this->postJson("/api/v1/dcs/flights/{$this->flight->id}/groups", ['segment_ids' => [$lead->id, $kid->id]])->assertCreated();

        // 2 × 23 = 46 kg for the two of them, used by whoever's bags
        $this->bag($lead->refresh(), $rule, 2, 40)->assertCreated();
        $this->quote($kid->refresh(), $rule, 1, 10)->assertOk()
            ->assertJsonPath('data.free_allowance_kg', 6)->assertJsonPath('data.excess_kg', 4)->assertJsonPath('data.excess_price', 40000);
    }

    public function test_excess_must_be_settled_and_there_is_no_kg_limit(): void
    {
        $rule = $this->rule(['name' => '20 KG', 'type' => 'weight', 'pieces' => 1, 'weight_kg' => 20]);
        $segment = $this->book();
        $this->open();

        $this->bag($segment, $rule, 1, 30)->assertStatus(422)->assertJsonPath('code', 'excess_payment_required');
        $this->bag($segment, $rule, 1, 30, ['waive_reason' => 'VIP'])->assertStatus(422)->assertJsonPath('code', 'waive_authorizer_required');
        $first = $this->bag($segment, $rule, 1, 30, ['waive_reason' => 'VIP', 'waive_authorized_by' => 'Duty manager'])
            ->assertCreated()->assertJsonPath('data.waived_amount', '100000.00')->json('data.id');
        // no excess limit: 60 kg more is just priced
        $this->bag($segment, $rule, 1, 60, ['payment_reference' => 'POS-1'])->assertCreated()->assertJsonPath('data.excess_price', '600000.00');
        // removing a bag gives its free kg back
        $this->deleteJson("/api/v1/dcs/baggage/{$first}")->assertOk();
        $this->quote($segment, $rule, 1, 20)->assertOk()->assertJsonPath('data.excess_kg', 0);
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

    public function test_the_free_allowance_ssr_keeps_its_kg_and_the_rest_is_excess(): void
    {
        // as set up on prod: "20KG Free" FBAG PC 1 × 20 kg, "Excess bag" EBAG KG 6,000 per kg
        $free = $this->rule(['name' => '20KG Free', 'type' => 'piece', 'pieces' => 1, 'weight_kg' => 20, 'max_quantity' => 0, 'ssr_code' => 'FBAG']);
        $this->rule(['name' => 'Excess bag', 'type' => 'weight', 'pieces' => 1, 'weight_kg' => 1, 'ssr_code' => 'EBAG', 'price' => 6000, 'cabin_class' => 'Y']);
        $segment = $this->book();
        $this->open();

        $this->quote($segment, $free, 1, 20)->assertOk()->assertJsonPath('data.excess_price', 0);
        $this->quote($segment, $free, 1, 23)->assertOk()->assertJsonPath('data.free_allowance_kg', 20)
            ->assertJsonPath('data.excess_kg', 3)->assertJsonPath('data.excess_price', 18000);
        $this->bag($segment, $free, 1, 23)->assertStatus(422)->assertJsonPath('code', 'excess_payment_required');
        $this->bag($segment, $free, 1, 23, ['payment_reference' => 'TX-1'])->assertCreated()->assertJsonPath('data.excess_price', '18000.00');
    }

    public function test_a_second_bag_after_the_free_piece_is_not_free_again(): void
    {
        // prod: FBAG PC 1 × 20 kg, only a kg EBAG (6,000 per kg), no piece EBAG
        $this->flight->baggageRules()->detach();
        $free = $this->rule(['name' => '20KG Free', 'type' => 'piece', 'pieces' => 1, 'weight_kg' => 20, 'ssr_code' => 'FBAG']);
        $this->rule(['name' => 'Excess bag', 'type' => 'weight', 'pieces' => 1, 'weight_kg' => 1, 'ssr_code' => 'EBAG', 'price' => 6000]);
        $segment = $this->book();
        $this->open();

        $this->bag($segment, $free, 1, 18)->assertCreated()->assertJsonPath('data.excess_price', '0.00');
        // the free piece is used: the next bag's kg are all excess
        $this->quote($segment, $free, 1, 20)->assertOk()->assertJsonPath('data.excess_pieces', 1)
            ->assertJsonPath('data.free_allowance_kg', 0)->assertJsonPath('data.excess_kg', 20)->assertJsonPath('data.excess_price', 120000);
        // both at once: one piece free (20 kg), the other's 15 kg excess
        $other = $this->book(['first_name' => 'Saraa']);
        $this->quote($other, $free, 2, 35)->assertOk()->assertJsonPath('data.free_allowance_kg', 20)
            ->assertJsonPath('data.excess_kg', 15)->assertJsonPath('data.excess_price', 90000);
    }

    public function test_a_paid_extra_piece_carries_the_rules_kg(): void
    {
        // the flight's EBAG rules: a piece 50,000, a kg 10,000
        $free = $this->rule(['name' => '1PC 20', 'type' => 'piece', 'pieces' => 1, 'weight_kg' => 20, 'ssr_code' => 'FBAG']);
        $segment = $this->book();
        $this->open();

        $this->bag($segment, $free, 1, 20)->assertCreated();
        // an extra piece: its price, up to 20 kg in it; 2 kg over at the kg price
        $this->quote($segment, $free, 1, 22)->assertOk()->assertJsonPath('data.excess_pieces', 1)
            ->assertJsonPath('data.excess_kg', 2)->assertJsonPath('data.excess_price', 70000);
    }
}
