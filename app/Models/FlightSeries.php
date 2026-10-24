<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlightSeries extends Model
{
    protected $table = 'flight_series';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'excess_baggage_max_kg' => 'decimal:1',
            'excess_baggage_price_per_kg' => 'decimal:2',
        ];
    }

    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aircraft::class);
    }

    public function inventoryTemplate(): BelongsTo
    {
        return $this->belongsTo(InventoryTemplate::class);
    }

    public function origin(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'origin_airport_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'destination_airport_id');
    }

    public function flights(): HasMany
    {
        return $this->hasMany(Flight::class);
    }

    /** @return array<int> ISO weekdays 1 (Mon) .. 7 (Sun) */
    public function weekdays(): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string) $this->days_of_week))));
    }
}
