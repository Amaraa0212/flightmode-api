<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AirlineSsrResource;
use App\Models\AirlineSsr;
use App\Models\SsrCode;
use App\Services\Access\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** SSRs an airline offers to its passengers, with an optional price. */
class AirlineSsrController extends Controller
{
    public function __construct(private readonly AccessScope $access) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return AirlineSsrResource::collection(
            $this->access->airlineOwned(AirlineSsr::query(), $request->user())
                ->when($request->filled('airline_id'), fn ($q) => $q->where('airline_id', $request->integer('airline_id')))
                ->with(['ssrCode', 'airline'])->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $airlineId = $this->access->airlineIdForWrite($request->user(), $request->integer('airline_id') ?: null);
        // the admin SSR page sends the code: an IATA code already in the catalogue is linked, a new one is added to it
        if (! $request->filled('ssr_code_id') && $request->filled('code')) {
            $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
            $known = SsrCode::query()->where('code', $request->input('code'))->first();
            if (! $known) {
                $new = $request->validate([
                    'code' => ['required', 'alpha_num', 'max:8'],
                    'name' => ['required', 'string', 'max:255'],
                    'category' => ['nullable', 'string', 'max:50'],
                ]);
                $known = SsrCode::create($new);
            }
            $request->merge(['ssr_code_id' => $known->id]);
        }
        $data = $request->validate([
            'ssr_code_id' => ['required', 'integer', 'exists:ssr_codes,id',
                Rule::unique('airline_ssrs')->where('airline_id', $airlineId)],
            'price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
        ], ['ssr_code_id.unique' => 'Your airline already offers this SSR.']);

        $ssr = AirlineSsr::create($data + ['airline_id' => $airlineId]);

        return $this->created('SSR added.', new AirlineSsrResource($ssr->load('ssrCode')));
    }

    public function update(Request $request, AirlineSsr $airlineSsr): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $airlineSsr);
        $airlineSsr->update($request->validate([
            'price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]));

        return $this->success('SSR updated.', new AirlineSsrResource($airlineSsr->load('ssrCode')));
    }

    public function destroy(Request $request, AirlineSsr $airlineSsr): JsonResponse
    {
        $this->access->ensureAirlineOwned($request->user(), $airlineSsr);
        $airlineSsr->delete();

        return $this->deleted('SSR removed.');
    }
}
