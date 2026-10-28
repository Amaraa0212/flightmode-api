<?php

namespace Tests\Feature\Booking;

use App\Models\Booking;
use App\Models\Flight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

/** Ticket time limit (TL): a booking held without tickets until its fare class limit, then issued or cancelled. */
class TicketTimeLimitTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    private Flight $flight;

    private int $classId;

    private int $fareId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->flight = $this->makeFlight(now()->addDays(5)->setTime(8, 0));
        $this->actingAsRole('airline-admin');

        $rbd = $this->postJson('/api/v1/admin/rbds', ['code' => 'M', 'cabin_class' => 'Y', 'rank' => 3])->assertCreated()->json('data.id');
        $this->classId = $this->postJson('/api/v1/admin/fare-classes', [
            'rbd_id' => $rbd, 'booking_code' => 'MOW', 'status' => 'HK', 'trip_type' => 'OW',
            'ticket_time_limit_hours' => 24, 'ticket_time_limit_before_departure_hours' => 72,
        ])->assertCreated()->assertJsonPath('data.ticket_time_limit_hours', 24)->json('data.id');
        $this->fareId = $this->postJson('/api/v1/admin/fares', [
            'fare_class_id' => $this->classId, 'origin_airport_id' => $this->ubn->id, 'destination_airport_id' => $this->pqc->id,
            'valid_from' => today()->toDateString(), 'valid_to' => today()->addMonth()->toDateString(), 'base_amount' => 1000000,
        ])->assertCreated()->json('data.id');
    }

    private function book(bool $onHold = true)
    {
        return $this->postJson('/api/v1/admin/bookings', ['flight_ids' => [$this->flight->id], 'on_hold' => $onHold,
            'passengers' => [$this->passengerPayload(['fare_id' => $this->fareId])]]);
    }

    public function test_a_held_booking_has_no_ticket_until_issued_before_its_time_limit(): void
    {
        $booking = $this->book()->assertCreated()->assertJsonPath('data.status', 'on_hold')
            ->assertJsonPath('data.passengers.0.segments.0.ticket_number', null)->json('data');

        // 24 h after booking is earlier than 72 h before departure (5 days away)
        $deadline = now()->parse($booking['ticketing_deadline_at']);
        $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, $deadline->timestamp, 5);

        // no check-in without a ticket
        $this->flight->update(['status' => 'checkin_open']);
        $segment = Booking::find($booking['id'])->passengers->first()->segments->first();
        $this->actingAsRole('station-agent');
        $this->postJson("/api/v1/dcs/segments/{$segment->id}/check-in", ['seat_number' => '5A'])
            ->assertStatus(422)->assertJsonPath('code', 'not_ticketed');

        $this->actingAsRole('airline-admin');
        $this->postJson("/api/v1/admin/bookings/{$booking['id']}/issue")->assertOk()
            ->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.ticketing_deadline_at', null)
            ->assertJsonPath('data.passengers.0.segments.0.ticket_number', fn ($t) => strlen((string) $t) === 13);
        $this->postJson("/api/v1/admin/bookings/{$booking['id']}/issue")->assertStatus(422)->assertJsonPath('code', 'not_on_hold');
    }

    public function test_a_hold_not_ticketed_by_its_time_limit_is_cancelled(): void
    {
        $id = $this->book()->assertCreated()->json('data.id');

        $this->travel(25)->hours();
        $this->artisan('bookings:cancel-expired-holds')->assertSuccessful();

        $this->assertSame('cancelled', Booking::find($id)->status->value);
        $this->postJson("/api/v1/admin/bookings/{$id}/issue")->assertStatus(422)->assertJsonPath('code', 'not_on_hold');
    }

    public function test_a_fare_without_time_limit_cannot_be_held(): void
    {
        $this->putJson("/api/v1/admin/fare-classes/{$this->classId}", [
            'ticket_time_limit_hours' => null, 'ticket_time_limit_before_departure_hours' => null,
        ])->assertOk();

        $this->book()->assertStatus(422)->assertJsonPath('code', 'no_time_limit');
        $this->book(false)->assertCreated()->assertJsonPath('data.status', 'confirmed');
    }
}
