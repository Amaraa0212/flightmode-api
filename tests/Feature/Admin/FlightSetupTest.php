<?php

namespace Tests\Feature\Admin;

use App\Enums\BookingSource;
use App\Models\Airline;
use App\Models\Flight;
use App\Services\Booking\BookingService;
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
        // closing and departing belong to OCC
        $this->actingAsRole('occ');
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

    public function test_only_occ_closes_and_only_airline_admin_cancels_or_reopens(): void
    {
        $flight = $this->makeFlight();
        $status = fn (array $body) => $this->postJson("/api/v1/admin/flights/{$flight->id}/status", $body);
        $status(['action' => 'open_checkin'])->assertOk();

        // the airline administrator (and staff / station) cannot close; OCC cannot cancel or reopen
        $status(['action' => 'close_checkin'])->assertForbidden();
        $this->actingAsRole('station-agent');
        $status(['action' => 'close_gate'])->assertForbidden();
        $this->actingAsRole('occ');
        $status(['action' => 'close_gate'])->assertOk()->assertJsonPath('data.status', 'gate_closed');
        $status(['action' => 'reopen'])->assertForbidden();
        $status(['action' => 'cancel', 'reason' => 'Weather'])->assertForbidden();

        $this->actingAsRole('airline-admin');
        $status(['action' => 'reopen'])->assertOk()->assertJsonPath('data.status', 'checkin_open');
        $status(['action' => 'cancel', 'reason' => 'Weather'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $status(['action' => 'reopen'])->assertOk()->assertJsonPath('data.status', 'scheduled');
        $status(['action' => 'reopen'])->assertStatus(422)->assertJsonPath('code', 'cannot_reopen');
    }

    public function test_flight_load_month_calendar(): void
    {
        $flight = $this->makeFlight(now()->addMonthNoOverflow()->startOfMonth()->setTime(9, 0), ['service_type' => 'charter']);
        app(BookingService::class)->create(['flight_ids' => [$flight->id], 'passengers' => [
            $this->passengerPayload(['infant' => ['first_name' => 'Tuya', 'last_name' => 'Bat', 'date_of_birth' => now()->subMonths(8)->toDateString(),
                'document_expires_at' => now()->addYears(4)->toDateString()]]),
            $this->passengerPayload(['first_name' => 'Tomor', 'date_of_birth' => now()->subYears(7)->toDateString()]),
        ]], $this->makeUser('system-admin'), BookingSource::Admin);
        $month = $flight->scheduled_departure_at->copy()->timezone('Asia/Ulaanbaatar')->format('Y-m');

        $row = $this->getJson("/api/v1/admin/flight-board/calendar?month={$month}")->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame(['ADT' => 1, 'CHD' => 1, 'INF' => 1, 'total' => 2], $row['booked']);
        $this->assertSame([56, 54, 'charter', '17:00'], [$row['capacity'], $row['free'], $row['service_type'], $row['time']]);

        $this->getJson('/api/v1/admin/flight-board/calendar?month='.now()->addMonthsNoOverflow(3)->format('Y-m'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_several_flights_are_created_at_once_from_a_local_time_and_block_time(): void
    {
        $dates = [now()->addDays(10)->toDateString(), now()->addDays(12)->toDateString()];
        $body = ['aircraft_id' => $this->aircraft->id, 'flight_number' => 'ta 777', 'origin_airport_id' => $this->ubn->id,
            'destination_airport_id' => $this->pqc->id, 'service_type' => 'charter', 'departure_time' => '23:30', 'block_minutes' => 370];

        $this->postJson('/api/v1/admin/flights/bulk', $body + ['dates' => $dates])->assertCreated()->assertJsonCount(2, 'data.created');
        $flight = Flight::query()->where('flight_number', 'TA777')->orderBy('scheduled_departure_at')->first();
        // 23:30 in Ulaanbaatar (UTC+8) = 15:30 UTC, + 6 h 10 min block
        $this->assertSame(['15:30', '21:40', 'charter'], [$flight->scheduled_departure_at->format('H:i'), $flight->scheduled_arrival_at->format('H:i'), $flight->service_type->value]);

        // the same day again is skipped, a new one is added
        $this->postJson('/api/v1/admin/flights/bulk', $body + ['dates' => [$dates[0], now()->addDays(14)->toDateString()]])->assertCreated()
            ->assertJsonCount(1, 'data.created')->assertJsonPath('data.skipped', [$dates[0]]);

        // the latest flight fills a new one
        $this->getJson('/api/v1/admin/flights/latest?flight_number=TA777')->assertOk()
            ->assertJsonPath('data.departure_time', '23:30')->assertJsonPath('data.block_minutes', 370)
            ->assertJsonPath('data.destination_airport_id', $this->pqc->id);
        $this->getJson('/api/v1/admin/flights/latest?flight_number=ZZ1')->assertOk()->assertJsonPath('data', null);
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
