<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Nested booking limit of one RBD on one flight (ameliaRES "booking code" row). */
class FlightRbdInventory extends Model
{
    protected $table = 'flight_rbd_inventory';

    protected $guarded = ['id'];

    protected $attributes = ['is_closed' => false];

    protected function casts(): array
    {
        return ['is_closed' => 'boolean'];
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    public function rbd(): BelongsTo
    {
        return $this->belongsTo(Rbd::class);
    }
}
