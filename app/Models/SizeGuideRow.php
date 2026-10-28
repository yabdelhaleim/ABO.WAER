<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SizeGuideRow extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            // The row's measurements, keyed by the guide's column
            // names: {"Chest": 56, "Length": 72}.
            'measurements' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The size guide that this row belongs to.
     */
    public function sizeGuide(): BelongsTo
    {
        return $this->belongsTo(SizeGuide::class);
    }
}
