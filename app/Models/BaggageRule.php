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

    /**
     * A rule always belongs to a group: one created on its own (seeders, older code) gets a group of its own, and a
     * group left with no rules goes with its last one.
     */
    protected static function booted(): void
    {
        static::creating(function (BaggageRule $rule) {
            if ($rule->baggage_rule_group_id) {
                return;
            }
            $rule->baggage_rule_group_id = BaggageRuleGroup::create([
                'airline_id' => $rule->airline_id,
                'name' => $rule->name,
                'type' => $rule->type,
                'cabin_class' => $rule->cabin_class,
                'max_quantity' => $rule->max_quantity,
                'currency' => $rule->currency ?? 'MNT',
                'is_active' => $rule->is_active ?? true,
            ])->id;
        });
        static::deleted(function (BaggageRule $rule) {
            if ($rule->baggage_rule_group_id && ! static::query()->where('baggage_rule_group_id', $rule->baggage_rule_group_id)->exists()) {
                BaggageRuleGroup::query()->whereKey($rule->baggage_rule_group_id)->delete();
            }
        });
    }

    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(BaggageRuleGroup::class, 'baggage_rule_group_id');
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
            // prepaid pieces only; a PC rule's extra piece is charged as excess, a KG rule counts kg, not pieces
            $this->isSsr(self::SSR_PREPAID) => $prepaidPieces,
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
