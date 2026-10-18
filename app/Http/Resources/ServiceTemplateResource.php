<?php

namespace App\Http\Resources;

use App\Models\ServiceTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ServiceTemplate */
class ServiceTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'airline_id' => $this->airline_id,
            'aircraft_id' => $this->aircraft_id,
            'aircraft' => $this->whenLoaded('aircraft', fn () => $this->aircraft?->registration),
            'name' => $this->name,
            'seat_assignment_profile_id' => $this->seat_assignment_profile_id,
            'seat_assignment_profile' => $this->whenLoaded('seatAssignmentProfile', fn () => $this->seatAssignmentProfile?->name),
            'checkin_opens_minutes' => $this->checkin_opens_minutes,
            'checkin_closes_minutes' => $this->checkin_closes_minutes,
            'baggage_rule_ids' => $this->whenLoaded('baggageRules', fn () => $this->baggageRules->pluck('id')),
            'seat_price_ids' => $this->whenLoaded('seatPrices', fn () => $this->seatPrices->pluck('id')),
        ];
    }
}
