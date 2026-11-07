<?php

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\Airline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

class CompanySettingsTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_airline_admin_changes_only_the_contacts_of_their_airline(): void
    {
        $this->actingAsRole('airline-admin');

        $this->putJson("/api/v1/admin/airlines/{$this->airline->id}", [
            'name' => 'Renamed Air', 'iata_code' => 'ZZ', 'numeric_code' => '999', 'currency' => 'USD', 'is_active' => false,
            'phone' => '+976 7000 0000', 'email' => 'ops@test.air', 'address' => 'Ulaanbaatar, Khan-Uul',
        ])->assertOk();

        $airline = $this->airline->refresh();
        $this->assertSame(['Test Air', 'TA', '123', true], [$airline->name, $airline->iata_code, $airline->numeric_code, $airline->is_active]);
        $this->assertSame(['+976 7000 0000', 'ops@test.air', 'Ulaanbaatar, Khan-Uul'], [$airline->phone, $airline->email, $airline->address]);
    }

    public function test_system_admin_changes_every_field(): void
    {
        $this->actingAsRole('system-admin');

        $this->putJson("/api/v1/admin/airlines/{$this->airline->id}", ['name' => 'Renamed Air', 'iata_code' => 'ZZ'])->assertOk();

        $this->assertSame(['Renamed Air', 'ZZ'], [$this->airline->refresh()->name, $this->airline->iata_code]);
    }

    public function test_an_airline_adds_its_own_sales_channels_that_other_airlines_never_see(): void
    {
        $this->actingAsRole('airline-admin');
        $id = $this->postJson('/api/v1/admin/agencies', ['name' => 'Hunnu ticket office', 'channel' => 'ticket_office'])
            ->assertCreated()->assertJsonPath('data.channel', 'ticket_office')
            ->assertJsonPath('data.owner_airline_id', $this->airline->id)->json('data.id');
        $this->getJson('/api/v1/admin/agencies?owned=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/agencies?channel[]=web')->assertOk()->assertJsonCount(0, 'data');

        // another airline: not listed, not readable, cannot be contracted (even by the system admin)
        $other = Airline::create(['name' => 'Other Air', 'iata_code' => 'OT', 'numeric_code' => '456']);
        $this->actingAsRole('airline-admin', ['airline_id' => $other->id]);
        $this->getJson('/api/v1/admin/agencies')->assertOk()->assertJsonMissing(['id' => $id]);
        $this->getJson("/api/v1/admin/agencies/{$id}")->assertForbidden();

        $this->actingAsRole('system-admin');
        $this->putJson("/api/v1/admin/airlines/{$other->id}/agencies/{$id}", ['is_active' => true])->assertForbidden();
        $this->putJson("/api/v1/admin/airlines/{$this->airline->id}/agencies/{$id}", ['is_active' => false])->assertOk();
    }

    public function test_agents_permission_lists_agencies_to_contract_but_not_other_airlines_channels_or_contracts(): void
    {
        $other = Airline::create(['name' => 'Other Air', 'iata_code' => 'OT', 'numeric_code' => '456']);
        $free = Agency::create(['name' => 'Created by super admin', 'code' => 'FREE', 'channel' => 'agency']);
        $free->airlines()->attach($other->id, ['is_active' => true]);
        $otherChannel = Agency::create(['name' => 'Other office', 'code' => 'OTHR', 'channel' => 'ticket_office', 'owner_airline_id' => $other->id]);

        $this->actingAsRole('airline-admin');
        $this->getJson('/api/v1/admin/agencies')->assertOk()->assertJsonMissing(['id' => $free->id]);

        $rows = collect($this->getJson('/api/v1/admin/agencies?for_contract=1&per_page=100')->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue($rows->has($free->id));
        $this->assertFalse($rows->has($otherChannel->id));
        $this->assertSame([], $rows[$free->id]['airlines']);

        $this->putJson("/api/v1/admin/airlines/{$this->airline->id}/agencies/{$free->id}", ['is_active' => true])->assertOk();
        $this->getJson("/api/v1/admin/agencies/{$free->id}")->assertOk()
            ->assertJsonCount(1, 'data.airlines')->assertJsonPath('data.airlines.0.id', $this->airline->id);
    }

    public function test_bag_tag_direction_is_saved_per_airline(): void
    {
        $this->actingAsRole('airline-admin');
        $url = "/api/v1/admin/airlines/{$this->airline->id}";
        $this->getJson($url)->assertOk()->assertJsonPath('data.bag_tag_rotation', 0);
        $this->putJson($url, ['bag_tag_rotation' => 90])->assertStatus(422)->assertJsonValidationErrors('bag_tag_rotation');
        $this->putJson($url, ['bag_tag_rotation' => 180])->assertOk()->assertJsonPath('data.bag_tag_rotation', 180);
    }
}
