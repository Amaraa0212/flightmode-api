<?php

namespace Tests\Feature\Dcs;

use App\Enums\BookingSource;
use App\Models\BaggageRule;
use App\Models\Passenger;
use App\Models\ServiceTemplate;
use App\Services\Booking\BookingService;
use App\Services\Documents\ETicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

/** Issue reports round 11: local-day boards, per-flight baggage on the e-ticket, users, rules, groups. */
class Round11Test extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_the_flight_board_day_is_the_origins_local_day(): void
    {
        // 01:20 at UBN on the 22nd is 17:20 UTC on the 21st
        $flight = $this->makeFlight(now()->addDays(5)->setTimezone('Asia/Ulaanbaatar')->setTime(1, 20)->utc());
        $local = $flight->scheduled_departure_at->copy()->timezone('Asia/Ulaanbaatar')->toDateString();
        $utc = $flight->scheduled_departure_at->toDateString();
        $this->actingAsRole('airline-admin');

        $ids = fn ($day) => array_column($this->getJson('/api/v1/admin/flight-board?date='.$day)->assertOk()->json('data'), 'id');
        $this->assertContains($flight->id, $ids($local));
        $this->assertNotContains($flight->id, $ids($utc));
        // the manifest page shows the board row's airline
        $this->getJson('/api/v1/admin/flight-board?date='.$local)->assertOk()->assertJsonPath('data.0.airline.name', $this->airline->name);
    }

    public function test_the_eticket_shows_each_flights_own_baggage(): void
    {
        $out = $this->makeFlight();
        $back = $this->makeFlight(now()->addDays(10)->setTime(9, 0), ['flight_number' => 'TA102',
            'origin_airport_id' => $this->pqc->id, 'destination_airport_id' => $this->ubn->id]);
        $out->baggageRules()->attach(BaggageRule::create(['airline_id' => $this->airline->id, 'name' => '15KG', 'type' => 'weight', 'pieces' => 1, 'weight_kg' => 15])->id);
        $back->baggageRules()->attach(BaggageRule::create(['airline_id' => $this->airline->id, 'name' => '20KG', 'type' => 'weight', 'pieces' => 1, 'weight_kg' => 20])->id);
        app(BookingService::class)->create(['flight_ids' => [$out->id, $back->id], 'passengers' => [$this->passengerPayload()]],
            $this->makeUser('system-admin'), BookingSource::Admin);

        $data = app(ETicketService::class)->data(Passenger::query()->firstOrFail());
        $this->assertSame([15, 20], array_column($data['legs'], 'baggage_kg'));
        $html = view('documents.eticket', $data)->render();
        $this->assertStringContainsString('15 kg in total', $html);
        $this->assertStringContainsString('20 kg in total', $html);
    }

    public function test_a_user_who_issued_tickets_is_disabled_not_deleted(): void
    {
        $agent = $this->makeUser('agency-agent');
        $idle = $this->makeUser('agency-agent');
        $this->actingAs($agent);
        app(BookingService::class)->create(['allocation_id' => $this->makeAllocation($this->makeFlight())->id,
            'passengers' => [$this->passengerPayload()]], $agent, BookingSource::Agency);

        $this->actingAsRole('system-admin');
        $this->deleteJson("/api/v1/admin/users/{$agent->id}")->assertOk();
        $this->assertFalse($agent->fresh()->is_active);
        $this->assertNotSoftDeleted('users', ['id' => $agent->id]);
        $this->postJson('/api/v1/auth/login', ['email' => $agent->email, 'password' => 'password'])->assertStatus(403);

        $this->deleteJson("/api/v1/admin/users/{$idle->id}")->assertOk();
        $this->assertSoftDeleted('users', ['id' => $idle->id]);
    }

    public function test_the_eticket_takes_the_free_allowance_from_the_fbag_rule(): void
    {
        $out = $this->makeFlight();
        // as the baggage rules page saves a bag: FBAG (free) with EBAG / XBAG rows that are paid, not the allowance
        $group = fn (array $rows, array $details) => $this->postJson('/api/v1/admin/baggage-rule-groups',
            $details + ['type' => 'weight', 'is_active' => true, 'rules' => $rows])->assertCreated()->json('data.rules.*.id');
        $this->actingAsRole('airline-admin');
        $ids = $group([['ssr_code' => 'EBAG', 'weight_kg' => 1, 'price' => 10000], ['ssr_code' => 'FBAG', 'pieces' => 1, 'weight_kg' => 15],
            ['ssr_code' => 'XBAG', 'pieces' => 1, 'weight_kg' => 23, 'price' => 150000]], ['name' => 'Charter 15KG']);
        $business = $group([['ssr_code' => 'FBAG', 'pieces' => 2, 'weight_kg' => 32]], ['name' => 'Business 2PC', 'type' => 'piece', 'cabin_class' => 'C']);
        $out->baggageRules()->attach([...$ids, ...$business]);

        app(BookingService::class)->create(['flight_ids' => [$out->id], 'passengers' => [$this->passengerPayload()]],
            $this->makeUser('system-admin'), BookingSource::Admin);

        $data = app(ETicketService::class)->data(Passenger::query()->firstOrFail());
        // economy: the 15 kg FBAG total, not the 20 kg default, the EBAG kg or the XBAG bag
        $this->assertSame(15, $data['legs'][0]['baggage_kg']);
        $this->assertNull($data['legs'][0]['baggage_pieces']);
    }

    public function test_a_baggage_rule_in_a_template_is_not_deleted(): void
    {
        $rule = BaggageRule::create(['airline_id' => $this->airline->id, 'name' => '1PC', 'type' => 'piece', 'pieces' => 1, 'weight_kg' => 23]);
        $template = ServiceTemplate::create(['airline_id' => $this->airline->id, 'aircraft_id' => $this->aircraft->id, 'name' => 'Summer']);
        $template->baggageRules()->attach($rule->id);
        $this->actingAsRole('airline-admin');

        $this->deleteJson("/api/v1/admin/baggage-rules/{$rule->id}")->assertStatus(422)->assertJsonPath('code', 'baggage_rule_in_template');
        $template->baggageRules()->detach();
        $this->deleteJson("/api/v1/admin/baggage-rules/{$rule->id}")->assertOk();
    }

    public function test_one_pnr_is_grouped_at_its_first_check_in_and_bags_keep_a_passenger_out_of_groups(): void
    {
        $flight = $this->makeFlight(now()->addHours(3));
        $rule = BaggageRule::create(['airline_id' => $this->airline->id, 'name' => '1PC', 'type' => 'piece', 'pieces' => 1, 'weight_kg' => 23]);
        $flight->baggageRules()->attach($rule->id);
        $admin = $this->makeUser('system-admin');
        $family = app(BookingService::class)->create(['flight_ids' => [$flight->id], 'passengers' => [
            $this->passengerPayload(['first_name' => 'Bold']), $this->passengerPayload(['first_name' => 'Saraa']),
        ]], $admin, BookingSource::Admin)->passengers->map(fn ($p) => $p->segments()->first());
        $solo = app(BookingService::class)->create(['flight_ids' => [$flight->id], 'passengers' => [$this->passengerPayload(['first_name' => 'Tuya'])]],
            $admin, BookingSource::Admin)->passengers->first()->segments()->first();

        $this->actingAsRole('station-agent');
        $this->postJson("/api/v1/dcs/flights/{$flight->id}/open")->assertOk();
        $this->postJson("/api/v1/dcs/segments/{$family[0]->id}/check-in")->assertOk();
        $group = $family[0]->fresh()->checkin_group_id;
        $this->assertNotNull($group);
        $this->assertSame($group, $family[1]->fresh()->checkin_group_id);
        $this->assertNull($solo->fresh()->checkin_group_id);

        // a passenger with bags of their own is not put in a group
        $this->postJson("/api/v1/dcs/segments/{$solo->id}/baggage", ['baggage_rule_id' => $rule->id, 'pieces' => 1, 'weight_kg' => 10])->assertCreated();
        $this->postJson("/api/v1/dcs/flights/{$flight->id}/groups", ['segment_ids' => [$solo->id, $family[0]->id]])
            ->assertStatus(422)->assertJsonPath('code', 'group_member_has_baggage');
    }

    public function test_a_round_trip_on_two_quotas_counts_and_lists_on_both(): void
    {
        $out = $this->makeFlight(null, ['service_type' => 'charter']);
        $back = $this->makeFlight(now()->addDays(10)->setTime(9, 0), ['flight_number' => 'TA102', 'service_type' => 'charter',
            'origin_airport_id' => $this->pqc->id, 'destination_airport_id' => $this->ubn->id]);
        $outQuota = $this->makeAllocation($out, 5, ['Y' => 5]);
        $backQuota = $this->makeAllocation($back, 5, ['Y' => 5]);
        $this->actingAsRole('agency-agent');

        $this->postJson('/api/v1/agency/bookings', ['allocation_id' => $outQuota->id, 'return_allocation_id' => $backQuota->id,
            'passengers' => [$this->passengerPayload(['first_name' => 'Bold'])]])->assertCreated();

        $usage = fn ($quota) => $this->getJson("/api/v1/agency/allocations/{$quota->id}")->assertOk()->json('data.usage');
        $this->assertSame([1, 4], [$usage($outQuota)['used'], $usage($outQuota)['remaining']]);
        $this->assertSame([1, 4], [$usage($backQuota)['used'], $usage($backQuota)['remaining']]);   // the return quota counts it

        // the return quota lists the passenger with the return coupon
        $list = $this->getJson("/api/v1/agency/allocations/{$backQuota->id}/passengers")->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame($back->id, $list[0]['segments'][0]['flight_id']);
        $this->assertNotNull($list[0]['segments'][0]['ticket_number']);

        // cancelling frees both
        $this->deleteJson("/api/v1/agency/passengers/{$list[0]['id']}")->assertOk();
        $this->assertSame([0, 0], [$usage($outQuota)['used'], $usage($backQuota)['used']]);
    }
}
