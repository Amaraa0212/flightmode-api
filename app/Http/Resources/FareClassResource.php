<?php

namespace App\Http\Resources;

use App\Models\FareClass;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FareClass */
class FareClassResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'airline_id' => $this->airline_id,
            'rbd_id' => $this->rbd_id,
            'rbd_code' => $this->whenLoaded('rbd', fn () => $this->rbd->code),
            'cabin_class' => $this->whenLoaded('rbd', fn () => $this->rbd->cabin_class->value),
            'booking_code' => $this->booking_code,
            'description' => $this->description,
            'status' => $this->status,
            'waitlist' => $this->waitlist,
            'is_visible' => $this->is_visible,
            'trip_type' => $this->trip_type->value,
            'rule' => $this->rule,
            'is_group' => $this->is_group,
            'group_min' => $this->group_min,
            'group_max' => $this->group_max,
            'ticket_time_limit_hours' => $this->ticket_time_limit_hours,
            'ticket_time_limit_before_departure_hours' => $this->ticket_time_limit_before_departure_hours,
            'is_active' => $this->is_active,
            'discounts' => FareDiscountResource::collection($this->whenLoaded('discounts')),
            'fares_count' => $this->whenCounted('fares'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
