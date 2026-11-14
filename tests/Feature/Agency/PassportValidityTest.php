<?php

namespace Tests\Feature\Agency;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

class PassportValidityTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_an_agency_needs_a_passport_valid_six_months_after_an_international_departure(): void
    {
        $flight = $this->makeFlight();   // UBN → PQC
        $allocation = $this->makeAllocation($flight, 5, ['Y' => 5]);
        $this->actingAsRole('agency-agent');
        $book = fn (array $pax) => $this->postJson('/api/v1/agency/bookings', ['allocation_id' => $allocation->id, 'passengers' => [$pax]]);

        $book($this->passengerPayload(['document_expires_at' => null]))
            ->assertStatus(422)->assertJsonPath('code', 'passport_expiry_required');
        $book($this->passengerPayload(['document_expires_at' => $flight->scheduled_departure_at->copy()->addMonths(3)->toDateString()]))
            ->assertStatus(422)->assertJsonPath('code', 'passport_validity');
        $id = $book($this->passengerPayload(['document_expires_at' => $flight->scheduled_departure_at->copy()->addMonths(7)->toDateString()]))
            ->assertCreated()->json('data.passengers.0.id');

        // editing it to a passport that runs out too soon is refused as well
        $this->putJson("/api/v1/agency/passengers/{$id}", ['document_expires_at' => $flight->scheduled_departure_at->copy()->addMonth()->toDateString()])
            ->assertStatus(422)->assertJsonPath('code', 'passport_validity');
    }
}
