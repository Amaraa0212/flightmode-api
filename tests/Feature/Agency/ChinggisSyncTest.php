<?php

namespace Tests\Feature\Agency;

use App\Models\AgencyAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

/** Chinggis agent API: switched on per agency, every allocation keeps the result of its last push. */
class ChinggisSyncTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        config(['services.chinggis.base_url' => 'https://chinggis.test', 'services.chinggis.key' => 'secret', 'services.chinggis.agency_ids' => []]);
        $this->actingAsRole('airline-admin');
    }

    public function test_an_agency_switched_on_has_its_quota_pushed_and_the_result_kept(): void
    {
        Http::fake(['chinggis.test/*' => Http::response(['ok' => true])]);
        $allocation = $this->makeAllocation($this->makeFlight());
        Http::assertNothingSent();   // the agency is not synced yet

        $this->postJson("/api/v1/admin/allocations/{$allocation->id}/chinggis-push")
            ->assertStatus(422)->assertJsonPath('code', 'agency_not_synced');

        $this->putJson("/api/v1/admin/agencies/{$this->agency->id}", ['chinggis_sync' => true])->assertOk()
            ->assertJsonPath('data.chinggis_sync', true);
        $this->postJson("/api/v1/admin/allocations/{$allocation->id}/chinggis-push")->assertOk()
            ->assertJsonPath('data.chinggis.error', null)
            ->assertJsonPath('data.chinggis.synced_at', fn ($at) => $at !== null);

        Http::assertSent(fn ($request) => $request->url() === 'https://chinggis.test/api/agent/schedules'
            && $request['schedules'][0]['availability']['pax']['quota'] === 10);
    }

    public function test_a_failed_push_is_kept_on_the_allocation(): void
    {
        Http::fake(['chinggis.test/*' => Http::response('down', 503)]);
        $this->agency->update(['chinggis_sync' => true]);
        $allocation = $this->makeAllocation($this->makeFlight());

        $this->assertSame('Chinggis API refused it (HTTP 503).', AgencyAllocation::find($allocation->id)->chinggis_error);
        $this->postJson("/api/v1/admin/allocations/{$allocation->id}/chinggis-push")
            ->assertStatus(422)->assertJsonPath('code', 'chinggis_push_failed');
    }
}
