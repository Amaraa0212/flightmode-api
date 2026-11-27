<?php

namespace App\Models;

use App\Enums\FlightStatus;
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
            'uses_published_fares' => 'boolean',
            'released_at' => 'datetime',
            'released_seats' => 'integer',
            'released_cabins' => 'array',
            'deadline_hours' => 'integer',
            'is_waitlist' => 'boolean',
            'chinggis_synced_at' => 'datetime',
            'schedule_change_seen_at' => 'datetime',
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
     * Last moment the agency may add / change names. When the deadline is the departure day itself, names close
     * `deadline_hours` hours before check-in opens (check-in opens 3 hours before departure).
     */
    public function namesDeadlineAt(): Carbon
    {
        $departure = $this->outboundFlight->departureAt();

        if ($this->deadline->isSameDay($departure)) {
            return $this->checkinOpensAt()->subHours((int) $this->deadline_hours);
        }

        return $this->deadline->copy()->setTimeFrom($departure);
    }

    public function checkinOpensAt(): Carbon
    {
        return $this->outboundFlight->departureAt()->copy()
            ->subHours((int) config('flightmode.allocations.checkin_opens_hours', 3));
    }

    /** Check-in of the outbound flight has been opened (or the flight is past it): no more names. */
    public function isCheckinStarted(): bool
    {
        $status = $this->outboundFlight->status;

        return $status !== null && $status !== FlightStatus::Scheduled;
    }

    public function isOpenForNames(): bool
    {
        return ! $this->isCheckinStarted() && now()->lt($this->namesDeadlineAt());
    }

    /** Why names cannot be added any more (null = still open). */
    public function namesClosedMessage(): ?string
    {
        if ($this->isCheckinStarted()) {
            return 'Check-in of this flight has opened; passengers cannot be added any more.';
        }

        return now()->lt($this->namesDeadlineAt()) ? null : 'The name list deadline for this allocation has passed.';
    }
}
