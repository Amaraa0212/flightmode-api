<?php

namespace App\Services\Fare;

use App\Enums\PassengerType;
use App\Models\Fare;
use App\Models\FareTax;
use Illuminate\Database\Eloquent\Builder;

/**
 * Keeps the flat `fares` columns (cabin_class, fare_class, trip_type, tax_amount, CHD / INF %) in step with
 * the fare class, its discounts and the fare taxes, so code still reading them (allocations, reports, Chinggis) stays right.
 */
class FareService
{
    public function sync(Fare $fare): void
    {
        if (! $fare->fare_class_id) {
            return;
        }

        $fare->load('fareClass.rbd', 'fareClass.discounts');
        $class = $fare->fareClass;

        $fare->forceFill([
            'cabin_class' => $class->rbd->cabin_class,
            'fare_class' => $class->rbd->code,
            'trip_type' => $class->trip_type,
            'tax_amount' => $fare->taxLines(PassengerType::Adult)->sum('amount'),
            'child_discount_percent' => $class->discountFor(PassengerType::Child),
            'infant_discount_percent' => $class->discountFor(PassengerType::Infant),
        ])->saveQuietly();
    }

    /** Re-sync every fare-class fare matched by the query. */
    public function syncWhere(callable $scope): void
    {
        $query = Fare::query()->whereNotNull('fare_class_id');
        $scope($query);
        $query->each(fn (Fare $fare) => $this->sync($fare));
    }

    /** Fares whose price uses this tax line. */
    public function syncForTax(FareTax $tax): void
    {
        if ($tax->fare_id) {
            $this->syncWhere(fn (Builder $q) => $q->whereKey($tax->fare_id));

            return;
        }

        $column = $tax->bound === 'OUT' ? 'origin_airport_id' : 'destination_airport_id';
        $this->syncWhere(fn (Builder $q) => $q->where('airline_id', $tax->airline_id)
            ->when($tax->airport_id, fn (Builder $x) => $x->where($column, $tax->airport_id)));
    }
}
