<?php

namespace App\Models;

use App\Enums\BaggageType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One acceptance at the counter: `pieces` bags weighed together, one tag per piece. */
class CheckedBaggage extends Model
{
    protected $table = 'checked_baggage';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => BaggageType::class,
            'weight_kg' => 'decimal:1',
            'free_allowance_kg' => 'decimal:1',
            'price' => 'decimal:2',
            'excess_price' => 'decimal:2',
            'waived_amount' => 'decimal:2',
        ];
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(PassengerSegment::class, 'passenger_segment_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(BaggageRule::class, 'baggage_rule_id');
    }

    public function excessRule(): BelongsTo
    {
        return $this->belongsTo(BaggageRule::class, 'excess_baggage_rule_id');
    }

    public function tags(): HasMany
    {
        return $this->hasMany(BaggageTag::class)->orderBy('sequence');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
