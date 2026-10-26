<?php

namespace App\Http\Resources;

use App\Enums\PassengerType;
use App\Models\AgencyHold;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AgencyHold */
class AgencyHoldResource extends JsonResource
{
    /** @var array<string, mixed>|null */
    public ?array $usage = null;

    public function withUsage(array $usage): self
    {
        $this->usage = $usage;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $allocation = $this->relationLoaded('allocation') ? $this->allocation : null;
        $fare = $this->relationLoaded('fare') ? $this->fare : null;

        return [
            'id' => $this->id,
            'agency_id' => $this->agency_id,
            'allocation_id' => $this->agency_allocation_id,
            'allocation_fare_id' => $this->agency_allocation_fare_id,
            'cabin_class' => $this->cabin_class->value,
            'seats' => $this->seats,
            'status' => $this->status,
            'fare' => $fare ? [
                'fare_class' => $fare->fareClass(),
                'price' => $fare->priceFor(PassengerType::Adult),
            ] : null,
            'outbound_flight' => $allocation?->relationLoaded('outboundFlight') ? new FlightResource($allocation->outboundFlight) : null,
            'return_flight' => $allocation?->relationLoaded('returnFlight') && $allocation->returnFlight ? new FlightResource($allocation->returnFlight) : null,
            'names_deadline_at' => $allocation?->namesDeadlineAt()->toIso8601String(),
            'usage' => $this->when($this->usage !== null, fn () => $this->usage),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'released_at' => $this->released_at?->toIso8601String(),
        ];
    }
}
