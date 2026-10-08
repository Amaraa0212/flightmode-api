<?php

namespace App\Http\Resources;

use App\Models\SsrCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SsrCode */
class SsrCodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'category' => $this->category,
            'remark' => $this->whenPivotLoaded('passenger_segment_ssr', fn () => $this->pivot->remark),
        ];
    }
}
