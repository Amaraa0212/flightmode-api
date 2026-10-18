<?php

namespace App\Http\Controllers\Api\V1\Dcs;

use App\Enums\Gender;
use App\Http\Controllers\Controller;
use App\Http\Resources\BaggageRuleResource;
use App\Http\Resources\CheckedBaggageResource;
use App\Models\BaggageRule;
use App\Models\BaggageTag;
use App\Models\CheckedBaggage;
use App\Models\Flight;
use App\Models\PassengerSegment;
use App\Services\Access\AccessScope;
use App\Services\Baggage\BaggagePricing;
use App\Services\Baggage\BaggageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class BaggageController extends Controller
{
    public function __construct(
        private readonly AccessScope $access,
        private readonly BaggageService $baggage,
        private readonly BaggagePricing $pricing,
    ) {}

    /** All baggage of the flight (crew included). */
    public function index(Request $request, Flight $flight): AnonymousResourceCollection
    {
        $this->access->ensureFlight($request->user(), $flight);

        return CheckedBaggageResource::collection(
            $flight->checkedBaggage()->with(['tags', 'rule', 'segment.passenger.booking'])->latest('id')->get()
        );
    }

    /**
     * Rules the counter may use for this passenger: the flight's rules for the passenger's cabin plus the
     * free-bag rule, and how many prepaid (XBAG) pieces the passenger has.
     */
    public function rules(Request $request, PassengerSegment $segment): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $segment->flight);

        $rules = $segment->flight->baggageRules()->where('is_active', true)->get()
            ->filter(fn (BaggageRule $r) => $r->cabin_class === null || $r->cabin_class === $segment->cabin_class->value || $r->isSsr(BaggageRule::SSR_FREE))
            ->values();

        return response()->json(['data' => [
            'rules' => BaggageRuleResource::collection($rules)->resolve(),
            'prepaid_pieces' => (int) $segment->prepaidBaggage()->sum('pieces'),
            'excess_price_per_kg' => $segment->flight->excess_baggage_price_per_kg,
            'excess_max_kg' => $segment->flight->excess_baggage_max_kg,
            'accepted' => CheckedBaggageResource::collection($segment->checkedBaggage()->with('tags', 'rule')->get())->resolve(),
        ]]);
    }

    public function store(Request $request, PassengerSegment $segment): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $segment->flight);
        $data = $request->validate([
            'baggage_rule_id' => ['required', 'integer', Rule::exists('baggage_rules', 'id')->where('airline_id', $segment->flight->airline_id)],
            'excess_baggage_rule_id' => ['nullable', 'integer', Rule::exists('baggage_rules', 'id')->where('airline_id', $segment->flight->airline_id)],
            'pieces' => ['required', 'integer', 'min:1', 'max:20'],
            'weight_kg' => ['required', 'numeric', 'min:0.1', 'max:999'],
            'description' => ['nullable', 'string', 'max:255'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'waive_reason' => ['nullable', 'string', 'max:500'],
            'waive_authorized_by' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->created('Baggage accepted.', new CheckedBaggageResource($this->baggage->accept($segment, $data)));
    }

    /** Price preview for the counter (same calculation as store) — nothing is saved. */
    public function quote(Request $request, PassengerSegment $segment): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $segment->flight);
        $data = $request->validate([
            'baggage_rule_id' => ['required', 'integer', Rule::exists('baggage_rules', 'id')->where('airline_id', $segment->flight->airline_id)],
            'pieces' => ['required', 'integer', 'min:1', 'max:20'],
            'weight_kg' => ['required', 'numeric', 'min:0', 'max:999'],
        ]);

        $rule = BaggageRule::query()->findOrFail($data['baggage_rule_id']);
        // same piece check as accepting the bag, so the counter sees the problem before tagging
        $this->baggage->assertPieceLimit($segment, $rule, (int) $data['pieces']);

        return response()->json(['data' => $this->pricing->quote($segment, $rule, (int) $data['pieces'], (float) $data['weight_kg']) + [
            'currency' => $segment->flight->airline->currency,
        ]]);
    }

    public function storeCrew(Request $request, Flight $flight): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $flight);
        $data = $request->validate([
            'crew_name' => ['nullable', 'string', 'max:100'],
            'pieces' => ['nullable', 'integer', 'min:1', 'max:20'],
            'weight_kg' => ['required', 'numeric', 'min:0.1', 'max:999'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->created('Crew baggage accepted.', new CheckedBaggageResource($this->baggage->acceptCrew($flight, $data)));
    }

    public function destroy(Request $request, CheckedBaggage $checkedBaggage): JsonResponse
    {
        $this->access->ensureFlight($request->user(), $checkedBaggage->flight);
        $this->baggage->remove($checkedBaggage);

        return $this->deleted('Baggage removed.');
    }

    /** Data for printing one bag tag (the frontend renders the CODE128 barcode from tag_number). */
    public function tag(Request $request, BaggageTag $tag): JsonResponse
    {
        $tag->load(['flight.airline', 'flight.destination', 'flight.origin', 'checkedBaggage.segment.passenger']);
        $this->access->ensureFlight($request->user(), $tag->flight);

        $flight = $tag->flight;
        $baggage = $tag->checkedBaggage;
        $passenger = $baggage->segment?->passenger;

        return response()->json(['data' => [
            'tag_number' => $tag->tag_number,
            'barcode' => $tag->tag_number,
            'airline' => [
                'name' => $flight->airline->name,
                'iata_code' => $flight->airline->iata_code,
                'logo_url' => $flight->airline->logoUrl(),
                'layout' => $flight->airline->bag_tag_layout,
            ],
            'flight_number' => $flight->flight_number,
            'date' => $flight->departureAt()->copy()->timezone($flight->origin->timezone)->format('dM'),
            'origin' => $flight->origin->iata_code,
            'destination' => ['code' => $flight->destination->iata_code, 'city' => $flight->destination->city, 'name' => $flight->destination->name],
            'passenger' => $passenger ? $passenger->last_name.'/'.$passenger->first_name : ($baggage->crew_name ? 'CREW '.$baggage->crew_name : 'CREW'),
            'passenger_title' => $passenger ? ($passenger->gender === Gender::Female ? 'MRS' : 'MR') : null,
            'is_crew' => $baggage->passenger_segment_id === null,
            'pnr' => $passenger?->booking?->pnr,
            'piece' => $baggage->tags()->where('sequence', '<=', $tag->sequence)->count().'/'.$baggage->pieces,
            'weight_kg' => $baggage->weight_kg,
        ]]);
    }
}
