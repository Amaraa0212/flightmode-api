<?php

namespace App\Http\Resources;

use App\Models\Rbd;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Rbd */
class RbdResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'airline_id' => $this->airline_id,
            'code' => $this->code,
            'cabin_class' => $this->cabin_class->value,
            'rank' => $this->rank,
            'parent_id' => $this->parent_id,
            'parent_code' => $this->whenLoaded('parent', fn () => $this->parent?->code),
            'fare_family_id' => $this->fare_family_id,
            'fare_family_code' => $this->whenLoaded('fareFamily', fn () => $this->fareFamily?->code),
            'fare_family_name' => $this->whenLoaded('fareFamily', fn () => $this->fareFamily?->name),
            'advance_booking_days' => $this->advance_booking_days,
            'is_upgrade' => $this->is_upgrade,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'fare_classes_count' => $this->whenCounted('fareClasses'),
            'children_count' => $this->whenCounted('children'),
        ];
    }
}
