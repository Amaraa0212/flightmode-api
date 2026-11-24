<?php

namespace Tests\Feature\Checkin;

use App\Enums\BookingSource;
use App\Enums\SegmentStatus;
use App\Events\Checkin\SeatsChanged;
use App\Mail\VerificationCodeMail;
use App\Models\Booking;
use App\Models\Flight;
use App\Models\PassengerSegment;
use App\Services\Booking\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

class OnlineCheckinTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    private Flight $flight;

    private PassengerSegment $segment;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->flight = $this->makeFlight(now()->addHours(10), [
            'online_checkin_enabled' => true, 'online_checkin_opens_minutes' => 1440, 'online_checkin_closes_minutes' => 60,
        ]);
        $this->booking = app(BookingService::class)->create(
            ['flight_ids' => [$this->flight->id], 'contact_email' => 'Booker@Example.com', 'passengers' => [
                $this->passengerPayload(['person_code' => 'ab12345']),
                $this->passengerPayload(['first_name' => 'Saraa', 'last_name' => 'Bold', 'person_code' => 'cd67890', 'email' => null]),
            ]],
            $this->makeUser('system-admin'), BookingSource::Admin,
        );
        $this->segment = $this->booking->passengers->firstWhere('person_code', 'AB12345')->segments()->firstOrFail();
    }

    /** Oyu Tolgoi: SAP number alone gives a session. */
    private function checkinSession(): string
    {
        return $this->postJson('/api/v1/checkin/lookup/sap', ['person_code' => ' ab12345 '])->assertOk()
            ->assertJsonPath('data.flights.0.flight_id', $this->flight->id)
            ->assertJsonCount(1, 'data.flights.0.passengers')
            ->json('data.session_token');
    }

    /** Others: PNR + last name, then the code e-mailed to the booking's stored addresses. */
    private function pnrSession(): string
    {
        Mail::fake();
        $lookup = $this->postJson('/api/v1/checkin/lookup/pnr', ['pnr' => strtolower($this->booking->pnr), 'last_name' => 'bold'])->assertOk()
            ->assertJsonPath('data.flights.0.flight_id', $this->flight->id)
            ->assertJsonPath('data.email_hints', ['b*****@example.com', 'b**@example.com'])
            ->json('data.lookup_token');

        $code = null;
        Mail::assertSent(VerificationCodeMail::class, function (VerificationCodeMail $mail) use (&$code) {
            $code = $mail->code;

            return $mail->hasTo('booker@example.com') && $mail->hasTo('bat@example.com');
        });

        $this->postJson('/api/v1/checkin/verify', ['lookup_token' => $lookup, 'code' => $code === '0000' ? '1111' : '0000'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_code');

        return $this->postJson('/api/v1/checkin/verify', ['lookup_token' => $lookup, 'code' => $code])->assertOk()->json('data.session_token');
    }

    public function test_sap_session_only_sees_that_person(): void
    {
        $headers = ['X-Checkin-Token' => $this->checkinSession()];

        $this->getJson("/api/v1/checkin/flights/{$this->flight->id}/passengers", $headers)->assertOk()
            ->assertJsonCount(1, 'data.passengers')
            ->assertJsonPath('data.passengers.0.segment_id', $this->segment->id);

        $other = $this->booking->passengers->firstWhere('person_code', 'CD67890')->segments()->firstOrFail();
        $this->postJson("/api/v1/checkin/flights/{$this->flight->id}/check-in", [
            'accept_policies' => true, 'passengers' => [['segment_id' => $other->id]],
        ], $headers)->assertForbidden()->assertJsonPath('code', 'segment_not_in_session');
    }

    public function test_pnr_session_sees_the_whole_booking(): void
    {
        $headers = ['X-Checkin-Token' => $this->pnrSession()];

        $this->getJson('/api/v1/checkin/flights', $headers)->assertOk()->assertJsonCount(2, 'data.0.passengers');
        $this->getJson("/api/v1/checkin/flights/{$this->flight->id}/passengers", $headers)->assertOk()
            ->assertJsonCount(2, 'data.passengers');
    }

    public function test_pnr_lookup_needs_a_matching_last_name(): void
    {
        $this->postJson('/api/v1/checkin/lookup/pnr', ['pnr' => $this->booking->pnr, 'last_name' => 'Nobody'])
            ->assertNotFound()->assertJsonPath('code', 'booking_not_found');
        $this->postJson('/api/v1/checkin/lookup/pnr', ['pnr' => 'ZZZZZZ', 'last_name' => 'Dorj'])
            ->assertNotFound()->assertJsonPath('code', 'booking_not_found');
    }

    public function test_pnr_lookup_without_a_stored_email_is_refused(): void
    {
        $this->booking->update(['contact_email' => null]);
        $this->booking->passengers()->update(['email' => null]);

        $this->postJson('/api/v1/checkin/lookup/pnr', ['pnr' => $this->booking->pnr, 'last_name' => 'Dorj'])
            ->assertStatus(422)->assertJsonPath('code', 'no_contact_email');
    }

    public function test_full_online_checkin_with_seat_choice(): void
    {
        $token = $this->checkinSession();
        $headers = ['X-Checkin-Token' => $token];

        $this->getJson("/api/v1/checkin/flights/{$this->flight->id}/passengers", $headers)->assertOk()
            ->assertJsonPath('data.online_checkin_open', true)
            ->assertJsonPath('data.passengers.0.segment_id', $this->segment->id);

        $seats = collect($this->getJson("/api/v1/checkin/flights/{$this->flight->id}/seat-map", $headers)->assertOk()->json('data.seats'));
        // zone A (business rows 1-2) is excluded from online check-in by default
        $this->assertSame('restricted', $seats->firstWhere('seat_number', '1A')['state']);
        $this->assertSame('available', $seats->firstWhere('seat_number', '5C')['state']);

        $this->postJson("/api/v1/checkin/flights/{$this->flight->id}/seat-hold", ['seat_number' => '1A'], $headers)
            ->assertStatus(422)->assertJsonPath('code', 'seat_not_available');
        $this->postJson("/api/v1/checkin/flights/{$this->flight->id}/seat-hold", ['seat_number' => '5C'], $headers)->assertOk();

        $passes = $this->postJson("/api/v1/checkin/flights/{$this->flight->id}/check-in", [
            'accept_policies' => true,
            'passengers' => [['segment_id' => $this->segment->id, 'seat_number' => '5C']],
        ], $headers)->assertOk()->json('data');

        $this->assertSame('5C', $passes[0]['seat_number']);
        $fresh = $this->segment->fresh();
        $this->assertSame(SegmentStatus::CheckedIn, $fresh->status);
        $this->assertSame('online', $fresh->checkin_channel->value);
    }

    public function test_automatic_seat_follows_online_restrictions(): void
    {
        // economy zone B (rows 3-6) closed online too — the automatic pick must skip it
        config(['flightmode.online_checkin.excluded_zones' => ['A', 'B']]);
        $headers = ['X-Checkin-Token' => $this->checkinSession()];

        $this->postJson("/api/v1/checkin/flights/{$this->flight->id}/check-in", [
            'accept_policies' => true,
            'passengers' => [['segment_id' => $this->segment->id]],
        ], $headers)->assertOk();

        $this->assertSame('7A', $this->segment->fresh()->seat_number);
    }

    public function test_requests_without_a_valid_session_are_rejected(): void
    {
        $this->getJson("/api/v1/checkin/flights/{$this->flight->id}/passengers", ['X-Checkin-Token' => 'nope'])
            ->assertStatus(401)->assertJsonPath('code', 'session_expired');
    }

    public function test_closed_flight_cannot_be_found(): void
    {
        $this->flight->update(['online_checkin_enabled' => false]);

        $this->postJson('/api/v1/checkin/lookup/sap', ['person_code' => 'AB12345'])
            ->assertNotFound()->assertJsonPath('code', 'no_online_checkin');
    }

    /** A second person on the same flight, in a booking of their own. */
    private function otherSession(): string
    {
        app(BookingService::class)->create(
            ['flight_ids' => [$this->flight->id], 'passengers' => [$this->passengerPayload(['first_name' => 'Other', 'last_name' => 'Person', 'person_code' => 'zz99999'])]],
            $this->makeUser('system-admin'), BookingSource::Admin,
        );

        return $this->postJson('/api/v1/checkin/lookup/sap', ['person_code' => 'ZZ99999'])->assertOk()->json('data.session_token');
    }

    public function test_a_seat_held_by_someone_else_cannot_be_held_or_taken(): void
    {
        $mine = ['X-Checkin-Token' => $this->checkinSession()];
        $theirs = ['X-Checkin-Token' => $this->otherSession()];
        $url = "/api/v1/checkin/flights/{$this->flight->id}";

        $this->postJson("{$url}/seat-hold", ['seat_number' => '5C'], $theirs)->assertOk();
        $this->postJson("{$url}/seat-hold", ['seat_number' => '5C'], $theirs)->assertOk(); // own hold is extended
        $this->postJson("{$url}/seat-hold", ['seat_number' => '5C'], $mine)->assertStatus(422);

        // even if the seat map's hold index lost the seat, the hold key itself still guards it
        Cache::forget("seat-hold-index:{$this->flight->id}");
        $this->postJson("{$url}/check-in", [
            'accept_policies' => true, 'passengers' => [['segment_id' => $this->segment->id, 'seat_number' => '5C']],
        ], $mine)->assertStatus(422)->assertJsonPath('code', 'seat_held');
        $this->assertNull($this->segment->fresh()->seat_number);
    }

    public function test_releasing_a_seat_frees_it_for_others(): void
    {
        $mine = ['X-Checkin-Token' => $this->checkinSession()];
        $theirs = ['X-Checkin-Token' => $this->otherSession()];
        $url = "/api/v1/checkin/flights/{$this->flight->id}";

        $this->postJson("{$url}/seat-hold", ['seat_number' => '5C'], $theirs)->assertOk();
        $this->deleteJson("{$url}/seat-hold/5C", [], $mine)->assertOk(); // not my hold: nothing happens
        $this->postJson("{$url}/seat-hold", ['seat_number' => '5C'], $mine)->assertStatus(422);

        $this->deleteJson("{$url}/seat-hold/5c", [], $theirs)->assertOk();
        $this->postJson("{$url}/seat-hold", ['seat_number' => '5C'], $mine)->assertOk();
    }

    public function test_automatic_seat_skips_seats_others_are_holding(): void
    {
        $theirs = ['X-Checkin-Token' => $this->otherSession()];
        $first = collect($this->getJson("/api/v1/checkin/flights/{$this->flight->id}/seat-map", $theirs)->json('data.seats'))
            ->where('state', 'available')->where('cabin_class', 'Y')->where('is_emergency_exit', false)->first()['seat_number'];

        $this->postJson("/api/v1/checkin/flights/{$this->flight->id}/seat-hold", ['seat_number' => $first], $theirs)->assertOk();

        $this->postJson("/api/v1/checkin/flights/{$this->flight->id}/check-in", [
            'accept_policies' => true, 'passengers' => [['segment_id' => $this->segment->id]],
        ], ['X-Checkin-Token' => $this->checkinSession()])->assertOk();

        $seat = $this->segment->fresh()->seat_number;
        $this->assertNotNull($seat);
        $this->assertNotSame($first, $seat);
    }

    public function test_seat_changes_are_broadcast_to_the_public_seat_map(): void
    {
        Event::fake([SeatsChanged::class]);
        $headers = ['X-Checkin-Token' => $this->checkinSession()];
        $url = "/api/v1/checkin/flights/{$this->flight->id}";

        $this->postJson("{$url}/seat-hold", ['seat_number' => '5C'], $headers)->assertOk();
        Event::assertDispatched(SeatsChanged::class, fn (SeatsChanged $e) => $e->flightId === $this->flight->id && $e->seats === ['5C' => 'held']
            && $e->broadcastOn()->name === "oci.flight.{$this->flight->id}");

        $this->postJson("{$url}/check-in", [
            'accept_policies' => true, 'passengers' => [['segment_id' => $this->segment->id, 'seat_number' => '5C']],
        ], $headers)->assertOk();
        Event::assertDispatched(SeatsChanged::class, fn (SeatsChanged $e) => $e->seats === ['5C' => 'occupied']);
        // the hold is let go quietly: the seat is announced as occupied, never as released
        Event::assertNotDispatched(SeatsChanged::class, fn (SeatsChanged $e) => in_array('released', $e->seats, true));

        $payload = (new SeatsChanged($this->flight->id, ['5C' => 'occupied']))->broadcastWith();
        $this->assertSame(['seats' => [['seat_number' => '5C', 'state' => 'occupied']]], $payload);
    }

    public function test_a_flight_not_open_yet_says_when_it_opens(): void
    {
        // departs in 10 hours, opens 5 hours before departure: in 5 hours
        $this->flight->update(['online_checkin_opens_minutes' => 300]);
        $opens = $this->flight->departureAt()->copy()->subMinutes(300);

        $this->postJson('/api/v1/checkin/lookup/sap', ['person_code' => 'AB12345'])
            ->assertNotFound()->assertJsonPath('code', 'online_checkin_not_open_yet')
            ->assertJsonPath('context.flight_number', $this->flight->flight_number)
            ->assertJsonPath('context.opens_at', $opens->toIso8601String())
            ->assertJsonPath('context.opens_at_local', $opens->copy()->timezone('Asia/Ulaanbaatar')->format('Y-m-d H:i'));

        $this->postJson('/api/v1/checkin/lookup/pnr', ['pnr' => $this->booking->pnr, 'last_name' => 'Dorj'])
            ->assertNotFound()->assertJsonPath('code', 'online_checkin_not_open_yet');
    }
}
