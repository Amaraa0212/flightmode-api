<?php

namespace Tests\Feature\Agency;

use App\Models\AgencyAccount;
use App\Models\AgencyAllocation;
use App\Models\Fare;
use App\Models\Flight;
use App\Models\Passenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

class HoldAndAccountTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    private Flight $flight;

    private AgencyAllocation $allocation;

    private int $bucketId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->flight = $this->makeFlight(now()->addDays(5)->setTime(8, 0));
        $fare = Fare::create([
            'airline_id' => $this->airline->id, 'origin_airport_id' => $this->ubn->id, 'destination_airport_id' => $this->pqc->id,
            'trip_type' => 'OW', 'cabin_class' => 'Y', 'fare_class' => 'M', 'valid_from' => today(), 'valid_to' => today()->addMonth(),
            'base_amount' => 1000000, 'tax_amount' => 50000,
        ]);
        $this->allocation = $this->makeAllocation($this->flight, 6, ['Y' => 6], ['fares' => [['fare_id' => $fare->id, 'cabin_class' => 'Y', 'seats' => 5]]]);
        $this->bucketId = $this->allocation->fares->first()->id;
    }

    private function hold(int $seats): int
    {
        return $this->postJson('/api/v1/agency/holds', [
            'allocation_id' => $this->allocation->id, 'cabin_class' => 'Y', 'seats' => $seats, 'allocation_fare_id' => $this->bucketId,
        ])->assertCreated()->json('data.id');
    }

    private function enterNames(int $holdId, int $count = 1): TestResponse
    {
        $passengers = [];
        for ($i = 0; $i < $count; $i++) {
            $passengers[] = $this->passengerPayload(['cabin_class' => 'C']);   // a hold fixes the cabin
        }

        return $this->postJson('/api/v1/agency/bookings', ['allocation_id' => $this->allocation->id, 'hold_id' => $holdId, 'passengers' => $passengers]);
    }

    private function topUp(float $amount): void
    {
        $this->actingAsRole('airline-admin');
        $this->postJson('/api/v1/admin/agency-accounts/top-up', ['agency_id' => $this->agency->id, 'amount' => $amount, 'note' => 'Bank transfer'])
            ->assertCreated();
        $this->actingAsRole('agency-agent');
    }

    public function test_agency_finds_its_flights_of_a_day(): void
    {
        $this->actingAsRole('agency-agent');
        $date = $this->flight->scheduled_departure_at->copy()->timezone('Asia/Ulaanbaatar')->toDateString();

        $this->getJson("/api/v1/agency/flights?date={$date}")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->allocation->id)
            ->assertJsonPath('data.0.usage.remaining', 6)
            ->assertJsonPath('data.0.usage.fares.0.price.fare', '1000000.00');
        $this->getJson('/api/v1/agency/flights?date='.today()->addDays(20)->toDateString())->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_held_seats_are_kept_and_names_are_entered_on_them_later(): void
    {
        $this->actingAsRole('agency-agent');
        $holdId = $this->hold(3);

        $usage = $this->getJson("/api/v1/agency/allocations/{$this->allocation->id}")->json('data.usage');
        $this->assertSame(3, $usage['held']);
        $this->assertSame(3, $usage['remaining']);
        $this->assertSame(2, $usage['fares'][0]['remaining']);   // 5 - 3 held

        // a hold cannot take more than what is free
        $this->postJson('/api/v1/agency/holds', ['allocation_id' => $this->allocation->id, 'cabin_class' => 'Y', 'seats' => 3, 'allocation_fare_id' => $this->bucketId])
            ->assertStatus(422)->assertJsonPath('code', 'not_enough_seats');

        $this->enterNames($holdId, 2)->assertCreated()->assertJsonPath('data.passengers.0.segments.0.cabin_class', 'Y');
        $hold = collect($this->getJson('/api/v1/agency/holds')->assertOk()->json('data'))->firstWhere('id', $holdId);
        $this->assertSame(['seats' => 3, 'named' => 2, 'remaining' => 1, 'is_open_for_names' => true], $hold['usage']);

        $this->enterNames($holdId, 2)->assertStatus(422)->assertJsonPath('code', 'hold_full');

        // releasing frees the seat without a name
        $this->deleteJson("/api/v1/agency/holds/{$holdId}")->assertOk();
        $usage = $this->getJson("/api/v1/agency/allocations/{$this->allocation->id}")->json('data.usage');
        $this->assertSame(0, $usage['held']);
        $this->assertSame(4, $usage['remaining']);
        $this->enterNames($holdId)->assertStatus(422)->assertJsonPath('code', 'hold_not_usable');
    }

    public function test_held_seats_are_not_sold_without_the_hold(): void
    {
        $this->actingAsRole('agency-agent');
        $this->hold(5);   // the whole fare quota

        $this->postJson('/api/v1/agency/bookings', ['allocation_id' => $this->allocation->id,
            'passengers' => [$this->passengerPayload(['allocation_fare_id' => $this->bucketId])]])
            ->assertStatus(422)->assertJsonPath('code', 'fare_full');
    }

    public function test_tickets_are_charged_to_the_account_and_refunded_on_cancel(): void
    {
        $this->topUp(2_500_000);
        $holdId = $this->hold(3);

        $this->enterNames($holdId, 2)->assertCreated();
        $account = AgencyAccount::query()->firstOrFail();
        $this->assertSame('400000.00', $account->balance);   // 2 × (1 000 000 + 50 000)

        // not enough for one more: the whole booking is refused
        $this->enterNames($holdId)->assertStatus(422)->assertJsonPath('code', 'insufficient_balance');
        $this->assertSame(2, Passenger::query()->count());

        $passenger = Passenger::query()->firstOrFail();
        $this->deleteJson("/api/v1/agency/passengers/{$passenger->id}")->assertOk();
        $this->assertSame('1450000.00', $account->refresh()->balance);

        $types = collect($this->getJson("/api/v1/agency/accounts/{$account->id}/transactions")->assertOk()->json('data'))->pluck('type')->all();
        $this->assertSame(['refund', 'ticket', 'ticket', 'topup'], $types);
        $this->getJson('/api/v1/agency/accounts')->assertOk()->assertJsonPath('data.0.balance', '1450000.00');
    }

    public function test_agencies_without_an_account_sell_as_before_and_admins_see_every_contract(): void
    {
        $this->actingAsRole('agency-agent');
        $this->enterNames($this->hold(1))->assertCreated();
        $this->assertSame(0, AgencyAccount::query()->count());

        $this->actingAsRole('airline-admin');
        $this->getJson('/api/v1/admin/agency-accounts')->assertOk()
            ->assertJsonPath('data.0.agency', 'Test Travel')->assertJsonPath('data.0.balance', null);
        $this->postJson('/api/v1/admin/agency-accounts/top-up', ['agency_id' => $this->agency->id, 'amount' => -5, 'type' => 'adjustment'])
            ->assertStatus(422)->assertJsonPath('code', 'balance_below_zero');
        $this->postJson('/api/v1/admin/agency-accounts/top-up', ['agency_id' => $this->agency->id, 'amount' => -5])
            ->assertStatus(422)->assertJsonPath('code', 'topup_not_positive');

        $this->actingAsRole('airline-staff');
        $this->getJson('/api/v1/admin/agency-accounts')->assertForbidden();
    }
}
