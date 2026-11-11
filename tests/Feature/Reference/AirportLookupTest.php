<?php

namespace Tests\Feature\Reference;

use App\Models\Country;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

class AirportLookupTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->actingAsRole('airline-admin');
    }

    public function test_the_worldwide_list_is_loaded_and_found_by_code_with_our_country_id(): void
    {
        $mn = Country::query()->where('alpha2', 'MN')->value('id');

        $this->getJson('/api/v1/reference/iata-airports?code=ulg')->assertOk()
            ->assertJsonPath('data.0.name', 'Ulgii Airport')
            ->assertJsonPath('data.0.timezone', 'Asia/Hovd')
            ->assertJsonPath('data.0.country_id', $mn);

        $this->assertSame('UBN', $this->getJson('/api/v1/reference/iata-airports?search=chinggis')->json('data.0.code'));
    }

    public function test_cities_and_time_zones_follow_the_country(): void
    {
        $mn = Country::query()->where('alpha2', 'MN')->value('id');

        $cities = collect($this->getJson("/api/v1/reference/cities?country_id={$mn}")->assertOk()->json('data'));
        $this->assertSame(['city' => 'Ulgii', 'city_code' => 'ULG', 'timezone' => 'Asia/Hovd'], $cities->firstWhere('city', 'Ulgii'));
        $this->assertNull($cities->firstWhere('city', 'Seoul'));
        $this->assertSame('Ulaanbaatar', $this->getJson("/api/v1/reference/cities?country_id={$mn}&search=ulaan")->json('data.0.city'));

        $this->assertContains('Asia/Hovd', $this->getJson("/api/v1/reference/timezones?country_id={$mn}")->json('data'));
        $this->assertNotContains('Asia/Seoul', $this->getJson("/api/v1/reference/timezones?country_id={$mn}")->json('data'));
        $this->getJson('/api/v1/reference/cities')->assertUnprocessable();
    }
}
