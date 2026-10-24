<?php

namespace Database\Seeders;

use App\Models\Airline;
use App\Models\Rbd;
use Illuminate\Database\Seeder;

/**
 * Demo booking classes (RBD) for the demo airlines (MIAT, Hunnu Air), nested like the legacy ameliaRES MG714 setup:
 * C > D > I, W > E, and the economy chain Y > B > H > K > L > M > N > Q > T > U > V > R > O > Z > X with S as a second branch under Y.
 * V and R are inactive (closed in the legacy screen). Safe to re-run: rows are matched by airline + code.
 */
class RbdDemoSeeder extends Seeder
{
    /** code => [cabin, rank, parent code, active, description] — parents come before their children */
    private const RBDS = [
        'C' => ['C', 1, null, true, 'Business full fare'],
        'D' => ['C', 2, 'C', true, 'Business discount'],
        'I' => ['C', 3, 'D', true, 'Business promo'],
        'W' => ['W', 1, null, true, 'Premium economy full fare'],
        'E' => ['W', 2, 'W', true, 'Premium economy discount'],
        'Y' => ['Y', 1, null, true, 'Economy full fare'],
        'B' => ['Y', 2, 'Y', true, 'Economy flexible'],
        'S' => ['Y', 2, 'Y', true, 'Economy special (own branch)'],
        'H' => ['Y', 3, 'B', true, 'Economy standard'],
        'K' => ['Y', 4, 'H', true, 'Economy standard'],
        'L' => ['Y', 5, 'K', true, 'Economy saver'],
        'M' => ['Y', 6, 'L', true, 'Economy saver'],
        'N' => ['Y', 7, 'M', true, 'Economy discount'],
        'Q' => ['Y', 8, 'N', true, 'Economy discount'],
        'T' => ['Y', 9, 'Q', true, 'Economy promo'],
        'U' => ['Y', 10, 'T', true, 'Economy promo'],
        'V' => ['Y', 11, 'U', false, 'Economy promo (closed)'],
        'R' => ['Y', 12, 'V', false, 'Economy promo (closed)'],
        'O' => ['Y', 13, 'R', true, 'Economy lowest'],
        'Z' => ['Y', 14, 'O', true, 'Economy lowest'],
        'X' => ['Y', 15, 'Z', true, 'Economy group / tour'],
    ];

    public function run(): void
    {
        $airlines = Airline::query()->whereIn('iata_code', ['OM', 'MR'])->get();
        if ($airlines->isEmpty()) {
            $this->command?->warn('RbdDemoSeeder: run DcsDemoSeeder first (needs the OM / MR airlines).');

            return;
        }

        foreach ($airlines as $airline) {
            $ids = [];
            foreach (self::RBDS as $code => [$cabin, $rank, $parent, $active, $description]) {
                $ids[$code] = Rbd::query()->updateOrCreate(
                    ['airline_id' => $airline->id, 'code' => $code],
                    [
                        'cabin_class' => $cabin,
                        'rank' => $rank,
                        'parent_id' => $parent ? $ids[$parent] : null,
                        'is_active' => $active,
                        'description' => $description,
                    ],
                )->id;
            }

            $this->command?->info("RbdDemoSeeder: {$airline->iata_code} ".count($ids).' RBD');
        }
    }
}
