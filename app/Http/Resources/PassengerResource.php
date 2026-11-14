<?php

namespace App\Http\Resources;

use App\Models\Passenger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Passenger */
class PassengerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'booking_id' => $this->booking_id,
            'pnr' => $this->whenLoaded('booking', fn () => $this->booking->pnr),
            // open jaw: the PNR of the same trip on the other airline
            'linked_pnr' => $this->whenLoaded('booking', fn () => $this->booking->relationLoaded('linkedBooking') ? $this->booking->linkedBooking?->pnr : null),
            // on_hold: tickets still to be issued before the ticket time limit
            'booking_status' => $this->whenLoaded('booking', fn () => $this->booking->status->value),
            'ticketing_deadline_at' => $this->whenLoaded('booking', fn () => $this->booking->ticketing_deadline_at?->toIso8601String()),
            // agency quota passengers: the staff member who issued (or booked) it
            'issued_by' => $this->when($this->relationLoaded('booking') && $this->booking->hasAttribute('issued_by_name'),
                fn () => $this->booking->getAttribute('issued_by_name')),
            'agency' => $this->whenLoaded('booking', fn () => $this->booking->relationLoaded('agency') && $this->booking->agency
                ? ['id' => $this->booking->agency->id, 'name' => $this->booking->agency->name] : null),
            'type' => $this->type?->value,
            'infant_of_passenger_id' => $this->infant_of_passenger_id,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'gender' => $this->gender?->value,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'nationality' => $this->nationality,
            'person_code' => $this->person_code,
            'document_type' => $this->document_type,
            'document_number' => $this->document_number,
            'document_issuing_country' => $this->document_issuing_country,
            'document_issued_at' => $this->document_issued_at?->toDateString(),
            'document_expires_at' => $this->document_expires_at?->toDateString(),
            'email' => $this->email,
            'phone' => $this->phone,
            'emergency_contact' => $this->emergency_contact,
            'remark' => $this->remark,
            'infant' => new PassengerResource($this->whenLoaded('infant')),
            'segments' => SegmentResource::collection($this->whenLoaded('segments')),
        ];
    }
}
