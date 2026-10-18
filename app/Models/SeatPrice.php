<?php

namespace App\Models;

use App\Enums\CabinClass;
use App\Enums\SeatType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeatPrice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cabin_class' => CabinClass::class,
            'seat_type' => SeatType::class,
            'price' => 'decimal:2',
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }

    public function appliesTo(AircraftSeat $seat): bool
    {
        return $this->cabin_class === $seat->cabin_class
            && ($this->seat_type === null || $this->seat_type === $seat->seat_type)
            && $this->coversRow($seat->seat_row);
    }

    /** rows "14-15" or "1-3,12"; empty = every row */
    public function coversRow(int $row): bool
    {
        if (blank($this->rows)) {
            return true;
        }
        foreach (explode(',', $this->rows) as $range) {
            [$from, $to] = array_pad(explode('-', trim($range)), 2, null);
            if ($row >= (int) $from && $row <= (int) ($to ?? $from)) {
                return true;
            }
        }

        return false;
    }

    /** More specific rules win when positions are equal: rows + seat type > one of them > cabin only. */
    public function specificity(): int
    {
        return (blank($this->rows) ? 0 : 2) + ($this->seat_type === null ? 0 : 1);
    }
}
