<?php

namespace App\Http\Controllers\Api\V1\Checkin;

use App\Enums\BaggagePolicyType;
use App\Http\Controllers\Controller;
use App\Http\Resources\BaggagePolicyResource;
use App\Http\Resources\BaggageRuleResource;
use App\Models\BaggagePolicy;
use App\Models\Flight;
use App\Services\Checkin\OnlineCheckinService;
use App\Services\Seat\SeatHoldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public online check-in. After /verify every call sends the session token in the
 * `X-Checkin-Token` header.
 */
class OnlineCheckinController extends Controller
{
    public function __construct(private readonly OnlineCheckinService $checkin) {}

    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate(['person_code' => ['required', 'string', 'max:20']]);

        return response()->json(['data' => $this->checkin->lookup($data['person_code'])]);
    }

    public function sendCode(Request $request): JsonResponse
    {
        $data = $request->validate(['lookup_token' => ['required', 'string'], 'email' => ['required', 'email']]);
        $this->checkin->sendCode($data['lookup_token'], $data['email']);

        return response()->json(['message' => 'Verification code sent.']);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['lookup_token' => ['required', 'string'], 'code' => ['required', 'digits:4']]);

        return response()->json(['data' => $this->checkin->verify($data['lookup_token'], $data['code'])]);
    }

    public function passengers(Request $request, Flight $flight): JsonResponse
    {
        $flight->loadMissing(['origin', 'destination']);
        $segments = $this->checkin->sessionSegments($this->token($request), $flight);

        return response()->json(['data' => [
            'is_domestic' => $flight->isDomestic(),
            'online_checkin_open' => $this->checkin->isOpenOnline($flight),
            'passengers' => $segments->map(fn ($s) => [
                'segment_id' => $s->id,
                'name' => $s->passenger->last_name.'/'.$s->passenger->first_name,
                'type' => $s->passenger->type?->value,
                'cabin_class' => $s->cabin_class->value,
                'seat_number' => $s->seat_number,
                'is_checked_in' => $s->isCheckedIn(),
                'infant' => $s->passenger->infant ? $s->passenger->infant->last_name.'/'.$s->passenger->infant->first_name : null,
            ])->values(),
        ]]);
    }

    public function seatMap(Request $request, Flight $flight): JsonResponse
    {
        return response()->json(['data' => $this->checkin->seatMap($this->token($request), $flight)]);
    }

    public function holdSeat(Request $request, Flight $flight): JsonResponse
    {
        $data = $request->validate(['seat_number' => ['required', 'string', 'max:4']]);
        $this->checkin->holdSeat($this->token($request), $flight, $data['seat_number']);

        return response()->json(['message' => 'Seat held for '.SeatHoldService::TTL_MINUTES.' minutes.']);
    }

    public function checkIn(Request $request, Flight $flight): JsonResponse
    {
        $data = $request->validate([
            'passengers' => ['required', 'array', 'min:1'],
            'passengers.*.segment_id' => ['required', 'integer'],
            'passengers.*.seat_number' => ['nullable', 'string', 'max:4'],
            'accept_policies' => ['accepted'],
        ]);

        $this->checkin->checkIn($this->token($request), $flight, $data['passengers']);

        return response()->json([
            'message' => 'Checked in.',
            'data' => $this->checkin->boardingPasses($this->token($request), $flight),
        ]);
    }

    public function boardingPasses(Request $request, Flight $flight): JsonResponse
    {
        return response()->json(['data' => $this->checkin->boardingPasses($this->token($request), $flight)]);
    }

    /** Baggage / dangerous goods texts to accept before check-in, and the flight's baggage products. */
    public function policies(Request $request, Flight $flight): JsonResponse
    {
        $locale = $request->input('locale', 'mn');

        $policies = BaggagePolicy::query()->where('is_active', true)->where('locale', $locale)
            ->where(fn ($q) => $q->whereNull('airline_id')->orWhere('airline_id', $flight->airline_id))
            ->get()->groupBy(fn ($p) => $p->type->value);

        return response()->json(['data' => [
            'cabin' => BaggagePolicyResource::collection($policies->get(BaggagePolicyType::Cabin->value, collect()))->resolve(),
            'checked' => BaggagePolicyResource::collection($policies->get(BaggagePolicyType::Checked->value, collect()))->resolve(),
            'baggage_rules' => BaggageRuleResource::collection($flight->baggageRules()->where('is_active', true)->get())->resolve(),
        ]]);
    }

    private function token(Request $request): string
    {
        return (string) $request->header('X-Checkin-Token');
    }
}
