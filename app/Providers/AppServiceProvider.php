<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Checkin\PassengerScreening;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Ban list is loaded once per request (DCS passenger list screens every row)
        $this->app->scoped(PassengerScreening::class);
    }

    public function boot(): void
    {
        // `can:<menu>.<action>` checks the user's role permissions (menus / permissions tables). System admins pass everything.
        Gate::before(function (User $user, string $ability) {
            if (! preg_match('/^[a-z_]+\.[a-z_]+$/', $ability)) {
                return null;
            }

            return $user->hasPermission($ability) ?: null;
        });

        $this->onlineCheckinLimits();
        $this->sessionTimeout();
    }

    /**
     * A sign-in ends after `flightmode.auth.idle_minutes` without a request, and `max_hours` after it was made
     * (also for tokens issued before tokens got an expiry). Partner API tokens are not sign-ins and never expire.
     */
    private function sessionTimeout(): void
    {
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid) {
            if (! $isValid || $token->name === User::PARTNER_TOKEN_NAME) {
                return $isValid;
            }

            $idle = (int) config('flightmode.auth.idle_minutes', 60);
            $max = (int) config('flightmode.auth.max_hours', 12);
            $lastSeen = $token->last_used_at ?? $token->created_at;

            return (! $idle || $lastSeen?->gt(now()->subMinutes($idle)))
                && (! $max || $token->created_at?->gt(now()->subHours($max)));
        });
    }

    /**
     * Online check-in is public. Many Oyu Tolgoi workers share one site IP, so the IP limits are loose and
     * the tight ones count per searched value / per token instead.
     */
    private function onlineCheckinLimits(): void
    {
        $upper = fn (Request $r, string $field) => mb_strtoupper(trim((string) $r->input($field)));

        RateLimiter::for('oci', fn (Request $r) => Limit::perMinute(120)
            ->by('oci:'.($r->header('X-Checkin-Token') ?: $r->ip())));

        RateLimiter::for('oci-sap', fn (Request $r) => [
            Limit::perMinute(120)->by('oci-sap-ip:'.$r->ip()),
            Limit::perMinute(10)->by('oci-sap:'.$upper($r, 'person_code')),
        ]);

        RateLimiter::for('oci-pnr', fn (Request $r) => [
            Limit::perMinute(30)->by('oci-pnr-ip:'.$r->ip()),
            Limit::perMinute(5)->by('oci-pnr:'.$upper($r, 'pnr')),
        ]);

        RateLimiter::for('oci-code', fn (Request $r) => [
            Limit::perMinute(30)->by('oci-code-ip:'.$r->ip()),
            Limit::perMinute(5)->by('oci-code:'.$r->path().':'.$r->input('lookup_token')),
        ]);
    }
}
