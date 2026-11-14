<?php

namespace Tests\Feature\Agency;

use App\Models\Booking;
use App\Models\Passenger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

class AddToBookingTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function infant(): array
    {
        return ['first_name' => 'Baby', 'last_name' => 'Dorj', 'gender' => 'M', 'date_of_birth' => now()->subYear()->toDateString(),
            'document_number' => 'E'.random_int(1000000, 9999999), 'document_expires_at' => now()->addYears(4)->toDateString()];
    }

    public function test_passengers_are_added_to_a_pnr_within_its_quota_and_the_infant_limit(): void
    {
        $this->aircraft->update(['max_infants' => 1]);
        $allocation = $this->makeAllocation($this->makeFlight(null, ['service_type' => 'charter']), 2, ['Y' => 2]);
        $this->actingAsRole('agency-agent');
        $this->postJson('/api/v1/agency/bookings', ['allocation_id' => $allocation->id, 'passengers' => [
            $this->passengerPayload(['first_name' => 'Bold', 'infant' => $this->infant()]),
        ]])->assertCreated();
        $booking = Booking::query()->sole();
        $this->getJson('/api/v1/agency/allocations')->assertOk()->assertJsonPath('data.0.usage.infants_left', 0);

        // the aircraft takes no more infants
        $this->postJson("/api/v1/agency/bookings/{$booking->id}/passengers", ['passengers' => [
            $this->passengerPayload(['first_name' => 'Saraa', 'infant' => $this->infant()]),
        ]])->assertStatus(422)->assertJsonPath('code', 'infants_full');

        $this->postJson("/api/v1/agency/bookings/{$booking->id}/passengers", ['passengers' => [
            $this->passengerPayload(['first_name' => 'Saraa']),
        ]])->assertOk();
        $saraa = Passenger::query()->where('first_name', 'SARAA')->sole();
        $this->assertSame($booking->id, $saraa->booking_id);
        $this->assertSame(13, strlen($saraa->segments->first()->ticket_number));

        // the quota (2 seats) is full now
        $this->postJson("/api/v1/agency/bookings/{$booking->id}/passengers", ['passengers' => [
            $this->passengerPayload(['first_name' => 'Tuya']),
        ]])->assertStatus(422);
        $this->assertSame(3, Passenger::query()->count());   // Bold, his infant, Saraa
    }
}
