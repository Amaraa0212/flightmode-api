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

    public function test_a_sign_in_ends_after_an_idle_hour_and_after_twelve_hours(): void
    {
        $this->makeUser('airline-staff', ['email' => 'staff@test.mn', 'password' => 'secret123']);
        $login = fn () => $this->postJson('/api/v1/auth/login', ['email' => 'staff@test.mn', 'password' => 'secret123'])->json('token');
        $me = function (string $token) {
            $this->app['auth']->forgetGuards();

            return $this->withToken($token)->getJson('/api/v1/auth/me');
        };

        // used every 50 minutes: still signed in; then idle for over an hour: signed out
        $token = $login();
        $this->travel(50)->minutes();
        $me($token)->assertOk();
        $this->travel(50)->minutes();
        $me($token)->assertOk();
        $this->travel(61)->minutes();
        $me($token)->assertUnauthorized();

        // busy all day: signed out 12 hours after signing in anyway
        $token = $login();
        for ($i = 0; $i < 24; $i++) {
            $this->travel(30)->minutes();
            $response = $me($token);
        }
        $response->assertUnauthorized();
    }

    public function test_partner_tokens_do_not_expire(): void
    {
        $user = $this->makeUser('airline-staff');
        $token = $user->createToken(\App\Models\User::PARTNER_TOKEN_NAME, [\App\Models\User::PARTNER_TOKEN_ABILITY])->accessToken;
        $this->travel(30)->days();

        $this->assertTrue(\Laravel\Sanctum\Sanctum::$accessTokenAuthenticationCallback->__invoke($token, true));
    }
}
