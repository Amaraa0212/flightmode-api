<?php

namespace App\Models;

use App\Enums\CabinClass;
use App\Enums\CheckinChannel;
use App\Enums\SegmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One passenger on one flight: ticket, seat, check-in and boarding state. */
class PassengerSegment extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cabin_class' => CabinClass::class,
            'status' => SegmentStatus::class,
            'checkin_channel' => CheckinChannel::class,
            'is_go_show' => 'boolean',
            'fare_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'agency_sale_amount' => 'decimal:2',
            'checked_in_at' => 'datetime',
            'boarding_pass_printed_at' => 'datetime',
            'boarded_at' => 'datetime',
        ];
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(Passenger::class);
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    public function fare(): BelongsTo
    {
        return $this->belongsTo(Fare::class);
    }

    public function allocationFare(): BelongsTo
    {
        return $this->belongsTo(AgencyAllocationFare::class, 'agency_allocation_fare_id');
    }

    public function checkinGroup(): BelongsTo
    {
        return $this->belongsTo(CheckinGroup::class);
    }

    public function ssrCodes(): BelongsToMany
    {
        return $this->belongsToMany(SsrCode::class, 'passenger_segment_ssr')->withPivot('remark')->withTimestamps();
    }

    public function checkedBaggage(): HasMany
    {
        return $this->hasMany(CheckedBaggage::class);
    }

    public function prepaidBaggage(): HasMany
    {
        return $this->hasMany(PrepaidBaggage::class);
    }

    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function isCheckedIn(): bool
    {
        return in_array($this->status, [SegmentStatus::CheckedIn, SegmentStatus::Boarded, SegmentStatus::Flown], true);
    }

    public function totalAmount(): string
    {
        return number_format((float) $this->fare_amount + (float) $this->tax_amount, 2, '.', '');
    }

    /** Segments that occupy a place (everything except cancelled / waitlisted). */
    public function scopeHoldingSpace(Builder $query): void
    {
        $query->whereNotIn('status', [SegmentStatus::Cancelled->value, SegmentStatus::Waitlisted->value]);
    }

    public function scopeCheckedIn(Builder $query): void
    {
        $query->whereIn('status', [SegmentStatus::CheckedIn->value, SegmentStatus::Boarded->value, SegmentStatus::Flown->value]);
    }
}
