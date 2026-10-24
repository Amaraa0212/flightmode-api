<?php

namespace App\Models;

use App\Enums\FlightServiceType;
use App\Enums\FlightStatus;
use App\Enums\SegmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Flight extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => FlightStatus::class,
            'service_type' => FlightServiceType::class,
            'scheduled_departure_at' => 'datetime',
            'scheduled_arrival_at' => 'datetime',
            'estimated_departure_at' => 'datetime',
            'off_block_at' => 'datetime',
            'takeoff_at' => 'datetime',
            'estimated_arrival_at' => 'datetime',
            'is_published' => 'boolean',
            'is_series_override' => 'boolean',
            'online_checkin_enabled' => 'boolean',
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

    public function series(): BelongsTo
    {
        return $this->belongsTo(FlightSeries::class, 'flight_series_id');
    }

    public function origin(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'origin_airport_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'destination_airport_id');
    }

    public function seatAssignmentProfile(): BelongsTo
    {
        return $this->belongsTo(SeatAssignmentProfile::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(PassengerSegment::class);
    }

    public function seatBlocks(): HasMany
    {
        return $this->hasMany(FlightSeatBlock::class);
    }

    public function gateAgents(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'flight_gate_agents')->withTimestamps();
    }

    public function baggageRules(): BelongsToMany
    {
        return $this->belongsToMany(BaggageRule::class);
    }

    public function seatPrices(): BelongsToMany
    {
        return $this->belongsToMany(SeatPrice::class);
    }

    public function checkedBaggage(): HasMany
    {
        return $this->hasMany(CheckedBaggage::class);
    }

    public function outboundAllocations(): HasMany
    {
        return $this->hasMany(AgencyAllocation::class, 'outbound_flight_id');
    }

    public function returnAllocations(): HasMany
    {
        return $this->hasMany(AgencyAllocation::class, 'return_flight_id');
    }

    public function cabinInventory(): HasMany
    {
        return $this->hasMany(FlightCabinInventory::class);
    }

    public function rbdInventory(): HasMany
    {
        return $this->hasMany(FlightRbdInventory::class);
    }

    /** Segments that hold a place on the aircraft (not cancelled / waitlisted). */
    public function activeSegments(): HasMany
    {
        return $this->segments()->whereNotIn('status', [SegmentStatus::Cancelled, SegmentStatus::Waitlisted]);
    }

    /** Departure time used for operations: ETD if set, otherwise STD. */
    public function departureAt(): Carbon
    {
        return $this->estimated_departure_at ?? $this->scheduled_departure_at;
    }

    public function route(): string
    {
        return $this->origin?->iata_code.'-'.$this->destination?->iata_code;
    }

    public function isDomestic(): bool
    {
        return $this->origin && $this->destination && $this->origin->isSameCountryAs($this->destination);
    }

    public function isCharter(): bool
    {
        return $this->service_type === FlightServiceType::Charter;
    }

    public function isCheckinOpen(): bool
    {
        return $this->status === FlightStatus::CheckinOpen;
    }

    /** Operational date filter: matches ETD when set, otherwise STD. */
    /**
     * Seat-holding passenger counts (infants excluded, like FlightStatistics::total) as
     * booked_count / checked_in_count / boarded_count — FlightResource prints them as passenger_counts.
     */
    public function scopeWithPassengerCounts(Builder $query): void
    {
        $adults = fn ($q) => $q->whereHas('passenger', fn ($p) => $p->whereNull('infant_of_passenger_id'));
        $holding = [SegmentStatus::Booked->value, SegmentStatus::CheckedIn->value, SegmentStatus::Boarded->value, SegmentStatus::Flown->value];
        $checkedIn = [SegmentStatus::CheckedIn->value, SegmentStatus::Boarded->value, SegmentStatus::Flown->value];
        $boarded = [SegmentStatus::Boarded->value, SegmentStatus::Flown->value];

        $query->withCount([
            'segments as booked_count' => fn ($q) => $adults($q->whereIn('status', $holding)),
            'segments as checked_in_count' => fn ($q) => $adults($q->whereIn('status', $checkedIn)),
            'segments as boarded_count' => fn ($q) => $adults($q->whereIn('status', $boarded)),
        ]);
    }

    public function scopeDepartingOn(Builder $query, string $date): void
    {
        $query->where(function (Builder $q) use ($date) {
            $q->whereDate('estimated_departure_at', $date)
                ->orWhere(fn (Builder $x) => $x->whereNull('estimated_departure_at')->whereDate('scheduled_departure_at', $date));
        });
    }

    public function scopeUpcoming(Builder $query, int $graceHours = 24): void
    {
        $since = now()->subHours($graceHours);
        $query->where(fn (Builder $q) => $q->where('scheduled_departure_at', '>=', $since)->orWhere('estimated_departure_at', '>=', $since));
    }
}
