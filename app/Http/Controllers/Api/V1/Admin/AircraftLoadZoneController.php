<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Aircraft;
use App\Services\Access\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Load zones (legacy airplane_rule): zone X opens for online seat selection while the passenger count
 * is between pax_from and pax_to. The whole list is replaced on save.
 */
class AircraftLoadZoneController extends Controller
{
    public function __construct(private readonly AccessScope $access) {}

    public function index(Request $request, Aircraft $aircraft): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $aircraft);

        return response()->json(['data' => $aircraft->loadZones()->orderBy('pax_from')->get(['id', 'zone', 'pax_from', 'pax_to'])]);
    }

    public function replace(Request $request, Aircraft $aircraft): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $aircraft);
        $data = $request->validate([
            'zones' => ['present', 'array'],
            'zones.*.zone' => ['required', 'string', 'max:5'],
            'zones.*.pax_from' => ['required', 'integer', 'min:0'],
            'zones.*.pax_to' => ['required', 'integer', 'gte:zones.*.pax_from'],
        ]);

        DB::transaction(function () use ($aircraft, $data) {
            $aircraft->loadZones()->delete();
            foreach ($data['zones'] as $zone) {
                $aircraft->loadZones()->create(['zone' => strtoupper($zone['zone'])] + $zone);
            }
        });

        return $this->success('Load zones saved.', $aircraft->loadZones()->orderBy('pax_from')->get(['id', 'zone', 'pax_from', 'pax_to']));
    }
}
