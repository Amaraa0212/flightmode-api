<?php

namespace App\Http\Resources;

use App\Models\RbdModification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RbdModification */
class RbdModificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'airline_id' => $this->airline_id,
            'rbd_id' => $this->rbd_id,
            'rbd_code' => $this->whenLoaded('rbd', fn () => $this->rbd->code),
            'cabin_class' => $this->whenLoaded('rbd', fn () => $this->rbd->cabin_class->value),
            'type' => $this->type,
            'timeframe_hours' => $this->timeframe_hours,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'percentage' => $this->percentage,
            'is_allowed' => $this->is_allowed,
        ];
    }
}
