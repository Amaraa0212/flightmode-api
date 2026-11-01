<?php

namespace App\Models;

use App\Enums\PassengerType;
use App\Enums\TripType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A tax line: the departure airport default for OW or RT fares (fare_id null), or an override saved on one fare. */
class FareTax extends Model
{
    protected $guarded = ['id'];

    /** Column defaults, so a freshly created model returns them too */
    protected $attributes = ['trip_type' => 'OW', 'currency' => 'MNT', 'refundable' => true];

    protected function casts(): array
    {
        return [
            'passenger_type' => PassengerType::class,
            'trip_type' => TripType::class,
            'amount' => 'decimal:2',
            'refundable' => 'boolean',
        ];
    }

    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }

    public function airport(): BelongsTo
    {
        return $this->belongsTo(Airport::class);
    }

    public function fare(): BelongsTo
    {
        return $this->belongsTo(Fare::class);
    }
}
