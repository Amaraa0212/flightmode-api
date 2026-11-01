<?php

namespace App\Http\Resources;

use App\Models\FareCombination;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FareCombination */
class FareCombinationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $base = fn ($c) => $c ? [
            'id' => $c->id,
            'booking_code' => $c->booking_code,
            'rbd_code' => $c->rbd?->code,
            'trip_type' => $c->trip_type->value,
        ] : null;

        return [
            'id' => $this->id,
            'airline_id' => $this->airline_id,
            'fare_class_id' => $this->fare_class_id,
            'combinable_fare_class_id' => $this->combinable_fare_class_id,
            'fare_class' => $this->whenLoaded('fareClass', fn () => $base($this->fareClass)),
            'combinable_fare_class' => $this->whenLoaded('combinableFareClass', fn () => $base($this->combinableFareClass)),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
