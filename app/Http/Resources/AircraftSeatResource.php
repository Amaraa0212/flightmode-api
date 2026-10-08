<?php

namespace App\Http\Resources;

use App\Models\AircraftSeat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AircraftSeat */
class AircraftSeatResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'seat_number' => $this->seat_number,
            'row' => $this->seat_row,
            'column' => $this->seat_column,
            'side' => $this->side->value,
            'seat_type' => $this->seat_type->value,
            'cabin_class' => $this->cabin_class->value,
            'zone' => $this->zone,
            'is_emergency_exit' => $this->is_emergency_exit,
            'is_active' => $this->is_active,
        ];
    }
}
