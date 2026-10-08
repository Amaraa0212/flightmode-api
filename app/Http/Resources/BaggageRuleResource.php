<?php

namespace App\Http\Resources;

use App\Models\BaggageRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BaggageRule */
class BaggageRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'airline_id' => $this->airline_id,
            'name' => $this->name,
            'type' => $this->type->value,
            'cabin_class' => $this->cabin_class,
            'pieces' => $this->pieces,
            'weight_kg' => $this->weight_kg,
            'max_quantity' => $this->max_quantity,
            'price' => $this->price,
            'currency' => $this->currency,
            'ssr_code' => $this->ssr_code,
            'is_active' => $this->is_active,
        ];
    }
}
