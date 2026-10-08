<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    public function test_login_returns_token_and_permissions(): void
    {
        $user = $this->makeUser('airline-staff', ['email' => 'staff@test.mn', 'password' => 'secret123']);

        $response = $this->postJson('/api/v1/auth/login', ['email' => 'staff@test.mn', 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'email', 'role', 'permissions']]);

        $this->assertContains('flights.view', $response->json('user.permissions'));

        $this->withToken($response->json('token'))->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->makeUser('airline-staff', ['email' => 'staff@test.mn', 'password' => 'secret123']);

        $this->postJson('/api/v1/auth/login', ['email' => 'staff@test.mn', 'password' => 'nope'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_disabled_user_cannot_login(): void
    {
        $this->makeUser('airline-staff', ['email' => 'off@test.mn', 'password' => 'secret123', 'is_active' => false]);

        $this->postJson('/api/v1/auth/login', ['email' => 'off@test.mn', 'password' => 'secret123'])->assertForbidden();
    }

    public function test_permission_is_enforced(): void
    {
        $this->actingAsRole('gate-agent');

        $this->getJson('/api/v1/admin/flights')->assertForbidden();
        $this->getJson('/api/v1/gate/flights')->assertOk();
    }

    public function test_unauthenticated_request_gets_401_json(): void
    {
        $this->getJson('/api/v1/admin/flights')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    }
}
