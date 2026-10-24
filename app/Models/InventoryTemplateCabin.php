<?php

namespace App\Models;

use App\Enums\CabinClass;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryTemplateCabin extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cabin_class' => CabinClass::class];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(InventoryTemplate::class, 'inventory_template_id');
    }
}
