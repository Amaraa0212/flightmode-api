<?php

namespace Tests\Feature\System;

use App\Models\Airline;
use App\Models\Announcement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

class AnnouncementAllAirlinesTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_a_system_administrator_sends_one_notification_to_every_airline(): void
    {
        $other = Airline::create(['name' => 'Other Air', 'iata_code' => 'OA', 'numeric_code' => '456']);
        $this->actingAsRole('system-admin');
        $id = $this->postJson('/api/v1/admin/announcements', [
            'all_airlines' => true, 'title' => 'Maintenance tonight', 'body' => 'The system is down 02:00–03:00.',
        ])->assertCreated()->json('data.id');
        $this->assertNull(Announcement::query()->find($id)->airline_id);

        // one agency only makes no sense for every airline
        $this->postJson('/api/v1/admin/announcements', [
            'all_airlines' => true, 'agency_id' => $this->agency->id, 'title' => 'x', 'body' => 'y',
        ])->assertUnprocessable()->assertJsonValidationErrors('agency_id');

        foreach ([['airline-admin', []], ['airline-admin', ['airline_id' => $other->id]], ['agency-agent', []]] as [$role, $attributes]) {
            $this->actingAsRole($role, $attributes);
            $this->assertContains($id, array_column($this->getJson('/api/v1/announcements')->assertOk()->json('data'), 'id'), $role);
        }
    }

    public function test_an_airline_administrator_cannot_address_other_airlines(): void
    {
        $this->actingAsRole('airline-admin');
        $id = $this->postJson('/api/v1/admin/announcements', ['all_airlines' => true, 'title' => 'Hi', 'body' => 'Ours only'])
            ->assertCreated()->json('data.id');

        $this->assertSame($this->airline->id, Announcement::query()->find($id)->airline_id);
    }
}
