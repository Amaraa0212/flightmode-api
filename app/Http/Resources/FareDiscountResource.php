<?php

namespace App\Http\Resources;

use App\Models\FareDiscount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FareDiscount */
class FareDiscountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fare_class_id' => $this->fare_class_id,
            'passenger_type' => $this->passenger_type->value,
            'age_from' => $this->age_from,
            'age_to' => $this->age_to,
            'percentage' => $this->percentage,
        ];
    }
}
