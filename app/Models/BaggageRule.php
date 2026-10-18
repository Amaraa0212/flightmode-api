<?php

namespace App\Models;

use App\Enums\BaggageRuleType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A baggage product. `ssr_code` has special meaning at the counter:
 *  - FBAG: free allowance (no piece limit)
 *  - XBAG: prepaid extra bag (limited by prepaid_baggage pieces)
 *  - EBAG: excess baggage (no piece limit)
 *  - otherwise: piece / combined rules are limited by max_quantity
 */
class BaggageRule extends Model
{
    public const SSR_FREE = 'FBAG';

    public const SSR_PREPAID = 'XBAG';

    public const SSR_EXCESS = 'EBAG';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => BaggageRuleType::class,
            'weight_kg' => 'decimal:1',
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function isSsr(string $code): bool
    {
        return strcasecmp((string) $this->ssr_code, $code) === 0;
    }

    /** Max pieces a passenger may check under this rule, null = unlimited. */
    public function pieceLimit(int $prepaidPieces = 0): ?int
    {
        return match (true) {
            $this->isSsr(self::SSR_FREE), $this->isSsr(self::SSR_EXCESS) => null,
            $this->isSsr(self::SSR_PREPAID) => $prepaidPieces,
            in_array($this->type, [BaggageRuleType::Piece, BaggageRuleType::Combined], true) => $this->max_quantity,
            default => null,
        };
    }

    public function isCombined(): bool
    {
        return $this->type === BaggageRuleType::Combined;
    }

    /** Free kg one passenger gets under a combined rule (shared by all their bags of this rule). */
    public function freeWeightPerPassenger(): float
    {
        return (float) $this->weight_kg * max(1, (int) $this->pieces);
    }
}
