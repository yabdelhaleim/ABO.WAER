<?php

namespace App\Models;

use Database\Factories\VariantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Variant extends Model
{
    /** @use HasFactory<VariantFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'cost' => 'float',
            'discount_price' => 'float',
            'quantity' => 'integer',
        ];
    }

    /**
     * Get the effective price for this variant (discount_price when set,
     * otherwise price).
     */
    public function getFinalPriceAttribute(): float
    {
        $discount = $this->discount_price;

        if ($discount !== null && (float) $discount > 0 && (float) $discount < (float) $this->price) {
            return (float) $discount;
        }

        return (float) $this->price;
    }

    /**
     * Whether this variant currently has stock.
     */
    public function getInStockAttribute(): bool
    {
        return $this->quantity > 0;
    }

    /**
     * The product this variant belongs to.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The color this variant references.
     */
    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class);
    }

    /**
     * The size this variant references.
     */
    public function size(): BelongsTo
    {
        return $this->belongsTo(Size::class);
    }
}
