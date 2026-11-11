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

    public function test_agents_permission_lists_only_the_airlines_own_and_contracted_agencies(): void
    {
        $other = Airline::create(['name' => 'Other Air', 'iata_code' => 'OT', 'numeric_code' => '456']);
        // created by the system administrator, contracted with the other airline only
        $shared = Agency::create(['name' => 'Created by super admin', 'code' => 'FREE', 'channel' => 'agency']);
        $shared->airlines()->attach($other->id, ['is_active' => true]);
        $otherAgency = Agency::create(['name' => 'Other travel', 'code' => 'OTHR', 'channel' => 'agency', 'owner_airline_id' => $other->id]);
        $own = Agency::create(['name' => 'Our travel', 'code' => 'OURS', 'channel' => 'agency', 'owner_airline_id' => $this->airline->id]);

        $this->actingAsRole('airline-admin');
        $ids = fn () => collect($this->getJson('/api/v1/admin/agencies?for_contract=1&per_page=100')->assertOk()->json('data'))->pluck('id');
        $this->assertContains($own->id, $ids());
        $this->assertContains($this->agency->id, $ids());          // shared, already contracted with us
        $this->assertNotContains($shared->id, $ids());
        $this->assertNotContains($otherAgency->id, $ids());
        $this->putJson("/api/v1/admin/airlines/{$this->airline->id}/agencies/{$otherAgency->id}", ['is_active' => true])->assertForbidden();

        // once the system administrator gives it a contract with us, it is ours to manage too
        $this->actingAsRole('system-admin');
        $this->putJson("/api/v1/admin/airlines/{$this->airline->id}/agencies/{$shared->id}", ['is_active' => true])->assertOk();
        $this->actingAsRole('airline-admin');
        $this->assertContains($shared->id, $ids());
        $this->getJson("/api/v1/admin/agencies/{$shared->id}")->assertOk()
            ->assertJsonCount(1, 'data.airlines')->assertJsonPath('data.airlines.0.id', $this->airline->id);
    }

    public function test_an_airline_edits_and_deletes_only_the_agencies_it_created(): void
    {
        $this->actingAsRole('airline-admin');
        $id = $this->postJson('/api/v1/admin/agencies', ['name' => 'Our travel', 'code' => 'OURS', 'channel' => 'agency'])
            ->assertCreated()->assertJsonPath('data.owner_airline_id', $this->airline->id)->json('data.id');

        $this->putJson("/api/v1/admin/agencies/{$id}", ['name' => 'Our travel LLC'])->assertOk()->assertJsonPath('data.name', 'Our travel LLC');
        // a shared agency (created by the system administrator) is only contracted with us
        $this->putJson("/api/v1/admin/agencies/{$this->agency->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->deleteJson("/api/v1/admin/agencies/{$this->agency->id}")->assertForbidden();
        $this->assertSame('Test Travel', $this->agency->fresh()->name);

        $this->deleteJson("/api/v1/admin/agencies/{$id}")->assertOk();
        $this->assertSoftDeleted('agencies', ['id' => $id]);
    }

    public function test_a_shared_agency_is_contracted_with_every_airline_and_a_later_one_is_linked_by_hand(): void
    {
        $other = Airline::create(['name' => 'Other Air', 'iata_code' => 'OT', 'numeric_code' => '456']);
        $this->actingAsRole('system-admin');
        $id = $this->postJson('/api/v1/admin/agencies', ['name' => 'Everyone travel', 'code' => 'ALL', 'channel' => 'agency', 'all_airlines' => true])
            ->assertCreated()->assertJsonPath('data.owner_airline_id', null)->json('data.id');
        $agency = Agency::query()->findOrFail($id);
        $this->assertEqualsCanonicalizing([$this->airline->id, $other->id], $agency->airlines()->pluck('airlines.id')->all());

        // an airline added later: linked by hand, the other contracts stay
        $later = Airline::create(['name' => 'Later Air', 'iata_code' => 'LA', 'numeric_code' => '789']);
        $this->assertFalse($agency->airlines()->whereKey($later->id)->exists());
        $this->putJson("/api/v1/admin/airlines/{$later->id}/agencies/{$id}", ['is_active' => true])->assertOk();
        $this->assertEqualsCanonicalizing([$this->airline->id, $other->id, $later->id], $agency->airlines()->pluck('airlines.id')->all());

        // each airline sees it on Agents permission, and only the system administrator edits it
        $this->actingAsRole('airline-admin', ['airline_id' => $other->id]);
        $this->assertContains($id, collect($this->getJson('/api/v1/admin/agencies?for_contract=1&per_page=100')->json('data'))->pluck('id'));
        $this->putJson("/api/v1/admin/agencies/{$id}", ['name' => 'Mine now'])->assertForbidden();
    }

    public function test_a_system_administrator_creates_an_agency_for_one_airline(): void
    {
        $this->actingAsRole('system-admin');
        $id = $this->postJson('/api/v1/admin/agencies', [
            'name' => 'For Test Air', 'code' => 'FTA', 'channel' => 'agency', 'owner_airline_id' => $this->airline->id,
        ])->assertCreated()->assertJsonPath('data.owner_airline_id', $this->airline->id)->json('data.id');
        $this->assertTrue(Agency::query()->findOrFail($id)->airlines()->whereKey($this->airline->id)->exists());

        $this->actingAsRole('airline-admin');
        $this->putJson("/api/v1/admin/agencies/{$id}", ['phone' => '+976 9999 0000'])->assertOk();
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
