<?php

namespace App\Models;

use App\Enums\PassengerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FareDiscount extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'passenger_type' => PassengerType::class,
            'age_from' => 'integer',
            'age_to' => 'integer',
            'percentage' => 'integer',
        ];
    }

    public function fareClass(): BelongsTo
    {
        return $this->belongsTo(FareClass::class);
    }
}
