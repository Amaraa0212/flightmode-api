<?php

namespace App\Providers;

use App\Enums\PermissionKey;
use App\Models\User;
use App\Services\Checkin\PassengerScreening;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Ban list is loaded once per request (DCS passenger list screens every row)
        $this->app->scoped(PassengerScreening::class);
    }

    public function boot(): void
    {
        // `can:<permission.key>` checks the user's role permissions. System admins pass everything.
        Gate::before(function (User $user, string $ability) {
            if (PermissionKey::tryFrom($ability) === null) {
                return null;
            }

            return $user->hasPermission($ability) ?: null;
        });
    }
}
