<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CabinClass;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GenerateSeatsRequest;
use App\Http\Resources\AircraftSeatResource;
use App\Models\Aircraft;
use App\Models\AircraftSeat;
use App\Services\Access\AccessScope;
use App\Services\Seat\SeatMapGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Physical seat map of an aircraft, built block by block. */
class AircraftSeatController extends Controller
{
    public function __construct(
        private readonly AccessScope $access,
        private readonly SeatMapGenerator $generator,
    ) {}

    public function index(Request $request, Aircraft $aircraft): AnonymousResourceCollection
    {
        $this->access->ensureAirlineOwned($request->user(), $aircraft);

        return AircraftSeatResource::collection($aircraft->seats()->get());
    }

    /** Create (or replace) the seats of a row range. */
    public function generate(GenerateSeatsRequest $request, Aircraft $aircraft): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $aircraft);

        $seats = $this->generator->generateBlock(
            $aircraft,
            $request->integer('row_from'),
            $request->integer('row_to'),
            CabinClass::from($request->string('cabin_class')),
            $request->validated('columns'),
            $request->validated('zone'),
            $request->boolean('is_emergency_exit'),
            $request->validated('skip_rows', []),
        );

        return $this->created("{$seats->count()} seat(s) saved.", AircraftSeatResource::collection($seats)->resolve());
    }

    public function destroyRows(Request $request, Aircraft $aircraft): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $aircraft);
        $data = $request->validate([
            'row_from' => ['required', 'integer', 'min:1'],
            'row_to' => ['required', 'integer', 'gte:row_from'],
        ]);

        $deleted = $this->generator->deleteRows($aircraft, $data['row_from'], $data['row_to']);

        return $this->deleted("{$deleted} seat(s) deleted.");
    }

    public function update(Request $request, Aircraft $aircraft, AircraftSeat $seat): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $aircraft);
        abort_unless($seat->aircraft_id === $aircraft->id, 404);

        $seat->update($request->validate([
            'zone' => ['nullable', 'string', 'max:5'],
            'is_emergency_exit' => ['boolean'],
            'is_active' => ['boolean'],
        ]));

        return $this->success('Seat updated.', new AircraftSeatResource($seat));
    }
}
