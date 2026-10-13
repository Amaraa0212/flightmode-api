<?php

namespace App\Http\Resources;

use App\Models\FareTax;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FareTax */
class FareTaxResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'airline_id' => $this->airline_id,
            'tax_code_id' => $this->tax_code_id,
            'tax_code' => $this->whenLoaded('taxCode', fn () => $this->taxCode->code),
            'airport_id' => $this->airport_id,
            'airport' => $this->whenLoaded('airport', fn () => $this->airport?->iata_code),
            'bound' => $this->bound,
            'fare_id' => $this->fare_id,
            'passenger_type' => $this->passenger_type->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'refundable' => $this->refundable,
        ];
    }
}
