<?php

namespace App\Http\Resources;

use App\Models\AgencyAllocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AgencyAllocation */
class AllocationResource extends JsonResource
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
        return [
            'id' => $this->id,
            'airline_id' => $this->airline_id,
            'agency' => $this->whenLoaded('agency', fn () => ['id' => $this->agency->id, 'name' => $this->agency->name]),
            'agency_id' => $this->agency_id,
            'outbound_flight' => new FlightResource($this->whenLoaded('outboundFlight')),
            'return_flight' => new FlightResource($this->whenLoaded('returnFlight')),
            'seats' => $this->seats,
            'deadline' => $this->deadline->toDateString(),
            'uses_fare_quotas' => $this->uses_fare_quotas,
            'is_waitlist' => $this->is_waitlist,
            'cabins' => $this->whenLoaded('cabins', fn () => $this->cabins->mapWithKeys(fn ($c) => [$c->cabin_class->value => $c->seats])),
            'fares' => $this->whenLoaded('fares', fn () => $this->fares->map(fn ($f) => [
                'id' => $f->id,
                'fare_id' => $f->fare_id,
                'fare_class' => $f->fareClass(),
                'cabin_class' => $f->cabin_class->value,
                'seats' => $f->seats,
                'min_fare_seats' => $f->min_fare_seats,
                'fare_amount' => $f->fare?->base_amount ?? $f->fare_amount,
                'fare_max_amount' => $f->fare_max_amount,
                'tax_amount' => $f->fare?->tax_amount ?? $f->tax_amount,
                'is_manual' => $f->isManual(),
            ])),
            'usage' => $this->when($this->usage !== null, fn () => $this->usage),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
