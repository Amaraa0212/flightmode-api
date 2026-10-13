<?php

namespace App\Models;

use App\Enums\CabinClass;
use App\Enums\PassengerType;
use App\Enums\TripType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

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

    public function fareClass(): BelongsTo
    {
        return $this->belongsTo(FareClass::class);
    }

    /** Tax lines saved on this fare; they override the airport default of the same tax code. */
    public function taxOverrides(): HasMany
    {
        return $this->hasMany(FareTax::class);
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

        // Fare-class fare: discount from fare_discounts, tax from fare_taxes
        if ($this->fare_class_id) {
            return [
                'fare' => $this->money($base * (100 - $this->fareClass->discountFor($type)) / 100),
                'tax' => $this->money((float) $this->taxLines($type)->sum('amount')),
            ];
        }

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

    /**
     * Taxes charged for a passenger type: the departure airport's OUT and the arrival airport's IN defaults,
     * with a line saved on this fare replacing the default of the same tax code.
     *
     * @return Collection<int, FareTax>
     */
    public function taxLines(PassengerType $type): Collection
    {
        $lines = FareTax::query()->with('taxCode')
            ->where('airline_id', $this->airline_id)
            ->where('passenger_type', $type)
            ->where(fn (Builder $q) => $q->where('fare_id', $this->id)
                ->orWhere(fn (Builder $d) => $d->whereNull('fare_id')->where(fn (Builder $a) => $a
                    ->where(fn (Builder $x) => $x->where('airport_id', $this->origin_airport_id)->where('bound', 'OUT'))
                    ->orWhere(fn (Builder $x) => $x->where('airport_id', $this->destination_airport_id)->where('bound', 'IN')))))
            ->get();

        $overridden = $lines->whereNotNull('fare_id')->pluck('tax_code_id')->all();

        return $lines->reject(fn (FareTax $t) => $t->fare_id === null && in_array($t->tax_code_id, $overridden, true))->values();
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
