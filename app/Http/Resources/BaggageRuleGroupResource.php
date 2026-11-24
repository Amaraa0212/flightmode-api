<?php

namespace App\Http\Resources;

use App\Models\BaggageRule;
use App\Models\BaggageRuleGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BaggageRuleGroup */
class BaggageRuleGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'airline_id' => $this->airline_id,
            'name' => $this->name,
            'type' => $this->type->value,
            'cabin_class' => $this->cabin_class,
            'max_quantity' => $this->max_quantity,
            'currency' => $this->currency,
            'is_active' => $this->is_active,
            'rules' => $this->whenLoaded('rules', fn () => $this->rules->map(fn (BaggageRule $r) => [
                'id' => $r->id,
                'ssr_code' => $r->ssr_code,
                'pieces' => $r->pieces,
                'weight_kg' => $r->weight_kg,
                'price' => $r->price,
            ])),
        ];
    }
}
