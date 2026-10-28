<?php

namespace App\Http\Resources;

use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Booking */
class BookingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pnr' => $this->pnr,
            'airline_id' => $this->airline_id,
            'agency' => $this->whenLoaded('agency', fn () => $this->agency ? ['id' => $this->agency->id, 'name' => $this->agency->name] : null),
            'agency_allocation_id' => $this->agency_allocation_id,
            'source' => $this->source->value,
            'status' => $this->status->value,
            'ticketing_deadline_at' => $this->ticketing_deadline_at?->toIso8601String(),
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'ticket_emailed_at' => $this->ticket_emailed_at?->toIso8601String(),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->only(['id', 'name'])),
            'passengers' => PassengerResource::collection($this->whenLoaded('passengers',
                fn () => $this->passengers->whereNull('infant_of_passenger_id')->values())),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
