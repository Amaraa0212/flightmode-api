<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryTemplateRbd extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $attributes = ['is_closed' => false];

    protected function casts(): array
    {
        return ['is_closed' => 'boolean'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(InventoryTemplate::class, 'inventory_template_id');
    }

    public function rbd(): BelongsTo
    {
        return $this->belongsTo(Rbd::class);
    }
}
