<?php

namespace App\Models;

use App\Enums\BanType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class PassengerBan extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'ban_type' => BanType::class,
            'date_of_birth' => 'date',
            'valid_until' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Active on the given day for the given airline (airline-specific or global bans). */
    public function scopeInForce(Builder $query, int $airlineId, Carbon $on): void
    {
        $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('airline_id')->orWhere('airline_id', $airlineId))
            ->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $on->toDateString()));
    }
}
