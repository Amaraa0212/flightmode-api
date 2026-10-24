<?php

namespace App\Models;

use App\Enums\CabinClass;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Seats of one cabin on one flight (ameliaRES "class of service" row). */
class FlightCabinInventory extends Model
{
    protected $table = 'flight_cabin_inventory';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cabin_class' => CabinClass::class];
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    /** Seats that may be sold: seats + oversell - curtail. */
    public function capacity(): int
    {
        return max(0, $this->seats + $this->oversell - $this->curtail);
    }
}
