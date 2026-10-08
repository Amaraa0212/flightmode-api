<?php

namespace App\Http\Resources;

use App\Models\CheckedBaggage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CheckedBaggage */
class CheckedBaggageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'flight_id' => $this->flight_id,
            'passenger_segment_id' => $this->passenger_segment_id,
            // Owner of the bag (DCS baggage list); crew bags have no segment.
            'passenger' => $this->whenLoaded('segment', fn () => $this->segment?->passenger ? [
                'id' => $this->segment->passenger->id,
                'name' => $this->segment->passenger->last_name.'/'.$this->segment->passenger->first_name,
                'pnr' => $this->segment->passenger->relationLoaded('booking') ? $this->segment->passenger->booking?->pnr : null,
            ] : null),
            'type' => $this->type->value,
            'crew_name' => $this->crew_name,
            'baggage_rule' => $this->whenLoaded('rule', fn () => $this->rule ? ['id' => $this->rule->id, 'name' => $this->rule->name, 'ssr_code' => $this->rule->ssr_code] : null),
            'excess_baggage_rule_id' => $this->excess_baggage_rule_id,
            'pieces' => $this->pieces,
            'weight_kg' => $this->weight_kg,
            'free_allowance_kg' => $this->free_allowance_kg,
            'price' => $this->price,
            'excess_price' => $this->excess_price,
            'currency' => $this->currency,
            'checkin_group_id' => $this->checkin_group_id,
            'description' => $this->description,
            'tags' => $this->whenLoaded('tags', fn () => $this->tags->map(fn ($t) => ['id' => $t->id, 'tag_number' => $t->tag_number, 'sequence' => $t->sequence])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
