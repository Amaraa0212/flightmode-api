<?php

namespace App\Http\Resources;

use App\Models\AgencyAccountTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AgencyAccountTransaction */
class AgencyAccountTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'amount' => $this->amount,
            'balance_after' => $this->balance_after,
            'note' => $this->note,
            'pnr' => $this->whenLoaded('booking', fn () => $this->booking?->pnr),
            'passenger' => $this->whenLoaded('passenger', fn () => $this->passenger
                ? $this->passenger->last_name.'/'.$this->passenger->first_name : null),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
