<?php

namespace App\Http\Resources;

use App\Models\PassengerSegment;
use App\Services\Checkin\PassengerScreening;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PassengerSegment */
class SegmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'passenger_id' => $this->passenger_id,
            'flight_id' => $this->flight_id,
            'flight' => new FlightResource($this->whenLoaded('flight')),
            'segment_no' => $this->segment_no,
            'cabin_class' => $this->cabin_class->value,
            'fare_class' => $this->fare_class,
            'fare_id' => $this->fare_id,
            'agency_allocation_fare_id' => $this->agency_allocation_fare_id,
            'ticket_number' => $this->ticket_number,
            'fare_amount' => $this->fare_amount,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->totalAmount(),
            'agency_sale_amount' => $this->agency_sale_amount,
            'currency' => $this->currency,
            'seat_number' => $this->seat_number,
            'status' => $this->status->value,
            'is_go_show' => $this->is_go_show,
            'boarding_sequence' => $this->boarding_sequence,
            'checkin_group_id' => $this->checkin_group_id,
            'checkin_channel' => $this->checkin_channel?->value,
            'checked_in_at' => $this->checked_in_at?->toIso8601String(),
            'boarding_pass_printed_at' => $this->boarding_pass_printed_at?->toIso8601String(),
            'boarded_at' => $this->boarded_at?->toIso8601String(),
            'offload_type' => $this->offload_type,
            'offload_reason' => $this->offload_reason,
            'ssr_codes' => $this->whenLoaded('ssrCodes', fn () => $this->ssrCodes->pluck('code')),
            'prepaid_baggage' => $this->whenLoaded('prepaidBaggage', fn () => [
                'pieces' => (int) $this->prepaidBaggage->sum('pieces'),
                'weight_kg' => (float) $this->prepaidBaggage->sum('weight_kg'),
            ]),
            'baggage' => CheckedBaggageResource::collection($this->whenLoaded('checkedBaggage')),
            'passenger' => new PassengerResource($this->whenLoaded('passenger')),
            // DCS only: ban-list screening (never exposed to agencies)
            'screening' => $this->when(
                $request->is('api/v1/dcs/*') && $this->relationLoaded('passenger'),
                fn () => app(PassengerScreening::class)->screenOnFlight($this->passenger, $this->flight_id),
            ),
        ];
    }
}
