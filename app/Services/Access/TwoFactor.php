<?php

namespace App\Services\Access;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;

/** Google Authenticator (TOTP, 6 digits / 30 s) for staff logins */
class TwoFactor
{
    /** Wrong codes in a row (any IP) before the user's code entry is locked */
    public const MAX_ATTEMPTS = 5;

    public const LOCK_SECONDS = 15 * 60;

    public function __construct(private readonly Google2FA $google2fa) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    /** otpauth:// URL for the authenticator app QR code */
    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl((string) config('app.name'), $user->email, $secret);
    }

    /** Seconds until the user may try a code again; 0 when not locked */
    public function lockedFor(User $user): int
    {
        return RateLimiter::tooManyAttempts($this->limiterKey($user), self::MAX_ATTEMPTS)
            ? RateLimiter::availableIn($this->limiterKey($user))
            : 0;
    }

    /** verify() that counts wrong codes per user, so guessing from many IPs is locked too */
    public function attempt(User $user, string $code): bool
    {
        if (! $this->verify($user, $code)) {
            RateLimiter::hit($this->limiterKey($user), self::LOCK_SECONDS);

            return false;
        }

        RateLimiter::clear($this->limiterKey($user));

        return true;
    }

    /** Unlocks code entry (after an administrator reset) */
    public function clearLock(User $user): void
    {
        RateLimiter::clear($this->limiterKey($user));
    }

    private function limiterKey(User $user): string
    {
        return 'two-factor:'.$user->id;
    }

    /** Accepts a code once (±30 s drift); a used code cannot be replayed. */
    public function verify(User $user, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (! $user->two_factor_secret || ! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $timestep = $this->google2fa->verifyKeyNewer($user->two_factor_secret, $code, $user->two_factor_last_timestep ?? 0);
        if ($timestep === false) {
            return false;
        }

        $user->forceFill(['two_factor_last_timestep' => $timestep])->save();

        return true;
    }
}
