<?php

namespace App\Models;

use Database\Factories\SizeGuideFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SizeGuide extends Model
{
    /** @use HasFactory<SizeGuideFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            // The ordered names of the chart's columns.
            'measurements' => 'array',
        ];
    }

    /**
     * The category this size guide is associated with.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * The measurement rows belonging to this size guide.
     */
    public function rows(): HasMany
    {
        return $this->hasMany(SizeGuideRow::class)->orderBy('sort_order')->orderBy('id');
    }
}
