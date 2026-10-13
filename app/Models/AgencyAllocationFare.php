<?php

namespace App\Models;

use App\Enums\CabinClass;
use App\Enums\PassengerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A fare bucket of an allocation: either a published fare (fare_id) or a manual price. */
class AgencyAllocationFare extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cabin_class' => CabinClass::class,
            'fare_amount' => 'decimal:2',
            'fare_max_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'child_discount_percent' => 'decimal:2',
            'infant_discount_percent' => 'decimal:2',
        ];
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(AgencyAllocation::class, 'agency_allocation_id');
    }

    public function fare(): BelongsTo
    {
        return $this->belongsTo(Fare::class);
    }

    public function isManual(): bool
    {
        return $this->fare_id === null;
    }

    public function fareClass(): ?string
    {
        return $this->fare?->fare_class;
    }

    /** @return array{fare: string, tax: string} */
    public function priceFor(PassengerType $type): array
    {
        if ($this->fare) {
            return $this->fare->priceFor($type);
        }

        // manual fare: same CHD / INF discount rule as a published fare
        $base = (float) $this->fare_amount;
        $discount = match ($type) {
            PassengerType::Child => (float) $this->child_discount_percent,
            PassengerType::Infant => (float) $this->infant_discount_percent,
            PassengerType::Adult => 0.0,
        };

        return [
            'fare' => number_format($base * (100 - $discount) / 100, 2, '.', ''),
            'tax' => $type === PassengerType::Infant ? '0.00' : (string) ($this->tax_amount ?? '0.00'),
        ];
    }
}
