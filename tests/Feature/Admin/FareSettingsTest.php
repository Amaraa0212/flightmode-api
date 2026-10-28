<?php

namespace Tests\Feature\Admin;

use App\Enums\PassengerType;
use App\Models\Airline;
use App\Models\Fare;
use App\Models\PassengerSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

class FareSettingsTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->actingAsRole('airline-admin');
    }

    /** RBD M (Y) -> fare class MOW with CHD 25 % / INF 90 %, taxes UBN out 30 000 + PQC in 20 000 for ADT. */
    private function buildFareSetup(): array
    {
        $rbd = $this->postJson('/api/v1/admin/rbds', ['code' => 'M', 'cabin_class' => 'Y', 'rank' => 3])->assertCreated()->json('data.id');
        $class = $this->postJson('/api/v1/admin/fare-classes', [
            'rbd_id' => $rbd, 'booking_code' => 'MOW', 'status' => 'HK', 'trip_type' => 'OW', 'description' => 'Economy one way',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/admin/fare-classes/{$class}/discounts", ['passenger_type' => 'CHD', 'age_from' => 2, 'age_to' => 11, 'percentage' => 25])->assertCreated();
        $this->postJson("/api/v1/admin/fare-classes/{$class}/discounts", ['passenger_type' => 'INF', 'age_to' => 1, 'percentage' => 90])->assertCreated();

        $tax = $this->postJson('/api/v1/admin/tax-codes', ['code' => 'MN', 'name' => 'Airport fee'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/admin/fare-taxes', ['tax_code_id' => $tax, 'airport_id' => $this->ubn->id, 'bound' => 'OUT', 'passenger_type' => 'ADT', 'amount' => 30000])->assertCreated();
        $this->postJson('/api/v1/admin/fare-taxes', ['tax_code_id' => $tax, 'airport_id' => $this->pqc->id, 'bound' => 'IN', 'passenger_type' => 'ADT', 'amount' => 20000])->assertCreated();
        // arriving at UBN: not charged on UBN -> PQC
        $this->postJson('/api/v1/admin/fare-taxes', ['tax_code_id' => $tax, 'airport_id' => $this->ubn->id, 'bound' => 'IN', 'passenger_type' => 'ADT', 'amount' => 99999])->assertCreated();

        $fare = $this->postJson('/api/v1/admin/fares', [
            'fare_class_id' => $class, 'origin_airport_id' => $this->ubn->id, 'destination_airport_id' => $this->pqc->id,
            'valid_from' => today()->toDateString(), 'valid_to' => today()->addMonth()->toDateString(), 'base_amount' => 1000000,
        ])->assertCreated()->assertJsonPath('data.booking_code', 'MOW')->json('data.id');

        return compact('rbd', 'class', 'tax', 'fare');
    }

    public function test_fare_class_fare_is_priced_from_discounts_and_route_taxes(): void
    {
        ['fare' => $fareId, 'tax' => $tax] = $this->buildFareSetup();
        $fare = Fare::query()->findOrFail($fareId);

        // flat columns synced for allocations / reports
        $this->assertSame('Y', $fare->cabin_class->value);
        $this->assertSame('M', $fare->fare_class);
        $this->assertSame('OW', $fare->trip_type->value);
        $this->assertSame('50000.00', $fare->tax_amount);
        $this->assertSame(25, $fare->child_discount_percent);
        $this->assertSame(90, $fare->infant_discount_percent);

        $this->assertSame(['fare' => '1000000.00', 'tax' => '50000.00'], $fare->priceFor(PassengerType::Adult));
        $this->assertSame(['fare' => '750000.00', 'tax' => '0.00'], $fare->priceFor(PassengerType::Child));
        $this->assertSame(['fare' => '100000.00', 'tax' => '0.00'], $fare->priceFor(PassengerType::Infant));

        // an override on the fare replaces the UBN default of the same tax code
        $this->postJson('/api/v1/admin/fare-taxes', ['tax_code_id' => $tax, 'fare_id' => $fareId, 'bound' => 'OUT', 'passenger_type' => 'ADT', 'amount' => 10000])->assertCreated();
        $fare->refresh();
        $this->assertSame('10000.00', $fare->priceFor(PassengerType::Adult)['tax']);
        $this->assertSame('10000.00', $fare->tax_amount);

        $this->getJson("/api/v1/admin/fare-taxes?origin_airport_id={$this->ubn->id}&destination_airport_id={$this->pqc->id}")
            ->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/admin/fare-taxes?fare_id={$fareId}")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_changing_a_discount_or_rbd_resyncs_fares(): void
    {
        ['rbd' => $rbd, 'class' => $class, 'fare' => $fareId] = $this->buildFareSetup();
        $discount = $this->getJson("/api/v1/admin/fare-classes/{$class}/discounts")->assertOk()->json('data.0');

        $this->putJson("/api/v1/admin/fare-classes/{$class}/discounts/{$discount['id']}", ['passenger_type' => 'CHD', 'percentage' => 50])->assertOk();
        $this->putJson("/api/v1/admin/rbds/{$rbd}", ['code' => 'K'])->assertOk();

        $fare = Fare::query()->findOrFail($fareId);
        $this->assertSame(50, $fare->child_discount_percent);
        $this->assertSame('K', $fare->fare_class);

        // one discount per passenger type
        $this->postJson("/api/v1/admin/fare-classes/{$class}/discounts", ['passenger_type' => 'CHD', 'percentage' => 10])
            ->assertStatus(422)->assertJsonValidationErrors('passenger_type');
    }

    public function test_used_rbd_fare_class_and_tax_code_cannot_be_deleted(): void
    {
        ['rbd' => $rbd, 'class' => $class, 'tax' => $tax] = $this->buildFareSetup();

        $this->deleteJson("/api/v1/admin/rbds/{$rbd}")->assertStatus(422)->assertJsonPath('code', 'rbd_in_use');
        $this->deleteJson("/api/v1/admin/fare-classes/{$class}")->assertStatus(422)->assertJsonPath('code', 'fare_class_in_use');
        $this->deleteJson("/api/v1/admin/tax-codes/{$tax}")->assertStatus(422)->assertJsonPath('code', 'tax_code_in_use');

        $this->postJson('/api/v1/admin/rbd-modifications', ['rbd_id' => $rbd, 'type' => 'REFUND', 'timeframe_hours' => 24, 'percentage' => 30])
            ->assertCreated()->assertJsonPath('data.rbd_code', 'M');
    }

    public function test_modification_penalty_is_an_amount_with_currency_or_a_percentage(): void
    {
        ['rbd' => $rbd] = $this->buildFareSetup();
        $rule = ['rbd_id' => $rbd, 'type' => 'DATE_CHANGE', 'timeframe_hours' => 48];

        $this->postJson('/api/v1/admin/rbd-modifications', $rule + ['amount' => 50000, 'percentage' => 10])
            ->assertStatus(422)->assertJsonValidationErrors(['amount', 'percentage']);
        $this->postJson('/api/v1/admin/rbd-modifications', $rule + ['amount' => 50000])
            ->assertStatus(422)->assertJsonValidationErrors('currency');

        $this->postJson('/api/v1/admin/rbd-modifications', $rule + ['amount' => 50000, 'currency' => 'MNT'])
            ->assertCreated()->assertJsonPath('data.currency', 'MNT')->assertJsonPath('data.percentage', null);
    }

    public function test_validation_and_airline_isolation(): void
    {
        $this->postJson('/api/v1/admin/rbds', ['code' => 'm1', 'cabin_class' => 'Y', 'rank' => 1])->assertStatus(422)->assertJsonValidationErrors('code');
        $rbd = $this->postJson('/api/v1/admin/rbds', ['code' => 'Y', 'cabin_class' => 'Y', 'rank' => 1])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/admin/rbds', ['code' => 'Y', 'cabin_class' => 'Y', 'rank' => 2])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->putJson("/api/v1/admin/rbds/{$rbd}", ['parent_id' => $rbd])->assertStatus(422)->assertJsonValidationErrors('parent_id');
        $this->postJson('/api/v1/admin/fare-classes', ['rbd_id' => $rbd, 'booking_code' => 'Y OW', 'status' => 'HK', 'trip_type' => 'OW'])
            ->assertStatus(422)->assertJsonValidationErrors('booking_code');

        // another airline's user sees nothing and cannot use this RBD
        $other = Airline::create(['name' => 'Other Air', 'iata_code' => 'OT', 'numeric_code' => '456']);
        $this->actingAsRole('airline-admin', ['airline_id' => $other->id]);
        $this->getJson('/api/v1/admin/rbds')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/admin/fare-classes', ['rbd_id' => $rbd, 'booking_code' => 'YOW', 'status' => 'HK', 'trip_type' => 'OW'])
            ->assertStatus(422)->assertJsonValidationErrors('rbd_id');
    }

    public function test_agency_booking_uses_fare_class_price(): void
    {
        ['fare' => $fareId] = $this->buildFareSetup();
        $flight = $this->makeFlight();
        $allocation = $this->makeAllocation($flight, 5, ['Y' => 5], ['fares' => [['fare_id' => $fareId, 'cabin_class' => 'Y', 'seats' => 2]]]);
        $this->actingAsRole('agency-agent');

        $this->postJson('/api/v1/agency/bookings', [
            'allocation_id' => $allocation->id,
            'passengers' => [$this->passengerPayload(['allocation_fare_id' => $allocation->fares->first()->id])],
        ])->assertCreated();

        $segment = PassengerSegment::query()->firstOrFail();
        $this->assertSame('1000000.00', $segment->fare_amount);
        $this->assertSame('50000.00', $segment->tax_amount);
        $this->assertSame('M', $segment->fare_class);
    }
}
