<?php

namespace Tests\Feature\Checkin;

use App\Enums\BookingSource;
use App\Enums\SegmentStatus;
use App\Mail\VerificationCodeMail;
use App\Models\Flight;
use App\Models\PassengerSegment;
use App\Services\Booking\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

class OnlineCheckinTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    private Flight $flight;

    private PassengerSegment $segment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->flight = $this->makeFlight(now()->addHours(10), [
            'online_checkin_enabled' => true, 'online_checkin_opens_minutes' => 1440, 'online_checkin_closes_minutes' => 60,
        ]);
        $booking = app(BookingService::class)->create(
            ['flight_ids' => [$this->flight->id], 'passengers' => [$this->passengerPayload(['person_code' => 'ab12345'])]],
            $this->makeUser('system-admin'), BookingSource::Admin,
        );
        $this->segment = $booking->passengers->first()->segments()->firstOrFail();
    }

    private function checkinSession(): string
    {
        Mail::fake();
        $lookup = $this->postJson('/api/v1/checkin/lookup', ['person_code' => 'AB12345'])->assertOk()
            ->assertJsonPath('data.flights.0.flight_id', $this->flight->id)->json('data.lookup_token');

        $this->postJson('/api/v1/checkin/verification-code', ['lookup_token' => $lookup, 'email' => 'pax@example.com'])->assertOk();

        $code = null;
        Mail::assertSent(VerificationCodeMail::class, function (VerificationCodeMail $mail) use (&$code) {
            $code = $mail->code;

            return $mail->hasTo('pax@example.com');
        });

        $this->postJson('/api/v1/checkin/verify', ['lookup_token' => $lookup, 'code' => $code === '0000' ? '1111' : '0000'])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_code');

        return $this->postJson('/api/v1/checkin/verify', ['lookup_token' => $lookup, 'code' => $code])->assertOk()->json('data.session_token');
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

    public function test_requests_without_a_valid_session_are_rejected(): void
    {
        $this->getJson("/api/v1/checkin/flights/{$this->flight->id}/passengers", ['X-Checkin-Token' => 'nope'])
            ->assertStatus(401)->assertJsonPath('code', 'session_expired');
    }

    public function test_closed_flight_cannot_be_found(): void
    {
        $this->flight->update(['online_checkin_enabled' => false]);

        $this->postJson('/api/v1/checkin/lookup', ['person_code' => 'AB12345'])
            ->assertNotFound()->assertJsonPath('code', 'no_online_checkin');
    }
}
