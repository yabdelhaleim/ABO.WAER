<?php

namespace App\Models;

use Database\Factories\CartItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    /** @use HasFactory<CartItemFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    /**
     * The customer who owns this line. NULL for guest carts, which are
     * identified by session_id instead.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The product this line was added from.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The exact color × size variant this line refers to.
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    /**
     * Total for this line. Priced off the variant's effective price, so a
     * variant carrying a discount_price is never billed at its list price.
     */
    public function getLineTotalAttribute(): float
    {
        return (float) ($this->variant?->final_price ?? 0) * $this->quantity;
    }

    /**
     * Whether the variant currently has enough stock to cover this line.
     */
    public function getIsAvailableAttribute(): bool
    {
        return $this->variant !== null && $this->variant->quantity >= $this->quantity;
    }
}
