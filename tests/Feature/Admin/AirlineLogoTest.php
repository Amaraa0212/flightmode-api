<?php

namespace Tests\Feature\Admin;

use App\Models\Airline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

/** An airline uploads its logo (e-ticket, boarding pass, bag tag); it is served from public/logos. */
class AirlineLogoTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    protected function tearDown(): void
    {
        foreach (glob(public_path('logos/airline-*')) ?: [] as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_an_airline_uploads_replaces_and_removes_its_logo(): void
    {
        $this->actingAsRole('airline-admin');
        $url = "/api/v1/admin/airlines/{$this->airline->id}/logo";

        $first = $this->post($url, ['logo' => UploadedFile::fake()->image('logo.png', 400, 120)], ['Accept' => 'application/json'])
            ->assertOk()->json('data');
        $this->assertStringStartsWith('logos/airline-'.$this->airline->id.'-', $first['logo_path']);
        $this->assertFileExists(public_path($first['logo_path']));
        $this->assertSame(asset($first['logo_path']), $first['logo_url']);

        // a new upload replaces the old file
        $second = $this->post($url, ['logo' => UploadedFile::fake()->image('logo.jpg', 400, 120)], ['Accept' => 'application/json'])
            ->assertOk()->json('data');
        $this->assertFileDoesNotExist(public_path($first['logo_path']));
        $this->assertFileExists(public_path($second['logo_path']));

        // not an image: refused
        $this->post($url, ['logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->deleteJson($url)->assertOk()->assertJsonPath('data.logo_url', null);
        $this->assertFileDoesNotExist(public_path($second['logo_path']));
        $this->assertNull($this->airline->fresh()->logo_path);
    }

    public function test_an_airline_cannot_change_another_airlines_logo(): void
    {
        $other = Airline::create(['name' => 'Other Air', 'iata_code' => 'OX', 'icao_code' => 'OXX', 'numeric_code' => '999', 'currency' => 'MNT']);
        $this->actingAsRole('airline-admin');

        $this->post("/api/v1/admin/airlines/{$other->id}/logo", ['logo' => UploadedFile::fake()->image('logo.png')], ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_a_plain_svg_logo_is_saved_and_an_unsafe_one_refused(): void
    {
        $this->actingAsRole('airline-admin');
        $url = "/api/v1/admin/airlines/{$this->airline->id}/logo";
        $svg = fn (string $body) => UploadedFile::fake()->createWithContent('logo.svg',
            '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 400 120">'.$body.'</svg>');
        $post = fn (UploadedFile $file) => $this->post($url, ['logo' => $file], ['Accept' => 'application/json']);

        // a design tool's export: shapes, text, internal <use href="#…">
        $path = $post($svg('<defs><path id="w" d="M0 0h40v40H0z"/></defs><use xlink:href="#w"/><text x="50" y="80">HUNNU</text>'))
            ->assertOk()->json('data.logo_path');
        $this->assertStringEndsWith('.svg', $path);
        $this->assertFileExists(public_path($path));

        foreach ([
            '<script>alert(1)</script>',
            '<rect width="10" height="10" onload="alert(1)"/>',
            '<a href="javascript:alert(1)"><rect width="10" height="10"/></a>',
            '<image href="https://example.com/x.png"/>',
            '<foreignObject><div>x</div></foreignObject>',
            '<style>@import url(https://example.com/x.css);</style>',
        ] as $unsafe) {
            $post($svg($unsafe))->assertStatus(422)->assertJsonValidationErrors('logo');
        }
        // the refused uploads did not replace the saved logo
        $this->assertSame($path, $this->airline->fresh()->logo_path);
    }
}
