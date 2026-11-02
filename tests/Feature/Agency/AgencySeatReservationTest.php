<?php

namespace Tests\Feature\Agency;

use App\Enums\CheckinChannel;
use App\Enums\FlightStatus;
use App\Enums\SegmentStatus;
use App\Models\Agency;
use App\Models\AgencyAllocation;
use App\Models\Flight;
use App\Models\PassengerSegment;
use App\Services\Checkin\CheckinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

/**
 * Charter seat reservation by agencies (legacy OT, Oyu Tolgoi): only with the "Seats" right of the agency's
 * contract, only on charter flights before check-in, only for its own passengers, without checking them in.
 */
class AgencySeatReservationTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    private Flight $flight;

    private AgencyAllocation $allocation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->flight = $this->makeFlight(now()->addDays(5)->setTime(8, 0), ['service_type' => 'charter']);
        $this->allocation = $this->makeAllocation($this->flight, 10, ['Y' => 10]);
    }

    private function allowSeats(bool $allow = true): void
    {
        $this->agency->airlines()->updateExistingPivot($this->airline->id, ['can_assign_seats' => $allow]);
    }

    /** @return array<int> segment ids of the agency's new passengers */
    private function book(int $count = 2): array
    {
        $this->actingAsRole('agency-agent');
        $this->postJson('/api/v1/agency/bookings', [
            'allocation_id' => $this->allocation->id,
            'passengers' => array_map(fn ($i) => $this->passengerPayload(['person_code' => "SAP{$i}"]), range(1, $count)),
        ])->assertCreated();

        return PassengerSegment::query()->latest('id')->limit($count)->pluck('id')->sort()->values()->all();
    }

    private function url(string $suffix): string
    {
        return "/api/v1/agency/allocations/{$this->allocation->id}{$suffix}";
    }

    public function test_seats_need_the_right_and_a_charter_flight(): void
    {
        $this->book(1);
        $this->getJson($this->url('/seat-map'))->assertForbidden();
        $this->getJson($this->url(''))->assertOk()->assertJsonPath('data.rights.assign_seats', false);

        $this->allowSeats();
        $this->getJson($this->url(''))->assertJsonPath('data.rights.assign_seats', true);
        $this->getJson($this->url('/seat-map'))->assertOk()->assertJsonCount(1, 'data.passengers');

        $this->flight->update(['service_type' => 'scheduled']);
        $this->getJson($this->url('/seat-map'))->assertStatus(422)->assertJsonPath('code', 'not_charter');

        $this->flight->update(['service_type' => 'charter', 'status' => FlightStatus::CheckinOpen]);
        $this->getJson($this->url('/seat-map'))->assertStatus(422)->assertJsonPath('code', 'seat_reservation_closed');
    }

    public function test_agency_reserves_seats_for_its_passengers_without_check_in(): void
    {
        $this->allowSeats();
        [$a, $b] = $this->book();

        // another agency's passenger in 3A: occupied, anonymous
        $other = Agency::create(['name' => 'Other']);
        $other->airlines()->attach($this->airline->id, ['is_active' => true]);
        $otherAllocation = $this->makeAllocation($this->flight, 5, ['Y' => 5], ['agency_id' => $other->id]);
        $this->actingAsRole('agency-agent', ['agency_id' => $other->id]);
        $this->postJson('/api/v1/agency/bookings', ['allocation_id' => $otherAllocation->id, 'passengers' => [$this->passengerPayload()]])->assertCreated();
        PassengerSegment::query()->latest('id')->first()->update(['seat_number' => '3A']);

        $this->actingAsRole('agency-agent');
        $seats = collect($this->getJson($this->url('/seat-map'))->assertOk()->json('data.seats'))->keyBy('seat_number');
        $this->assertSame('occupied', $seats['3A']['state']);
        $this->assertArrayNotHasKey('occupant', $seats['3A']);
        // business is zone A: never offered (same as online check-in)
        $this->assertSame('restricted', $seats['1A']['state']);

        $this->putJson($this->url('/seats'), ['seats' => [['segment_id' => $a, 'seat_number' => '3A']]])
            ->assertStatus(422)->assertJsonPath('code', 'seat_not_available');
        $this->putJson($this->url('/seats'), ['seats' => [['segment_id' => $a, 'seat_number' => '4A'], ['segment_id' => $b, 'seat_number' => '4A']]])
            ->assertStatus(422)->assertJsonPath('code', 'seat_twice');

        $this->putJson($this->url('/seats'), ['seats' => [['segment_id' => $a, 'seat_number' => '4a'], ['segment_id' => $b, 'seat_number' => '4B']]])
            ->assertOk()->assertJsonPath('data.passengers.0.seat_number', '4A');
        $segment = PassengerSegment::query()->findOrFail($a);
        $this->assertSame('4A', $segment->seat_number);
        $this->assertSame(SegmentStatus::Booked, $segment->status);
        $this->assertTrue(collect($this->getJson($this->url('/seat-map'))->json('data.seats'))->firstWhere('seat_number', '4A')['own']);

        // swap the two in one request
        $this->putJson($this->url('/seats'), ['seats' => [['segment_id' => $a, 'seat_number' => '4B'], ['segment_id' => $b, 'seat_number' => '4A']]])
            ->assertOk();
        $this->assertSame('4B', $segment->refresh()->seat_number);

        // someone else's passenger cannot be seated by this agency
        $foreign = PassengerSegment::query()->where('seat_number', '3A')->value('id');
        $this->putJson($this->url('/seats'), ['seats' => [['segment_id' => $foreign, 'seat_number' => '5A']]])
            ->assertStatus(422)->assertJsonPath('code', 'segment_not_yours');

        // the counter check-in keeps the reserved seat
        $checkedIn = app(CheckinService::class)->checkIn($segment->refresh(), null, CheckinChannel::Dcs);
        $this->assertSame('4B', $checkedIn->seat_number);
        $this->assertSame(SegmentStatus::CheckedIn, $checkedIn->status);
    }

    public function test_auto_seats_everyone_without_a_seat(): void
    {
        $this->allowSeats();
        [$a] = $this->book(3);
        $this->putJson($this->url('/seats'), ['seats' => [['segment_id' => $a, 'seat_number' => '6C']]])->assertOk();

        $this->postJson($this->url('/seats/auto'))->assertOk()->assertJsonPath('message', '2 passenger(s) seated.');
        $seats = PassengerSegment::query()->where('flight_id', $this->flight->id)->pluck('seat_number');
        $this->assertCount(3, $seats->filter()->unique());
        $this->assertSame('6C', PassengerSegment::query()->findOrFail($a)->seat_number);
    }

    public function test_excel_import_needs_its_right(): void
    {
        $this->agency->airlines()->updateExistingPivot($this->airline->id, ['can_import_passengers' => false]);
        $this->actingAsRole('agency-agent');
        $file = UploadedFile::fake()->createWithContent('pax.csv', "first_name,last_name\nBAT,DORJ\n");
        $this->postJson($this->url('/import'), ['file' => $file])->assertForbidden();
        $this->getJson($this->url(''))->assertJsonPath('data.rights.import_passengers', false);
    }

    public function test_airline_sets_the_rights_on_the_contract(): void
    {
        $this->actingAsRole('airline-admin');
        $this->putJson("/api/v1/admin/airlines/{$this->airline->id}/agencies/{$this->agency->id}", ['can_assign_seats' => true])->assertOk();
        $this->getJson("/api/v1/admin/agencies/{$this->agency->id}")->assertOk()
            ->assertJsonPath('data.airlines.0.can_assign_seats', true)
            ->assertJsonPath('data.airlines.0.can_import_passengers', true)
            ->assertJsonPath('data.airlines.0.contract_active', true);
    }
}
