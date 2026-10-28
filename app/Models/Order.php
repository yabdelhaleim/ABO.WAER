<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'subtotal' => 'float',
            'shipping_fee' => 'float',
            'discount_amount' => 'float',
            'total' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Governorate, $this>
     */
    public function governorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class);
    }

    /**
     * @return BelongsTo<CancellationReason, $this>
     */
    public function cancellationReason(): BelongsTo
    {
        return $this->belongsTo(CancellationReason::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<OrderStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->latest('id');
    }

    /**
     * The order number the shop would hand out next.
     *
     * The number is the configured prefix, the year and the sequence,
     * and the sequence starts at 1001 for each year. The admin's order
     * prefix reaches the orders themselves this way, and the preview on
     * the settings screen and the number a customer receives come from
     * this very method.
     */
    public static function nextOrderNumber(): string
    {
        $year = date('Y');
        $lastOrder = static::whereYear('created_at', $year)->latest('id')->first();
        $nextId = $lastOrder ? ($lastOrder->id + 1) : 1001;

        return static::orderNumberPrefix().$year.'-'.sprintf('%04d', $nextId);
    }

    /**
     * Auto-generate the next order number for the current year.
     */
    public static function generateOrderNumber(): string
    {
        return static::nextOrderNumber();
    }

    /**
     * The text every order number starts with.
     */
    public static function orderNumberPrefix(): string
    {
        return (string) (StoreSetting::get('orders.prefix') ?: 'ABO-');
    }

    /**
     * Resolve an order from either its numeric id or its order number.
     *
     * public/admin/js/orders.js addresses orders by their order_number
     * (that string is the payload's `id`, and it is what the DOM hands
     * back on every click), while server-side callers and route() pass
     * the model — and therefore the primary key. Accepting both keeps a
     * single set of admin order URLs usable from either side.
     *
     * @param  string|null  $field  an explicit binding field, when the
     *                              route declares one
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field !== null) {
            return $this->where($field, $value)->first();
        }

        return $this->where('id', $value)
            ->orWhere('order_number', $value)
            ->first();
    }
}
