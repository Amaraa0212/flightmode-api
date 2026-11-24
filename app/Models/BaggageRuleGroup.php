<?php

namespace App\Models;

use App\Enums\BaggageRuleType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bag's common details (name, limit by, cabin, max pieces, active) with one baggage rule per SSR
 * (FBAG / XBAG / EBAG), each with its own kg, pieces and price. The group's details are copied onto its rules.
 */
class BaggageRuleGroup extends Model
{
    /** SSR codes a group's rows may use, each at most once per group */
    public const SSR_CODES = [BaggageRule::SSR_FREE, BaggageRule::SSR_PREPAID, BaggageRule::SSR_EXCESS];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => BaggageRuleType::class,
            'is_active' => 'boolean',
        ];
    }

    public function airline(): BelongsTo
    {
        return $this->belongsTo(Airline::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(BaggageRule::class)
            ->orderByRaw("CASE ssr_code WHEN 'FBAG' THEN 0 WHEN 'XBAG' THEN 1 WHEN 'EBAG' THEN 2 ELSE 3 END")->orderBy('id');
    }

    /** The group's details as its rules carry them */
    public function sharedAttributes(): array
    {
        return [
            'airline_id' => $this->airline_id,
            'type' => $this->type,
            'cabin_class' => $this->cabin_class,
            'max_quantity' => $this->max_quantity,
            'currency' => $this->currency,
            'is_active' => $this->is_active,
        ];
    }
}
