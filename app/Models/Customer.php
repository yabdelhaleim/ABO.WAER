<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Customer extends Authenticatable
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, Notifiable;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_blocked' => 'boolean',
            'accepts_promotions' => 'boolean',
            'tags' => 'array',
        ];
    }

    /**
     * The customer's address book, ordered the way the account page shows it:
     * the default address first, then newest first.
     *
     * @return HasMany<CustomerAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class)
            ->latest('is_default')
            ->latest('id');
    }

    /**
     * The address pre-selected at checkout. AddressService keeps at most one
     * row per customer flagged as the default, so this is unambiguous.
     *
     * @return HasOne<CustomerAddress, $this>
     */
    public function defaultAddress(): HasOne
    {
        return $this->hasOne(CustomerAddress::class)->where('is_default', true);
    }

    /**
     * Every order this customer has placed. Used by coupon validation to
     * enforce per-customer usage limits.
     *
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * The customer's own cart lines. Guest carts carry a NULL customer_id
     * and are addressed by session_id instead, so they never surface here —
     * which is what CustomerSegmentationService's cart-abandoner segment
     * relies on to walk only over signed-in baskets.
     *
     * @return HasMany<CartItem, $this>
     */
    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }
}
