<?php

namespace App\Models;

use App\Enums\BaggagePolicyType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BaggagePolicy extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['type' => BaggagePolicyType::class, 'is_active' => 'boolean'];
    }

    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }
}
