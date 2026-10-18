<?php

namespace Tests\Feature\Admin;

use App\Enums\FlightStatus;
use App\Models\BaggageRule;
use App\Models\SeatPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

/** Lists the admin screens read: fares, flight products (Shop items), load zones, auto-seat profiles. */
class AdminProductListsTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->actingAsRole('airline-admin');
    }

    private function fare(array $attributes): void
    {
        $this->postJson('/api/v1/admin/fares', array_merge([
            'origin_airport_id' => $this->ubn->id, 'destination_airport_id' => $this->pqc->id,
            'trip_type' => 'OW', 'cabin_class' => 'Y', 'fare_class' => 'Y',
            'valid_from' => today()->toDateString(), 'valid_to' => today()->addMonth()->toDateString(),
            'base_amount' => 500000, 'tax_amount' => 95000,
        ], $attributes))->assertCreated();
    }

    public function test_fares_search_by_route_or_rbd_and_filter_by_many_cabins(): void
    {
        $this->fare([]);
        $this->fare(['cabin_class' => 'C', 'fare_class' => 'J']);
        $this->fare(['origin_airport_id' => $this->pqc->id, 'destination_airport_id' => $this->ubn->id, 'trip_type' => 'RT']);

        $this->getJson('/api/v1/admin/fares?search=UBN-PQC')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/admin/fares?search=pqc')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/admin/fares?search=J')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.cabin_class', 'C');
        $this->getJson('/api/v1/admin/fares?cabin_class[]=C&cabin_class[]=Y')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/admin/fares?cabin_class=C')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/fares?trip_type[]=RT')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.origin', 'PQC')
            ->assertJsonPath('data.0.total_amount', '595000.00');
    }

    public function test_flights_with_products_and_open_only(): void
    {
        $flight = $this->makeFlight();
        $this->makeFlight(null, ['flight_number' => 'TA102', 'status' => FlightStatus::Cancelled]);

        $rule = BaggageRule::create(['airline_id' => $this->airline->id, 'name' => '1PC 23KG', 'type' => 'piece',
            'pieces' => 1, 'weight_kg' => 23, 'max_quantity' => 1]);
        $price = SeatPrice::create(['airline_id' => $this->airline->id, 'cabin_class' => 'Y', 'price' => 30000]);
        $profile = $this->aircraft->seatAssignmentProfiles()->create(['name' => 'Back first']);
        $flight->baggageRules()->attach($rule->id);
        $flight->seatPrices()->attach($price->id);
        $flight->update(['seat_assignment_profile_id' => $profile->id]);
        $this->postJson("/api/v1/admin/flights/{$flight->id}/seat-blocks", ['seat_numbers' => ['3A', '3B']])->assertOk();

        $query = ['from' => today()->toDateString(), 'to' => today()->addWeek()->toDateString()];
        $this->getJson('/api/v1/admin/flights?'.http_build_query($query))->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('data.0.blocked_seats_count');

        $this->getJson('/api/v1/admin/flights?'.http_build_query($query + ['open' => 1, 'with_products' => 1]))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.flight_number', 'TA101')
            ->assertJsonPath('data.0.blocked_seats_count', 2)
            ->assertJsonPath('data.0.online_checkin.baggage_rules', ['1PC 23KG'])
            ->assertJsonPath('data.0.online_checkin.seat_prices_count', 1)
            ->assertJsonPath('data.0.online_checkin.seat_assignment_profile', 'Back first');
    }

    public function test_load_zones_are_replaced_as_a_list(): void
    {
        $path = "/api/v1/admin/aircraft/{$this->aircraft->id}/load-zones";

        $this->putJson($path, ['zones' => [
            ['zone' => 'c', 'pax_from' => 31, 'pax_to' => 56],
            ['zone' => 'b', 'pax_from' => 1, 'pax_to' => 30],
        ]])->assertOk();
        $this->getJson($path)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.zone', 'B')
            ->assertJsonPath('data.1.zone', 'C');

        $this->putJson($path, ['zones' => [['zone' => 'A', 'pax_from' => 10, 'pax_to' => 5]]])
            ->assertStatus(422)->assertJsonValidationErrors('zones.0.pax_to');
        $this->putJson($path, ['zones' => []])->assertOk();
        $this->getJson($path)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_seat_assignment_profiles_crud(): void
    {
        $path = "/api/v1/admin/aircraft/{$this->aircraft->id}/seat-assignment-profiles";

        $id = $this->postJson($path, ['name' => 'Back first', 'zones' => ['c', 'b', 'a']])->assertCreated()
            ->assertJsonPath('data.zones', ['C', 'B', 'A'])->json('data.id');
        $this->putJson("{$path}/{$id}", ['name' => 'Front first', 'zones' => ['A', 'B']])->assertOk()
            ->assertJsonPath('data.zones', ['A', 'B']);
        $this->getJson($path)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Front first');
        $this->postJson($path, ['name' => 'Dup', 'zones' => ['A', 'A']])->assertStatus(422);
        $this->deleteJson("{$path}/{$id}")->assertOk();
        $this->getJson($path)->assertOk()->assertJsonCount(0, 'data');
    }
}
