<?php

namespace App\Http\Resources;

use App\Models\SeatPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SeatPrice */
class SeatPriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'airline_id' => $this->airline_id,
            'cabin_class' => $this->cabin_class->value,
            'seat_type' => $this->seat_type?->value,
            'price' => $this->price,
            'currency' => $this->currency,
            'is_active' => $this->is_active,
        ];
    }
}
