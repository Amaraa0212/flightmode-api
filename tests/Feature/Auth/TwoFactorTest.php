<?php

namespace Tests\Feature\Auth;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Access\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\BuildsFlightData;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use BuildsFlightData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
    }

    private function code(string $secret, int $offsetSeconds = 0): string
    {
        return (new Google2FA)->oathTotp($secret, (new Google2FA)->getTimestamp() + intdiv($offsetSeconds, 30));
    }

    private function userWithTwoFactor(array $attributes = []): array
    {
        $secret = (new Google2FA)->generateSecretKey(32);
        $user = $this->makeUser('airline-staff', $attributes + ['email' => 'staff@test.mn', 'password' => 'secret123']);
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

        return [$user, $secret];
    }

    public function test_login_without_two_factor_returns_token_and_flag(): void
    {
        $this->makeUser('airline-staff', ['email' => 'staff@test.mn', 'password' => 'secret123']);

        $this->postJson('/api/v1/auth/login', ['email' => 'staff@test.mn', 'password' => 'secret123'])
            ->assertOk()->assertJsonStructure(['token'])->assertJsonPath('user.two_factor_enabled', false);
    }

    public function test_user_enables_google_authenticator(): void
    {
        $user = $this->makeUser('airline-staff', ['email' => 'staff@test.mn', 'password' => 'secret123']);

        // the password is asked again: a stolen session cannot add the attacker's phone
        $this->actingAs($user)->postJson('/api/v1/auth/two-factor/setup', ['password' => 'wrong'])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $setup = $this->actingAs($user)->postJson('/api/v1/auth/two-factor/setup', ['password' => 'secret123'])->assertOk();
        $secret = $setup->json('data.secret');
        $this->assertStringStartsWith('otpauth://totp/', $setup->json('data.otpauth_url'));
        $this->assertStringContainsString('secret='.$secret, $setup->json('data.otpauth_url'));

        // not active until a code is confirmed
        $this->assertFalse($user->fresh()->hasTwoFactor());

        $this->actingAs($user)->postJson('/api/v1/auth/two-factor/confirm', ['code' => '000000'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $this->actingAs($user)->postJson('/api/v1/auth/two-factor/confirm', ['code' => $this->code($secret)])
            ->assertOk()->assertJsonPath('data.two_factor_enabled', true);

        $this->assertTrue($user->fresh()->hasTwoFactor());
        $this->assertNotSame($secret, $user->fresh()->getRawOriginal('two_factor_secret'), 'secret is stored encrypted');
    }

    public function test_login_with_two_factor_needs_the_code(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();

        $login = $this->postJson('/api/v1/auth/login', ['email' => 'staff@test.mn', 'password' => 'secret123', 'device_name' => 'dcs'])
            ->assertOk()->assertJsonPath('two_factor_required', true)->assertJsonMissingPath('token');

        $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => $login->json('challenge'), 'code' => '123456'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $response = $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => $login->json('challenge'), 'code' => $this->code($secret)])
            ->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonPath('user.two_factor_enabled', true);

        $this->assertSame('dcs', $user->tokens()->first()->name);
        $this->withToken($response->json('token'))->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        [, $secret] = $this->userWithTwoFactor();
        $code = $this->code($secret);

        $challenge = fn () => $this->postJson('/api/v1/auth/login', ['email' => 'staff@test.mn', 'password' => 'secret123'])->json('challenge');

        $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => $challenge(), 'code' => $code])->assertOk();
        $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => $challenge(), 'code' => $code])
            ->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_expired_or_forged_challenge_is_rejected(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();

        $expired = Crypt::encryptString(json_encode(['user_id' => $user->id, 'expires_at' => now()->subMinute()->timestamp]));
        $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => $expired, 'code' => $this->code($secret)])
            ->assertStatus(422)->assertJsonPath('code', 'challenge_expired');

        $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => 'not-encrypted', 'code' => $this->code($secret)])
            ->assertStatus(422)->assertJsonPath('code', 'challenge_expired');
    }

    public function test_disabled_user_gets_no_challenge(): void
    {
        $this->userWithTwoFactor(['is_active' => false]);

        $this->postJson('/api/v1/auth/login', ['email' => 'staff@test.mn', 'password' => 'secret123'])->assertForbidden();
    }

    public function test_disabling_needs_a_current_code(): void
    {
        /** @var User $user */
        [$user, $secret] = $this->userWithTwoFactor();

        $this->actingAs($user)->deleteJson('/api/v1/auth/two-factor', ['password' => 'secret123', 'code' => '000000'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $this->actingAs($user)->deleteJson('/api/v1/auth/two-factor', ['password' => 'wrong', 'code' => $this->code($secret)])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->actingAs($user)->deleteJson('/api/v1/auth/two-factor', ['password' => 'secret123', 'code' => $this->code($secret)])
            ->assertOk()->assertJsonPath('data.two_factor_enabled', false);
        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_setup_is_refused_when_already_enabled(): void
    {
        [$user] = $this->userWithTwoFactor();

        $this->actingAs($user)->postJson('/api/v1/auth/two-factor/setup', ['password' => 'secret123'])->assertStatus(422);
    }

    public function test_system_admin_resets_a_users_google_authenticator(): void
    {
        [$user] = $this->userWithTwoFactor();
        $this->actingAsRole('system-admin');

        $this->getJson('/api/v1/admin/users/'.$user->id)->assertOk()->assertJsonPath('data.two_factor_enabled', true);

        $this->deleteJson('/api/v1/admin/users/'.$user->id.'/two-factor')
            ->assertOk()->assertJsonPath('data.two_factor_enabled', false);

        $this->assertFalse($user->fresh()->hasTwoFactor());
        $this->assertNull($user->fresh()->two_factor_secret);

        // the user signs in with the password alone again
        $this->postJson('/api/v1/auth/login', ['email' => 'staff@test.mn', 'password' => 'secret123'])
            ->assertOk()->assertJsonStructure(['token']);
    }

    public function test_only_a_system_admin_can_reset_google_authenticator(): void
    {
        [$user] = $this->userWithTwoFactor();
        $this->actingAsRole('airline-admin');

        $this->deleteJson('/api/v1/admin/users/'.$user->id.'/two-factor')->assertForbidden();
        $this->assertTrue($user->fresh()->hasTwoFactor());
    }

    public function test_wrong_codes_lock_the_user_across_challenges(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();
        // a fresh challenge each time (as from another login / IP): the count is kept per user
        $challenge = fn () => Crypt::encryptString(json_encode(['user_id' => $user->id, 'expires_at' => now()->addMinutes(5)->timestamp]));

        for ($i = 0; $i < TwoFactor::MAX_ATTEMPTS; $i++) {
            $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => $challenge(), 'code' => '000000'])->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => $challenge(), 'code' => $this->code($secret)])
            ->assertStatus(429)->assertJsonPath('code', 'two_factor_locked');
        $this->assertSame(0, $user->tokens()->count());
        $this->assertTrue(ActivityLog::query()->where('event', 'auth.two_factor_locked')->where('user_id', $user->id)->exists());

        // an administrator reset unlocks it
        $this->actingAsRole('system-admin');
        $this->deleteJson('/api/v1/admin/users/'.$user->id.'/two-factor')->assertOk();
        $this->assertSame(0, app(TwoFactor::class)->lockedFor($user));
    }

    public function test_admin_reset_signs_the_user_out_but_keeps_the_agency_api_token(): void
    {
        [$user] = $this->userWithTwoFactor();
        $user->createToken('dcs');
        $user->createToken(User::PARTNER_TOKEN_NAME, [User::PARTNER_TOKEN_ABILITY]);
        $admin = $this->actingAsRole('system-admin');

        $this->deleteJson('/api/v1/admin/users/'.$user->id.'/two-factor')->assertOk();

        $this->assertSame([User::PARTNER_TOKEN_NAME], $user->tokens()->pluck('name')->all());
        $log = ActivityLog::query()->where('event', 'auth.two_factor_reset')->firstOrFail();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame($user->id, $log->subject_id);
    }

    public function test_password_set_by_an_admin_signs_the_user_out(): void
    {
        $user = $this->makeUser('airline-staff', ['email' => 'staff@test.mn', 'password' => 'secret123']);
        $user->createToken('admin');
        $this->actingAsRole('system-admin');

        $this->putJson('/api/v1/admin/users/'.$user->id, [
            'name' => $user->name, 'email' => $user->email, 'role_id' => $user->role_id,
            'airline_id' => $user->airline_id, 'password' => 'NewSecret123', 'is_active' => true,
        ])->assertOk();

        $this->assertSame(0, $user->tokens()->count());
        $this->assertTrue(ActivityLog::query()->where('event', 'auth.password_reset')->where('subject_id', $user->id)->exists());
    }

    public function test_enabling_two_factor_signs_out_other_sessions(): void
    {
        $user = $this->makeUser('airline-staff', ['email' => 'staff@test.mn', 'password' => 'secret123']);
        $user->createToken('other-device');
        $current = $user->createToken('this-device')->plainTextToken;

        $secret = $this->withToken($current)->postJson('/api/v1/auth/two-factor/setup', ['password' => 'secret123'])->json('data.secret');
        $this->withToken($current)->postJson('/api/v1/auth/two-factor/confirm', ['code' => $this->code($secret)])->assertOk();

        $this->assertSame(['this-device'], $user->tokens()->pluck('name')->all());
    }

    public function test_sign_in_events_are_logged(): void
    {
        [$user, $secret] = $this->userWithTwoFactor();

        $this->postJson('/api/v1/auth/login', ['email' => 'staff@test.mn', 'password' => 'nope'])->assertStatus(422);
        $challenge = $this->postJson('/api/v1/auth/login', ['email' => 'staff@test.mn', 'password' => 'secret123'])->json('challenge');
        $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => $challenge, 'code' => '000000'])->assertStatus(422);
        $this->postJson('/api/v1/auth/two-factor-challenge', ['challenge' => $challenge, 'code' => $this->code($secret)])->assertOk();

        $events = ActivityLog::query()->where('user_id', $user->id)->where('channel', 'auth')->orderBy('id')->pluck('event')->all();
        $this->assertSame(['auth.login_failed', 'auth.two_factor_failed', 'auth.login'], $events);
    }
}
