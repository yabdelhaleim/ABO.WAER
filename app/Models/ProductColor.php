<?php

namespace App\Models;

use Database\Factories\ProductColorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductColor extends Model
{
    /** @use HasFactory<ProductColorFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * The product that owns this pivot row.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The color that this pivot row references.
     */
    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class);
    }

    /**
     * Images that belong to this product × color combination.
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class);
    }
}
