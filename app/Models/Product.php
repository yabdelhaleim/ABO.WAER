<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_archived' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    /**
     * Get the badge text for this product (e.g., 'NEW', 'BEST SELLER',
     * 'LIMITED'). Falls back to 'NEW' when the product is featured and
     * no explicit badge was set.
     */
    public function getDisplayBadgeAttribute(): ?string
    {
        if (! empty($this->badge)) {
            return $this->badge;
        }

        return $this->is_featured ? 'NEW' : null;
    }

    /**
     * The category that this product belongs to.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Colors available for this product (via the product_colors pivot).
     */
    public function colors(): BelongsToMany
    {
        return $this->belongsToMany(Color::class, 'product_colors')
            ->withTimestamps();
    }

    /**
     * Variants (color × size) for this product.
     */
    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class);
    }

    /**
     * All images attached to this product, through the product_colors pivot.
     */
    public function images(): HasManyThrough
    {
        return $this->hasManyThrough(
            ProductImage::class,
            ProductColor::class,
            'product_id',
            'product_color_id',
            'id',
            'id'
        );
    }

    /**
     * Pivot rows that link this product to its colors.
     */
    public function productColors(): HasMany
    {
        return $this->hasMany(ProductColor::class);
    }

    /**
     * The cheapest active variant price for this product.
     */
    public function getStartingPriceAttribute(): ?float
    {
        if (! $this->relationLoaded('variants')) {
            return null;
        }

        return (float) $this->variants->min(fn (Variant $v) => (float) $v->final_price) ?: null;
    }

    /**
     * The cheapest in-stock variant price for this product.
     */
    public function getMinPriceAttribute(): ?float
    {
        if (! $this->relationLoaded('variants')) {
            return null;
        }

        $prices = $this->variants
            ->filter(fn (Variant $v) => $v->in_stock)
            ->map(fn (Variant $v) => (float) $v->final_price);

        return $prices->min() ?: null;
    }

    /**
     * The most expensive variant price for this product.
     */
    public function getMaxPriceAttribute(): ?float
    {
        if (! $this->relationLoaded('variants')) {
            return null;
        }

        $prices = $this->variants->map(fn (Variant $v) => (float) $v->final_price);

        return $prices->max() ?: null;
    }

    /**
     * The first product image (through the first product_color row).
     */
    public function getPrimaryImageAttribute(): ?ProductImage
    {
        $pivot = $this->productColors->first();

        return $pivot?->images->first();
    }

    /**
     * Whether this product currently has a variant with stock > 0.
     */
    public function getIsInStockAttribute(): bool
    {
        if (! $this->relationLoaded('variants')) {
            return false;
        }

        return $this->variants->contains(fn (Variant $v) => $v->in_stock);
    }

    /**
     * Scope: only products visible to shoppers.
     */
    public function scopeActive($query)
    {
        return $query->where('is_archived', false);
    }
}
