<?php

namespace App\Http\Resources;

use App\Models\Fare;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Fare */
class FareResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'airline_id' => $this->airline_id,
            'origin' => $this->whenLoaded('origin', fn () => $this->origin->iata_code),
            'destination' => $this->whenLoaded('destination', fn () => $this->destination->iata_code),
            'origin_airport_id' => $this->origin_airport_id,
            'destination_airport_id' => $this->destination_airport_id,
            'trip_type' => $this->trip_type->value,
            'cabin_class' => $this->cabin_class->value,
            'fare_class' => $this->fare_class,
            'valid_from' => $this->valid_from->toDateString(),
            'valid_to' => $this->valid_to->toDateString(),
            'base_amount' => $this->base_amount,
            'tax_amount' => $this->tax_amount,
            'total_amount' => number_format((float) $this->base_amount + (float) $this->tax_amount, 2, '.', ''),
            'child_discount_percent' => $this->child_discount_percent,
            'infant_discount_percent' => $this->infant_discount_percent,
            'currency' => $this->currency,
            'conditions' => $this->conditions,
            'is_active' => $this->is_active,
        ];
    }
}
