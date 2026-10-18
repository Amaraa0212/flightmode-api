<?php

namespace Tests\Feature\Admin;

use App\Models\Airline;
use App\Models\Flight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

class FlightSetupTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->actingAsRole('airline-admin');
    }

    public function test_aircraft_with_cabins_and_seat_map(): void
    {
        $id = $this->postJson('/api/v1/admin/aircraft', [
            'registration' => 'JU-2002', 'type' => 'A320', 'total_seats' => 12, 'cabins' => ['C' => 4, 'Y' => 8],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/admin/aircraft/{$id}/seats/generate", [
            'row_from' => 1, 'row_to' => 1, 'cabin_class' => 'C', 'columns' => ['left' => 'AC', 'right' => 'DF'], 'zone' => 'A',
        ])->assertCreated()->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.seat_number', '1A')
            ->assertJsonPath('data.0.seat_type', 'window')
            ->assertJsonPath('data.1.seat_type', 'aisle');

        // Too many seats for the Y cabin (8 allowed)
        $this->postJson("/api/v1/admin/aircraft/{$id}/seats/generate", [
            'row_from' => 2, 'row_to' => 4, 'cabin_class' => 'Y', 'columns' => ['left' => 'ABC', 'right' => 'DEF'],
        ])->assertStatus(422)->assertJsonPath('code', 'cabin_capacity_exceeded');

        // Cabins must add up to total seats
        $this->postJson('/api/v1/admin/aircraft', [
            'registration' => 'JU-3003', 'type' => 'A320', 'total_seats' => 10, 'cabins' => ['Y' => 8],
        ])->assertStatus(422)->assertJsonValidationErrors('cabins');
    }

    public function test_flight_series_generates_flights_in_utc(): void
    {
        $start = now()->next('Monday')->startOfDay();

        $response = $this->postJson('/api/v1/admin/flight-series', [
            'aircraft_id' => $this->aircraft->id,
            'flight_number' => 'ta 555',
            'origin_airport_id' => $this->ubn->id,
            'destination_airport_id' => $this->pqc->id,
            'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->addDays(13)->toDateString(),
            'days_of_week' => [1, 3],
            'departure_time' => '09:30',
            'arrival_time' => '14:00',
        ])->assertCreated();

        $this->assertStringContainsString('4 created', $response->json('message'));
        $flight = Flight::query()->orderBy('scheduled_departure_at')->first();
        $this->assertSame('TA555', $flight->flight_number);
        // 09:30 Ulaanbaatar (UTC+8) = 01:30 UTC
        $this->assertSame('01:30', $flight->scheduled_departure_at->format('H:i'));
        // 14:00 Ho Chi Minh (UTC+7) = 07:00 UTC
        $this->assertSame('07:00', $flight->scheduled_arrival_at->format('H:i'));

        // Shrinking the series removes flights without passengers
        $seriesId = $response->json('data.id');
        $this->putJson("/api/v1/admin/flight-series/{$seriesId}", [
            'aircraft_id' => $this->aircraft->id,
            'flight_number' => 'TA555',
            'origin_airport_id' => $this->ubn->id,
            'destination_airport_id' => $this->pqc->id,
            'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->addDays(13)->toDateString(),
            'days_of_week' => [1],
            'departure_time' => '10:00',
            'arrival_time' => '14:00',
        ])->assertOk();

        $this->assertSame(2, Flight::count());
        $this->assertSame('02:00', Flight::query()->first()->scheduled_departure_at->format('H:i'));

        // one flight of the series moved by hand keeps its time when the series is saved again
        $moved = Flight::query()->orderBy('scheduled_departure_at')->first();
        $this->putJson("/api/v1/admin/flights/{$moved->id}", [
            'aircraft_id' => $this->aircraft->id, 'flight_number' => 'TA555',
            'origin_airport_id' => $this->ubn->id, 'destination_airport_id' => $this->pqc->id,
            'scheduled_departure_at' => $moved->scheduled_departure_at->copy()->setTime(5, 0)->toIso8601String(),
            'scheduled_arrival_at' => $moved->scheduled_arrival_at->copy()->setTime(10, 0)->toIso8601String(),
        ])->assertOk()->assertJsonPath('data.is_series_override', true);

        $this->putJson("/api/v1/admin/flight-series/{$seriesId}", [
            'aircraft_id' => $this->aircraft->id, 'flight_number' => 'TA555',
            'origin_airport_id' => $this->ubn->id, 'destination_airport_id' => $this->pqc->id,
            'period_start' => $start->toDateString(), 'period_end' => $start->copy()->addDays(13)->toDateString(),
            'days_of_week' => [1], 'departure_time' => '11:00', 'arrival_time' => '15:00',
        ])->assertOk();

        $this->assertSame('05:00', $moved->refresh()->scheduled_departure_at->format('H:i'));
        $this->assertSame('03:00', Flight::query()->whereKeyNot($moved->id)->first()->scheduled_departure_at->format('H:i'));
    }

    public function test_flight_life_cycle(): void
    {
        $flight = $this->makeFlight();

        $this->postJson("/api/v1/admin/flights/{$flight->id}/status", ['action' => 'open_checkin'])->assertOk()
            ->assertJsonPath('data.status', 'checkin_open');
        $this->postJson("/api/v1/admin/flights/{$flight->id}/status", ['action' => 'close_checkin', 'close_online' => true])->assertOk()
            ->assertJsonPath('data.status', 'checkin_closed');
        $this->postJson("/api/v1/admin/flights/{$flight->id}/status", ['action' => 'close_gate'])->assertOk();
        $this->postJson("/api/v1/admin/flights/{$flight->id}/status", [
            'action' => 'depart', 'off_block_at' => now()->toIso8601String(), 'takeoff_at' => now()->addMinutes(10)->toIso8601String(),
        ])->assertOk()->assertJsonPath('data.status', 'departed');

        $this->postJson("/api/v1/admin/flights/{$flight->id}/status", ['action' => 'open_checkin'])
            ->assertStatus(422)->assertJsonPath('code', 'flight_closed');

        $this->getJson("/api/v1/admin/activity-logs?flight_id={$flight->id}")->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/v1/admin/activity-logs?flight='.strtolower($flight->flight_number))->assertOk()->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.flight.flight_number', $flight->flight_number);
        $this->getJson('/api/v1/admin/activity-logs?flight=ZZ9')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/admin/activity-logs?passenger=Nobody')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_airline_user_cannot_see_other_airline_flights(): void
    {
        $other = Airline::create(['name' => 'Other']);
        $foreign = $this->makeFlight(null, ['airline_id' => $other->id, 'flight_number' => 'OT1']);

        $this->getJson("/api/v1/admin/flights/{$foreign->id}")->assertForbidden();
        $this->getJson('/api/v1/admin/flights?from='.now()->toDateString().'&to='.now()->addWeek()->toDateString())
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_flights_are_searched_by_number_route_and_registration_and_filtered_by_source(): void
    {
        $range = 'from='.now()->toDateString().'&to='.now()->addWeek()->toDateString();
        $single = $this->makeFlight(null, ['flight_number' => 'TA101']);
        $back = $this->makeFlight(now()->addDays(4)->setTime(9, 0), [
            'flight_number' => 'TA102', 'origin_airport_id' => $this->pqc->id, 'destination_airport_id' => $this->ubn->id,
        ]);

        $ids = fn (string $query) => collect($this->getJson("/api/v1/admin/flights?{$range}&{$query}")->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$back->id], $ids('search=102'));
        $this->assertSame([$single->id, $back->id], $ids('search=pqc'));
        $this->assertSame([$single->id, $back->id], $ids('search=JU-1001'));
        $this->assertSame([$single->id, $back->id], $ids('source=single'));
        $this->assertSame([], $ids('source=series'));
    }
}
