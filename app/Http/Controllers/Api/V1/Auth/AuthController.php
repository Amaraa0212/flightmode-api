<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\ActivityChannel;
use App\Enums\PermissionKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Http\Requests\Auth\TwoFactorCodeRequest;
use App\Http\Requests\Auth\TwoFactorDisableRequest;
use App\Http\Requests\Auth\TwoFactorSetupRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Access\TwoFactor;
use App\Services\ActivityLogger;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    private const CHALLENGE_MINUTES = 5;

    public function __construct(
        private readonly TwoFactor $twoFactor,
        private readonly ActivityLogger $logger,
    ) {}

    /** Issue a Bearer token for the back office, agency portal, DCS and gate app. */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()->with('role')->where('email', $request->string('email'))->first();

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            if ($user) {
                $this->audit($user, 'auth.login_failed', 'Wrong password');
            }
            throw ValidationException::withMessages(['email' => 'The e-mail or password is incorrect.']);
        }

        if (! $user->is_active) {
            $this->audit($user, 'auth.login_blocked', 'Disabled account');

            return response()->json(['message' => 'Your account is disabled.', 'code' => 'account_disabled'], 403);
        }

        // Google Authenticator on: no token yet, the client asks for the 6-digit code
        if ($user->hasTwoFactor()) {
            return response()->json([
                'two_factor_required' => true,
                'challenge' => Crypt::encryptString(json_encode([
                    'user_id' => $user->id,
                    'device_name' => $request->input('device_name', 'web'),
                    'expires_at' => now()->addMinutes(self::CHALLENGE_MINUTES)->timestamp,
                ])),
            ]);
        }

        return $this->issueToken($user, $request->input('device_name', 'web'));
    }

    /** Second step of a login with Google Authenticator: challenge (from login) + 6-digit code → token */
    public function twoFactorChallenge(TwoFactorChallengeRequest $request): JsonResponse
    {
        try {
            $challenge = json_decode(Crypt::decryptString((string) $request->string('challenge')), true);
        } catch (DecryptException) {
            $challenge = null;
        }

        if (! is_array($challenge) || ($challenge['expires_at'] ?? 0) < now()->timestamp) {
            return response()->json(['message' => 'The sign-in has expired. Please sign in again.', 'code' => 'challenge_expired'], 422);
        }

        $user = User::query()->with('role')->find($challenge['user_id'] ?? null);
        if (! $user || ! $user->hasTwoFactor()) {
            throw ValidationException::withMessages(['code' => 'The authenticator code is incorrect.']);
        }
        $this->checkCode($user, (string) $request->string('code'));

        return $this->issueToken($user, (string) ($challenge['device_name'] ?? 'web'));
    }

    /**
     * Starts Google Authenticator setup: a new secret to scan (not active until confirmed).
     * Needs the password, so a stolen session cannot put the attacker's phone on the account.
     */
    public function setupTwoFactor(TwoFactorSetupRequest $request): JsonResponse
    {
        $user = $request->user();
        if ($user->hasTwoFactor()) {
            throw ValidationException::withMessages(['code' => 'Google Authenticator is already enabled.']);
        }
        $this->checkPassword($user, (string) $request->string('password'));

        $secret = $this->twoFactor->generateSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null, 'two_factor_last_timestep' => null])->save();

        return response()->json(['data' => ['secret' => $secret, 'otpauth_url' => $this->twoFactor->otpauthUrl($user, $secret)]]);
    }

    public function confirmTwoFactor(TwoFactorCodeRequest $request): JsonResponse
    {
        $user = $request->user();
        if ($user->hasTwoFactor()) {
            throw ValidationException::withMessages(['code' => 'Google Authenticator is already enabled.']);
        }
        if (! $user->two_factor_secret) {
            throw ValidationException::withMessages(['code' => 'Start the Google Authenticator setup first.']);
        }
        $this->checkCode($user, (string) $request->string('code'));

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->signOutOtherSessions($user, $request);
        $this->audit($user, 'auth.two_factor_enabled', 'Google Authenticator enabled');

        return response()->json(['message' => 'Google Authenticator enabled.', 'data' => $this->profile($user)]);
    }

    /** Turning it off needs the password and a current code, so a stolen session cannot remove it */
    public function disableTwoFactor(TwoFactorDisableRequest $request): JsonResponse
    {
        $user = $request->user();
        $this->checkPassword($user, (string) $request->string('password'));
        if ($user->hasTwoFactor()) {
            $this->checkCode($user, (string) $request->string('code'));
        }

        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_timestep' => null])->save();
        $this->signOutOtherSessions($user, $request);
        $this->audit($user, 'auth.two_factor_disabled', 'Google Authenticator disabled');

        return response()->json(['message' => 'Google Authenticator disabled.', 'data' => $this->profile($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->profile($request->user())]);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! Hash::check($request->string('current_password'), $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
        }

        $user->update(['password' => $request->string('password')]);
        $user->tokens()->where('id', '!=', $user->currentAccessToken()?->id)->delete();
        $this->audit($user, 'auth.password_changed', 'Password changed');

        return response()->json(['message' => 'Password changed.']);
    }

    private function issueToken(User $user, string $deviceName): JsonResponse
    {
        if (! $user->is_active) {
            return response()->json(['message' => 'Your account is disabled.', 'code' => 'account_disabled'], 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken($deviceName, ['*'])->plainTextToken;
        $this->audit($user, 'auth.login', 'Signed in ('.$deviceName.')', ['device_name' => $deviceName, 'two_factor' => $user->hasTwoFactor()]);

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->profile($user),
        ]);
    }

    private function checkPassword(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => 'The password is incorrect.']);
        }
    }

    /** A wrong code counts towards the per-user lock (5 wrong codes → 15 minutes) */
    private function checkCode(User $user, string $code): void
    {
        if ($seconds = $this->twoFactor->lockedFor($user)) {
            abort(response()->json([
                'message' => 'Too many wrong codes. Try again in '.ceil($seconds / 60).' minute(s).',
                'code' => 'two_factor_locked',
                'retry_after' => $seconds,
            ], 429));
        }

        if (! $this->twoFactor->attempt($user, $code)) {
            $locked = $this->twoFactor->lockedFor($user) > 0;
            $this->audit($user, $locked ? 'auth.two_factor_locked' : 'auth.two_factor_failed', $locked
                ? 'Google Authenticator locked after '.TwoFactor::MAX_ATTEMPTS.' wrong codes'
                : 'Wrong Google Authenticator code');

            throw ValidationException::withMessages(['code' => 'The authenticator code is incorrect.']);
        }
    }

    /** A session that may have been stolen does not survive a change of the second factor */
    private function signOutOtherSessions(User $user, Request $request): void
    {
        $current = $request->user()->currentAccessToken();
        $user->revokeSessions($current instanceof PersonalAccessToken ? $current->id : null);
    }

    /** @param  array<string, mixed>  $properties */
    private function audit(User $user, string $event, string $description, array $properties = []): void
    {
        $this->logger->log(ActivityChannel::Auth, $event, $description, $user, properties: $properties, userId: $user->id);
    }

    /** @return array<string, mixed> */
    private function profile(User $user): array
    {
        $user->loadMissing(['role.permissions', 'airline', 'agency', 'airport']);

        return (new UserResource($user))->resolve() + [
            'permissions' => $user->isSystemAdmin()
                ? array_map(fn ($p) => $p->value, PermissionKey::cases())
                : $user->permissionKeys(),
            'is_system_admin' => $user->isSystemAdmin(),
            'two_factor_enabled' => $user->hasTwoFactor(),
        ];
    }
}
