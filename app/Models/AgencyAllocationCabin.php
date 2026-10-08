<?php

namespace App\Models;

use App\Enums\CabinClass;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgencyAllocationCabin extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cabin_class' => CabinClass::class];
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(AgencyAllocation::class, 'agency_allocation_id');
    }
}
