<?php

namespace App\Providers;

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
        // `can:<menu>.<action>` checks the user's role permissions (menus / permissions tables). System admins pass everything.
        Gate::before(function (User $user, string $ability) {
            if (! preg_match('/^[a-z_]+\.[a-z_]+$/', $ability)) {
                return null;
            }

            return $user->hasPermission($ability) ?: null;
        });
    }
}
