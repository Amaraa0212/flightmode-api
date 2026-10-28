<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class AgencyAllocation extends Model
{
    use SoftDeletes;

    /** Seats taken out of the flight for this agency */
    public const FIXED = 'fixed';

    /** Shared free sale: no seats taken out, the agency sells the flight's free seats (seats / cabin seats = limit, 0 = none) */
    public const POOL = 'pool';

    protected $guarded = ['id'];

    /** Column defaults, so a freshly created model returns them too */
    protected $attributes = ['mode' => self::FIXED];

    protected function casts(): array
    {
        return [
            'deadline' => 'date',
            'uses_fare_quotas' => 'boolean',
            'is_waitlist' => 'boolean',
            'chinggis_synced_at' => 'datetime',
        ];
    }

    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    public function outboundFlight(): BelongsTo
    {
        return $this->belongsTo(Flight::class, 'outbound_flight_id');
    }

    public function returnFlight(): BelongsTo
    {
        return $this->belongsTo(Flight::class, 'return_flight_id');
    }

    public function cabins(): HasMany
    {
        return $this->hasMany(AgencyAllocationCabin::class);
    }

    public function fares(): HasMany
    {
        return $this->hasMany(AgencyAllocationFare::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function isPool(): bool
    {
        return $this->mode === self::POOL;
    }

    public function isRoundTrip(): bool
    {
        return $this->return_flight_id !== null;
    }

    /** @return array<int> */
    public function flightIds(): array
    {
        return array_values(array_filter([$this->outbound_flight_id, $this->return_flight_id]));
    }

    /**
     * Last moment the agency may add / change names. When the deadline is the departure
     * day itself, names close 2 hours before departure (legacy rule).
     */
    public function namesDeadlineAt(): Carbon
    {
        $departure = $this->outboundFlight->departureAt();

        if ($this->deadline->isSameDay($departure)) {
            return $departure->copy()->subHours(2);
        }

        return $this->deadline->copy()->setTimeFrom($departure);
    }

    public function isOpenForNames(): bool
    {
        return now()->lt($this->namesDeadlineAt());
    }
}
