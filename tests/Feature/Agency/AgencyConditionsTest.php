<?php

namespace Tests\Feature\Agency;

use App\Models\Passenger;
use App\Services\Documents\ETicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

class AgencyConditionsTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_the_agency_administrator_edits_and_agents_only_read_the_conditions(): void
    {
        $this->actingAsRole('agency-admin');
        $this->getJson('/api/v1/agency/conditions')->assertOk()
            ->assertJsonPath('data.conditions_of_carriage', null)->assertJsonPath('data.can_update', true);
        $this->putJson('/api/v1/agency/conditions', ['conditions_of_carriage' => "# Khaan Travel\n\nRefunds within **24 hours**."])->assertOk();
        $this->assertStringContainsString('Refunds', $this->agency->fresh()->conditions_of_carriage);

        $this->actingAsRole('agency-agent');
        $this->getJson('/api/v1/agency/conditions')->assertOk()->assertJsonPath('data.can_update', false);
        $this->putJson('/api/v1/agency/conditions', ['conditions_of_carriage' => 'mine'])->assertForbidden();
    }

    public function test_the_eticket_prints_the_agencys_conditions_with_the_agency_header_and_the_airlines_otherwise(): void
    {
        $this->airline->update(['conditions_of_carriage' => "## Airline rules\n\nBags up to 20 kg."]);
        $allocation = $this->makeAllocation($this->makeFlight(), 5, ['Y' => 5]);
        $this->actingAsRole('agency-agent');
        $this->postJson('/api/v1/agency/bookings', ['allocation_id' => $allocation->id, 'passengers' => [$this->passengerPayload()]])->assertCreated();
        $passenger = Passenger::query()->firstOrFail();
        $service = app(ETicketService::class);
        $html = fn (?string $header) => view('documents.eticket', $service->data($passenger->fresh(), 'en', true, true, $header))->render();

        // no conditions of its own yet: the airline's, from Markdown
        $this->assertStringContainsString('<h2>Airline rules</h2>', $html(null));
        $this->assertStringNotContainsString('## Airline', $html(null));

        $this->agency->update(['conditions_of_carriage' => "# Khaan Travel\n\nRefunds within **24 hours**.<script>x</script>"]);
        $agencyHeader = $html(null);
        $this->assertStringContainsString('<strong>24 hours</strong>', $agencyHeader);
        $this->assertStringNotContainsString('Airline rules', $agencyHeader);
        $this->assertStringNotContainsString('<script>', $agencyHeader);
        $this->assertStringContainsString('Airline rules', $html('airline'));
    }
}
