<?php

namespace App\Services\Baggage;

use App\Enums\BaggageRuleType;
use App\Enums\SegmentStatus;
use App\Models\BaggageRule;
use App\Models\CheckedBaggage;
use App\Models\Flight;
use App\Models\PassengerSegment;
use Illuminate\Support\Collection;

/**
 * Free allowance, excess and price of a bag — the only place this is calculated (the DCS only shows the quote).
 *
 *  - PC rule (type piece, e.g. 1PC 23KG): the rule's pieces are free, each up to the rule's kg. A piece over them is
 *    charged as an excess piece (piece EBAG price each); kg over "pieces × rule kg" is excess kg (weight EBAG price per kg)
 *  - KG rule (type weight / combined, e.g. 20 KG): one free kg pool per passenger, whatever the number of pieces
 *    (2 bags of 19 kg in total fit 20 KG); the kg above it are excess kg. A check-in group shares one pool
 *  - XBAG (prepaid): free, as many pieces as were paid; FBAG: free
 *  - EBAG chosen as the rule: everything is excess at its own price (piece EBAG per piece, weight EBAG per kg)
 *  - excess prices come from the flight's EBAG rules (Shop items): a piece EBAG gives the price of an extra piece, a
 *    weight EBAG the price of a kg. Without one, that excess is free. There is no excess limit
 */
class BaggagePricing
{
    /**
     * @return array{free_allowance_kg: float, excess_kg: float, excess_pieces: int, excess_price: float, price: float,
     *               excess_price_per_kg: float, excess_price_per_piece: float, remaining_allowance_kg: ?float}
     */
    public function quote(PassengerSegment $segment, BaggageRule $rule, int $pieces, float $weightKg, ?Flight $flight = null): array
    {
        $flight ??= $segment->flight;
        [$perPiece, $perKg] = $this->excessRates($flight, $segment);
        $members = $this->allowanceSegments($segment, $rule);
        $remaining = null;

        if ($rule->isSsr(BaggageRule::SSR_EXCESS)) {
            // the excess rule itself: nothing free
            $byPiece = $rule->type === BaggageRuleType::Piece;
            $allowance = 0.0;
            $excessPieces = $byPiece ? $pieces : 0;
            $excessKg = $byPiece ? 0.0 : $weightKg;
            $excessPrice = $byPiece ? $pieces * (float) $rule->price : $weightKg * (float) $rule->price;
        } elseif ($rule->isSsr(BaggageRule::SSR_FREE)) {
            [$allowance, $excessPieces, $excessKg, $excessPrice] = [$weightKg, 0, 0.0, 0.0];
        } elseif ($rule->type === BaggageRuleType::Piece || $rule->isSsr(BaggageRule::SSR_PREPAID)) {
            // PC: free pieces left for the passenger (group), each up to the rule's kg
            $freePieces = $rule->isSsr(BaggageRule::SSR_PREPAID)
                ? (int) $members->sum(fn (PassengerSegment $m) => $m->prepaidBaggage->sum('pieces'))
                : max(1, (int) $rule->pieces) * $members->count();
            $usedPieces = (int) CheckedBaggage::query()->whereIn('passenger_segment_id', $members->pluck('id'))
                ->where('baggage_rule_id', $rule->id)->sum('pieces')
                - (int) CheckedBaggage::query()->whereIn('passenger_segment_id', $members->pluck('id'))
                    ->where('baggage_rule_id', $rule->id)->sum('excess_pieces');
            $covered = max(0, min($pieces, $freePieces - $usedPieces));
            $excessPieces = $pieces - $covered;
            $limit = $pieces * (float) $rule->weight_kg;
            $allowance = min($weightKg, $limit);
            $excessKg = round(max(0.0, $weightKg - $limit), 1);
            $excessPrice = $excessPieces * $perPiece + $excessKg * $perKg;
        } else {
            // KG: one pool of free kg, however many pieces
            $remaining = $this->remainingPool($segment, $rule);
            $allowance = min($weightKg, $remaining);
            $excessPieces = 0;
            $excessKg = round(max(0.0, $weightKg - $allowance), 1);
            $excessPrice = $excessKg * $perKg;
        }

        // a rule sold for money (not an SSR): its own price per piece
        $price = $rule->ssr_code ? 0.0 : round((float) $rule->price * $pieces, 2);

        return [
            'free_allowance_kg' => round($allowance, 1),
            'excess_kg' => round($excessKg, 1),
            'excess_pieces' => $excessPieces,
            'excess_price' => round($excessPrice, 2),
            'price' => $price,
            'excess_price_per_kg' => $perKg,
            'excess_price_per_piece' => $perPiece,
            'remaining_allowance_kg' => $remaining !== null ? round($remaining - $allowance, 1) : null,
        ];
    }

    /**
     * Prices of excess from the flight's EBAG rules (for the passenger's cabin first): [per piece, per kg].
     *
     * @return array{0: float, 1: float}
     */
    public function excessRates(Flight $flight, ?PassengerSegment $segment = null): array
    {
        $cabin = $segment?->cabin_class?->value;
        $ebags = $flight->baggageRules()->where('is_active', true)->where('ssr_code', BaggageRule::SSR_EXCESS)->get()
            ->filter(fn (BaggageRule $r) => $r->cabin_class === null || $r->cabin_class === $cabin)
            ->sortBy(fn (BaggageRule $r) => $r->cabin_class === null ? 1 : 0);
        $rate = fn (bool $byPiece) => (float) ($ebags->first(fn (BaggageRule $r) => ($r->type === BaggageRuleType::Piece) === $byPiece)?->price ?? 0);

        return [$rate(true), $rate(false)];
    }

    /** Free kg still unused under a combined rule by the passenger or their check-in group. */
    public function remainingPool(PassengerSegment $segment, BaggageRule $rule): float
    {
        $members = $this->allowanceSegments($segment, $rule);
        $used = (float) CheckedBaggage::query()->whereIn('passenger_segment_id', $members->pluck('id'))
            ->where('baggage_rule_id', $rule->id)->sum('free_allowance_kg');

        return max(0.0, round($rule->freeWeightPerPassenger() * $members->count() - $used, 1));
    }

    /**
     * Segments whose allowance a bag may use: the passenger alone, or every confirmed adult of their check-in
     * group on this flight that the rule applies to (same cabin).
     *
     * @return Collection<int, PassengerSegment>
     */
    public function allowanceSegments(PassengerSegment $segment, BaggageRule $rule): Collection
    {
        $segment->loadMissing('prepaidBaggage');
        if (! $segment->checkin_group_id) {
            return collect([$segment]);
        }

        $members = PassengerSegment::query()->with('prepaidBaggage')
            ->where('flight_id', $segment->flight_id)
            ->where('checkin_group_id', $segment->checkin_group_id)
            ->whereNotIn('status', [SegmentStatus::Cancelled, SegmentStatus::Waitlisted])
            ->whereHas('passenger', fn ($q) => $q->whereNull('infant_of_passenger_id'))
            ->get()
            ->filter(fn (PassengerSegment $s) => $rule->cabin_class === null || $s->cabin_class->value === $rule->cabin_class)
            ->values();

        return $members->contains('id', $segment->id) ? $members : $members->push($segment);
    }
}
