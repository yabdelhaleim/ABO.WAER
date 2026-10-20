<?php

namespace App\Models;

use App\Enums\CouponType;
use Database\Factories\CouponFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    /** @use HasFactory<CouponFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => CouponType::class,
            'value' => 'float',
            'max_discount' => 'float',
            'min_order_total' => 'float',
            'total_usage_limit' => 'integer',
            'usage_per_customer_limit' => 'integer',
            'used_count' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * التحقق من الصلاحية الزمنية وشروط الاستخدام للكوبون
     */
    public function isValidForOrder(float $subtotal, ?Customer $customer = null): bool
    {
        if (! $this->is_active) {
            return false;
        }

        // فحص تاريخ البدء
        if ($this->starts_at && now()->lt($this->starts_at)) {
            return false;
        }

        // فحص تاريخ الانتهاء
        if ($this->expires_at && now()->gt($this->expires_at)) {
            return false;
        }

        // فحص الحد الأقصى الكلي للاستخدام
        if ($this->total_usage_limit !== null && $this->used_count >= $this->total_usage_limit) {
            return false;
        }

        // فحص الحد الأدنى للطلب
        if ($this->min_order_total !== null && $subtotal < $this->min_order_total) {
            return false;
        }

        // فحص حد الاستخدام للعميل المسجل
        if ($customer && $this->usage_per_customer_limit !== null) {
            $customerUsage = Order::where('customer_id', $customer->id)
                ->where('coupon_id', $this->id)
                ->count();
            if ($customerUsage >= $this->usage_per_customer_limit) {
                return false;
            }
        }

        return true;
    }

    /**
     * حساب مبلغ الخصم الدقيق بناءً على نوع الكوبون والسقف
     */
    public function calculateDiscount(float $subtotal, float $shippingFee = 0.00): float
    {
        if ($this->type === CouponType::Shipping) {
            return $shippingFee;
        }

        if ($this->type === CouponType::Fixed) {
            return min($this->value, $subtotal);
        }

        // نسبة مئوية Percentage
        $discount = ($subtotal * $this->value) / 100;

        if ($this->max_discount !== null && $this->max_discount > 0) {
            $discount = min($discount, $this->max_discount);
        }

        return round(min($discount, $subtotal), 2);
    }
}
