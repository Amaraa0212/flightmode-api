<?php

namespace App\Models;

use App\Enums\CabinClass;
use App\Enums\SeatType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeatPrice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cabin_class' => CabinClass::class,
            'seat_type' => SeatType::class,
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }

    public function appliesTo(AircraftSeat $seat): bool
    {
        return $this->cabin_class === $seat->cabin_class
            && ($this->seat_type === null || $this->seat_type === $seat->seat_type);
    }
}
