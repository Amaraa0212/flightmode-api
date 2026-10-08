<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Aircraft extends Model
{
    use SoftDeletes;

    protected $table = 'aircraft';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }

    public function cabins(): HasMany
    {
        return $this->hasMany(AircraftCabin::class);
    }

    public function seats(): HasMany
    {
        return $this->hasMany(AircraftSeat::class)->orderBy('seat_row')->orderBy('seat_column');
    }

    public function loadZones(): HasMany
    {
        return $this->hasMany(AircraftLoadZone::class);
    }

    public function seatAssignmentProfiles(): HasMany
    {
        return $this->hasMany(SeatAssignmentProfile::class);
    }

    public function flights(): HasMany
    {
        return $this->hasMany(Flight::class);
    }

    /** @return array<string, int> cabin_class => seat_count */
    public function cabinSeatCounts(): array
    {
        return $this->cabins->mapWithKeys(fn (AircraftCabin $c) => [$c->cabin_class->value => $c->seat_count])->all();
    }
}
