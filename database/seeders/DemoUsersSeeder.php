<?php

namespace Database\Seeders;

use App\Models\Agency;
use App\Models\Airline;
use App\Models\Airport;
use App\Models\Flight;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * One demo user per role for each demo airline (MIAT, Hunnu Air), tied to that airline:
 * airline admin / staff, station and gate agents at UBN (the airline's ground handling), and the airline's
 * sales agency with its admin and ticketing agent. Gate agents are assigned to the airline's flights from UBN.
 *
 * Login: <airline code>.<role>@demo.flightmode.mn, e.g. om.station@demo.flightmode.mn — password DEMO_USERS_PASSWORD.
 * Safe to re-run (DcsDemoSeeder runs it after re-creating the flights).
 */
class DemoUsersSeeder extends Seeder
{
    /** role slug => [login prefix, first name of the person] */
    private const ROLES = [
        'airline-admin' => ['admin', 'Bat-Erdene'],
        'airline-staff' => ['staff', 'Sarnai'],
        'station-agent' => ['station', 'Enkhjin'],
        'gate-agent' => ['gate', 'Temuulen'],
        'agency-admin' => ['agency.admin', 'Nomin'],
        'agency-agent' => ['agency.agent', 'Tuguldur'],
    ];

    public function run(): void
    {
        $this->call([PermissionSeeder::class, RoleSeeder::class]);

        $ubn = Airport::query()->where('iata_code', 'UBN')->first();
        $airlines = Airline::query()->whereIn('iata_code', ['OM', 'MR'])->orderBy('iata_code', 'desc')->get();
        if (! $ubn || $airlines->isEmpty()) {
            $this->command?->warn('DemoUsersSeeder: run DcsDemoSeeder first (needs UBN and the OM / MR airlines).');

            return;
        }

        $roles = Role::query()->whereIn('slug', array_keys(self::ROLES))->pluck('id', 'slug');
        $password = env('DEMO_USERS_PASSWORD', 'Demo2026!');
        $rows = [];

        foreach ($airlines as $airline) {
            $code = strtolower($airline->iata_code);
            $agency = $this->agency($airline);

            foreach (self::ROLES as $slug => [$prefix, $person]) {
                $scope = match ($slug) {
                    'airline-admin', 'airline-staff' => ['airline_id' => $airline->id, 'airport_id' => null, 'agency_id' => null],
                    'station-agent', 'gate-agent' => ['airline_id' => $airline->id, 'airport_id' => $ubn->id, 'agency_id' => null],
                    // agency users: agency only — AccessScope treats agency_id without airline_id as an agency login
                    'agency-admin', 'agency-agent' => ['airline_id' => null, 'airport_id' => null, 'agency_id' => $agency->id],
                };

                $user = User::query()->withTrashed()->updateOrCreate(
                    ['email' => "{$code}.{$prefix}@demo.flightmode.mn"],
                    $scope + [
                        'name' => "{$person} ({$airline->iata_code} ".str_replace('-', ' ', $slug).')',
                        'password' => $password,
                        'role_id' => $roles[$slug],
                        'is_active' => true,
                        'deleted_at' => null,
                    ],
                );

                if ($slug === 'gate-agent') {
                    // the gate app lists only the flights the agent is assigned to
                    Flight::query()->where('airline_id', $airline->id)->where('origin_airport_id', $ubn->id)->get()
                        ->each(fn (Flight $flight) => $flight->gateAgents()->syncWithoutDetaching([$user->id]));
                }

                $rows[] = [$airline->iata_code, $slug, $user->email];
            }
        }

        $this->command?->table(['Airline', 'Role', 'Login'], $rows);
        // quotas and sold bookings for the agency users
        $this->call(DemoAgencySeeder::class);
        $this->command?->info('Password for every demo user: DEMO_USERS_PASSWORD (default in .env.example).');
    }

    /** The airline's sales agency (linked to that airline only) */
    private function agency(Airline $airline): Agency
    {
        $agency = Agency::query()->withTrashed()->updateOrCreate(
            ['code' => "DEMO-{$airline->iata_code}"],
            [
                'name' => "{$airline->name} Sales Agency",
                'email' => strtolower($airline->iata_code).'.agency@demo.flightmode.mn',
                'is_active' => true,
                'deleted_at' => null,
            ],
        );
        $agency->airlines()->syncWithoutDetaching([$airline->id => ['is_active' => true]]);

        return $agency;
    }
}
