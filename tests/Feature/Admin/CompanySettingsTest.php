<?php

namespace Tests\Feature\Admin;

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
}
