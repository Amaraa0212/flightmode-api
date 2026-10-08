<?php

namespace App\Models;

use App\Enums\CabinClass;
use App\Enums\SeatSide;
use App\Enums\SeatType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AircraftSeat extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cabin_class' => CabinClass::class,
            'seat_type' => SeatType::class,
            'side' => SeatSide::class,
            'is_emergency_exit' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aircraft::class);
    }
}
