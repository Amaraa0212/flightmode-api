<?php

namespace Tests\Feature\Agency;

use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

class PartnerApiTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function issueToken(): string
    {
        $agent = $this->makeUser('agency-agent');
        $this->actingAsRole('airline-admin');
        $token = $this->postJson("/api/v1/admin/users/{$agent->id}/api-token")->assertCreated()->json('data.token');
        $this->app['auth']->forgetGuards();

        return $token;
    }

    public function test_partner_token_books_through_the_partner_api(): void
    {
        $allocation = $this->makeAllocation($this->makeFlight());
        $headers = ['Authorization' => 'Bearer '.$this->issueToken()];

        $this->getJson('/api/v1/partner/allocations', $headers)->assertOk()->assertJsonPath('data.0.id', $allocation->id);

        $passengerId = $this->postJson('/api/v1/partner/bookings', [
            'allocation_id' => $allocation->id, 'passengers' => [$this->passengerPayload()],
        ], $headers)->assertCreated()->json('data.passengers.0.id');

        $this->assertSame('agency_api', Booking::query()->sole()->source->value);
        $this->get("/api/v1/partner/passengers/{$passengerId}/eticket", $headers)->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_partner_token_cannot_use_the_portal_and_disabled_agency_is_refused(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->issueToken()];

        // A partner token only has the "partner" ability: the web portal routes are not reachable with it
        $this->getJson('/api/v1/agency/allocations', $headers)->assertForbidden();

        $this->agency->update(['api_enabled' => false]);
        $this->getJson('/api/v1/partner/allocations', $headers)->assertForbidden()->assertJsonPath('code', 'partner_access_disabled');
    }

    public function test_web_token_cannot_use_the_partner_api(): void
    {
        $user = $this->makeUser('agency-agent');
        $token = $user->createToken('web')->plainTextToken;

        $this->getJson('/api/v1/partner/allocations', ['Authorization' => "Bearer {$token}"])->assertForbidden();
    }

    public function test_charter_request_from_agency_is_answered_by_airline(): void
    {
        $this->actingAsRole('agency-admin');
        $id = $this->postJson('/api/v1/agency/charter-requests', [
            'origin_airport_id' => $this->ubn->id, 'destination_airport_id' => $this->pqc->id,
            'departure_date' => now()->addMonth()->toDateString(), 'pax_count' => 180, 'airline_ids' => [$this->airline->id],
        ])->assertCreated()->json('data.id');

        $this->actingAsRole('airline-admin');
        $this->getJson('/api/v1/admin/charter-requests')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->patchJson("/api/v1/admin/charter-requests/{$id}/status", ['status' => 'approved'])
            ->assertOk()->assertJsonPath('data.status', 'approved');
    }
}
