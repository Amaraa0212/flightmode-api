<?php

namespace Tests\Feature\Dcs;

use App\Enums\ActivityChannel;
use App\Enums\BookingSource;
use App\Events\Dcs\FlightUpdated;
use App\Models\Announcement;
use App\Models\BaggageRule;
use App\Models\Flight;
use App\Models\PassengerSegment;
use App\Services\Booking\BookingService;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

/** Server-side baggage pricing, crew bag names, ban list, live updates and station announcements. */
class CounterFeaturesTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    private Flight $flight;

    private BaggageRule $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->flight = $this->makeFlight(now()->addHours(3), [
            'excess_baggage_price_per_kg' => 18000,
            'excess_baggage_max_kg' => 10,
        ]);
        $this->rule = BaggageRule::create(['airline_id' => $this->airline->id, 'name' => '1PC 23KG', 'type' => 'piece',
            'pieces' => 1, 'weight_kg' => 23, 'max_quantity' => 2]);
        $this->flight->baggageRules()->attach($this->rule->id);
    }

    private function book(array $pax = []): PassengerSegment
    {
        $booking = app(BookingService::class)->create(
            ['flight_ids' => [$this->flight->id], 'passengers' => [$this->passengerPayload($pax)]],
            $this->makeUser('system-admin'), BookingSource::Admin,
        );

        return $booking->passengers->first()->segments()->firstOrFail();
    }

    private function openAndCheckIn(PassengerSegment $segment): void
    {
        $this->actingAsRole('station-agent');
        $this->postJson("/api/v1/dcs/flights/{$this->flight->id}/open")->assertOk();
        $this->postJson("/api/v1/dcs/segments/{$segment->id}/check-in")->assertOk();
    }

    public function test_baggage_can_be_accepted_before_the_check_in(): void
    {
        $segment = $this->book();
        $this->actingAsRole('station-agent');
        $this->postJson("/api/v1/dcs/flights/{$this->flight->id}/open")->assertOk();

        $tag = $this->postJson("/api/v1/dcs/segments/{$segment->id}/baggage", [
            'baggage_rule_id' => $this->rule->id, 'pieces' => 1, 'weight_kg' => 20,
        ])->assertCreated()->json('data.tags.0.tag_number');

        // the tag carries the boarding sequence given with the bag; the check-in keeps it
        $sequence = $segment->refresh()->boarding_sequence;
        $this->assertNotNull($sequence);
        $this->assertSame(str_pad((string) $sequence, 3, '0', STR_PAD_LEFT), substr($tag, 4, 3));
        $this->postJson("/api/v1/dcs/segments/{$segment->id}/check-in")->assertOk();
        $this->assertSame($sequence, $segment->refresh()->boarding_sequence);

        $this->deleteJson("/api/v1/dcs/segments/{$segment->id}/check-in")->assertStatus(422);

        // a booked passenger with bags cannot leave the flight before the bags are removed
        $other = $this->book(['first_name' => 'BOLD']);
        $this->postJson("/api/v1/dcs/segments/{$other->id}/baggage", [
            'baggage_rule_id' => $this->rule->id, 'pieces' => 1, 'weight_kg' => 18,
        ])->assertCreated();
        $this->expectExceptionMessage('Remove the passenger\'s baggage before cancelling the passenger.');
        app(BookingService::class)->cancelPassenger($other->passenger, ActivityChannel::Admin);
    }

    public function test_excess_is_priced_by_the_api_and_client_prices_are_ignored(): void
    {
        $segment = $this->book();
        $this->openAndCheckIn($segment);

        $this->postJson("/api/v1/dcs/segments/{$segment->id}/baggage/quote", [
            'baggage_rule_id' => $this->rule->id, 'pieces' => 1, 'weight_kg' => 26,
        ])->assertOk()
            ->assertJsonPath('data.free_allowance_kg', 23)
            ->assertJsonPath('data.excess_kg', 3)
            ->assertJsonPath('data.excess_price', 54000);

        $this->postJson("/api/v1/dcs/segments/{$segment->id}/baggage", [
            'baggage_rule_id' => $this->rule->id, 'pieces' => 1, 'weight_kg' => 26,
            'excess_price' => 0, 'free_allowance_kg' => 100, 'price' => 0,
        ])->assertCreated()
            ->assertJsonPath('data.free_allowance_kg', '23.0')
            ->assertJsonPath('data.excess_price', '54000.00');

        // cap: 3 kg already excess, 10 kg max per passenger
        $this->postJson("/api/v1/dcs/segments/{$segment->id}/baggage", [
            'baggage_rule_id' => $this->rule->id, 'pieces' => 1, 'weight_kg' => 32,
        ])->assertStatus(422)->assertJsonPath('code', 'excess_limit_exceeded');
    }

    public function test_crew_bags_keep_the_crew_name(): void
    {
        $this->actingAsRole('station-agent');
        $this->postJson("/api/v1/dcs/flights/{$this->flight->id}/open")->assertOk();

        $bag = $this->postJson("/api/v1/dcs/flights/{$this->flight->id}/crew-baggage", [
            'crew_name' => 'cpt Batbold', 'pieces' => 1, 'weight_kg' => 18,
        ])->assertCreated()->assertJsonPath('data.crew_name', 'CPT BATBOLD')->json('data');

        $this->getJson("/api/v1/dcs/baggage-tags/{$bag['tags'][0]['id']}")->assertOk()
            ->assertJsonPath('data.passenger', 'CREW CPT BATBOLD')
            ->assertJsonPath('data.is_crew', true)
            ->assertJsonPath('data.passenger_title', null)
            ->assertJsonPath('data.destination.name', $this->flight->destination->name);
    }

    public function test_a_check_in_group_shares_its_piece_allowance(): void
    {
        $this->rule->update(['max_quantity' => 1]); // 1PC 23KG each
        $lead = $this->book(['last_name' => 'Bold', 'first_name' => 'Nandin', 'document_number' => 'E7100001']);
        $kid1 = $this->book(['last_name' => 'Bold', 'first_name' => 'Bilguun', 'document_number' => 'E7100002']);
        $kid2 = $this->book(['last_name' => 'Bold', 'first_name' => 'Anu', 'document_number' => 'E7100003']);
        $solo = $this->book(['last_name' => 'Dorj', 'first_name' => 'Bat', 'document_number' => 'E7100004']);
        $this->openAndCheckIn($lead);
        $this->postJson("/api/v1/dcs/segments/{$solo->id}/check-in")->assertOk();
        $this->postJson("/api/v1/dcs/flights/{$this->flight->id}/groups", ['segment_ids' => [$lead->id, $kid1->id, $kid2->id]])
            ->assertCreated();

        $bag = ['baggage_rule_id' => $this->rule->id, 'pieces' => 3, 'weight_kg' => 69];
        // the quote already checks the pooled limit: 3 members × 1 piece, 3 × 23 kg free
        $this->postJson("/api/v1/dcs/segments/{$lead->id}/baggage/quote", $bag)->assertOk()
            ->assertJsonPath('data.free_allowance_kg', 69)->assertJsonPath('data.excess_kg', 0);
        $this->postJson("/api/v1/dcs/segments/{$lead->id}/baggage", $bag)->assertCreated();

        // the group's pieces are used up — on any member
        $one = ['baggage_rule_id' => $this->rule->id, 'pieces' => 1, 'weight_kg' => 10];
        $this->postJson("/api/v1/dcs/segments/{$lead->id}/baggage/quote", $one)
            ->assertStatus(422)->assertJsonPath('code', 'baggage_limit_exceeded');
        $this->postJson("/api/v1/dcs/segments/{$lead->id}/baggage", $one)->assertStatus(422);

        // a passenger outside the group keeps their own single piece
        $this->postJson("/api/v1/dcs/segments/{$solo->id}/baggage", ['baggage_rule_id' => $this->rule->id, 'pieces' => 2, 'weight_kg' => 40])
            ->assertStatus(422)->assertJsonPath('code', 'baggage_limit_exceeded');
        $this->postJson("/api/v1/dcs/segments/{$solo->id}/baggage", $one)->assertCreated();
    }

    public function test_qz_tray_requests_are_signed_by_the_server(): void
    {
        $dir = sys_get_temp_dir().'/qz-'.uniqid();
        mkdir($dir);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'counter'], $key), null, $key, 1);
        openssl_x509_export($cert, $certPem);
        file_put_contents("$dir/key.pem", $pem);
        file_put_contents("$dir/cert.txt", $certPem);
        config(['flightmode.qz.certificate' => "$dir/cert.txt", 'flightmode.qz.private_key' => "$dir/key.pem"]);

        $this->actingAsRole('station-agent');
        $this->getJson('/api/v1/dcs/qz/certificate')->assertOk()->assertJsonPath('data.certificate', $certPem);

        $signature = $this->postJson('/api/v1/dcs/qz/sign', ['request' => 'qz-request-123'])->assertOk()->json('data.signature');
        $this->assertSame(1, openssl_verify('qz-request-123', base64_decode($signature), openssl_pkey_get_public($certPem), OPENSSL_ALGO_SHA512));

        config(['flightmode.qz.private_key' => "$dir/missing.pem"]);
        $this->postJson('/api/v1/dcs/qz/sign', ['request' => 'x'])->assertStatus(422)->assertJsonPath('code', 'qz_not_configured');
    }

    public function test_banned_passenger_cannot_check_in_and_watch_list_only_warns(): void
    {
        $banned = $this->book(['last_name' => 'Bold', 'first_name' => 'Sara', 'document_number' => 'E7000001']);
        $watched = $this->book(['last_name' => 'Dorj', 'first_name' => 'Bat', 'date_of_birth' => '1985-03-03', 'document_number' => 'E7000002']);

        $admin = $this->actingAsRole('airline-admin');
        $this->postJson('/api/v1/admin/passenger-bans', [
            'ban_type' => 'no_fly', 'last_name' => 'Bold', 'first_name' => 'Sara',
            'document_number' => 'e7000001', 'reason' => 'Unruly on OM301',
        ])->assertCreated()->assertJsonPath('data.airline_id', $admin->airline_id);
        $this->postJson('/api/v1/admin/passenger-bans', [
            'ban_type' => 'watch', 'last_name' => 'DORJ', 'first_name' => 'BAT',
            'date_of_birth' => '1985-03-03', 'reason' => 'Check documents',
        ])->assertCreated();

        $this->actingAsRole('station-agent');
        $this->postJson("/api/v1/dcs/flights/{$this->flight->id}/open")->assertOk();

        $this->getJson("/api/v1/dcs/segments/{$banned->id}")->assertOk()->assertJsonPath('data.screening.status', 'banned');
        $this->postJson("/api/v1/dcs/segments/{$banned->id}/check-in")
            ->assertStatus(422)->assertJsonPath('code', 'passenger_banned');

        $this->getJson("/api/v1/dcs/segments/{$watched->id}")->assertOk()->assertJsonPath('data.screening.status', 'watch');
        $this->postJson("/api/v1/dcs/segments/{$watched->id}/check-in")->assertOk();

        $rows = collect($this->getJson("/api/v1/dcs/flights/{$this->flight->id}/passengers")->assertOk()->json('data'));
        $this->assertSame('banned', $rows->firstWhere('id', $banned->id)['screening']['status']);
    }

    public function test_changes_are_broadcast_to_the_flight_channel(): void
    {
        Event::fake([FlightUpdated::class]);
        $segment = $this->book();
        $this->openAndCheckIn($segment);

        Event::assertDispatched(FlightUpdated::class, fn (FlightUpdated $e) => $e->flightId === $this->flight->id
            && $e->event === 'passenger.checked_in'
            && $e->broadcastOn()->name === "private-dcs.flight.{$this->flight->id}"
            && $e->seat['segment_id'] === $segment->id && $e->seat['from'] === null && $e->seat['to'] !== null
            // the flight bar figures ride along — counters do not reload the flight for them
            && $e->broadcastWith()['statistics']['checked_in']['total'] === 1);

        // un-check releases the seat → counters free it on their seat map
        $seat = $segment->refresh()->seat_number;
        $this->deleteJson("/api/v1/dcs/segments/{$segment->id}/check-in")->assertOk();
        Event::assertDispatched(FlightUpdated::class, fn (FlightUpdated $e) => $e->event === 'passenger.checkin_cancelled'
            && $e->broadcastWith()['seat'] === ['segment_id' => $segment->id, 'from' => $seat, 'to' => null]
            && $e->statistics['checked_in']['total'] === 0);
    }

    public function test_only_staff_of_the_station_may_listen_to_a_flight(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        // channels were registered on the null test driver at boot — register them on reverb
        app(BroadcastManager::class)->forgetDrivers();
        require base_path('routes/channels.php');
        $channel = "private-dcs.flight.{$this->flight->id}";

        $this->actingAsRole('station-agent');
        $this->postJson('/api/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel])
            ->assertOk()->assertJsonStructure(['auth']);

        $this->actingAsRole('station-agent', ['airport_id' => $this->pqc->id]);
        $this->postJson('/api/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel])
            ->assertForbidden();
    }

    public function test_station_staff_see_airline_wide_announcements(): void
    {
        $agencyOnly = Announcement::create(['airline_id' => $this->airline->id, 'agency_id' => $this->agency->id,
            'title' => 'Agency only', 'body' => 'x', 'requires_acknowledgement' => false]);
        $airlineWide = Announcement::create(['airline_id' => $this->airline->id, 'title' => 'New bag tag printers',
            'body' => 'Counters 1-5', 'requires_acknowledgement' => true]);

        $this->actingAsRole('station-agent', ['airline_id' => null]);
        $ids = collect($this->getJson('/api/v1/announcements')->assertOk()->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($airlineWide->id));
        $this->assertFalse($ids->contains($agencyOnly->id));
    }
}
