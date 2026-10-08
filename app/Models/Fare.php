<?php

namespace App\Models;

use App\Enums\CabinClass;
use App\Enums\PassengerType;
use App\Enums\TripType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fare extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'trip_type' => TripType::class,
            'cabin_class' => CabinClass::class,
            'valid_from' => 'date',
            'valid_to' => 'date',
            'base_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }

    public function origin(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'origin_airport_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'destination_airport_id');
    }

    /** Fares usable for a flight: same route (or the reverse route for round-trip fares). */
    public function scopeForRoute(Builder $query, int $originId, int $destinationId): void
    {
        $query->where(function (Builder $q) use ($originId, $destinationId) {
            $q->where(fn (Builder $x) => $x->where('origin_airport_id', $originId)->where('destination_airport_id', $destinationId))
                ->orWhere(fn (Builder $x) => $x->where('origin_airport_id', $destinationId)
                    ->where('destination_airport_id', $originId)
                    ->where('trip_type', TripType::RoundTrip));
        });
    }

    /** @return array{fare: string, tax: string} */
    public function priceFor(PassengerType $type): array
    {
        $base = (float) $this->base_amount;

        return match ($type) {
            PassengerType::Adult => ['fare' => $this->money($base), 'tax' => $this->money((float) $this->tax_amount)],
            PassengerType::Child => [
                'fare' => $this->money($base * (100 - $this->child_discount_percent) / 100),
                'tax' => $this->money((float) $this->tax_amount),
            ],
            PassengerType::Infant => [
                'fare' => $this->money($base * (100 - $this->infant_discount_percent) / 100),
                'tax' => $this->money(0),
            ],
        };
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
