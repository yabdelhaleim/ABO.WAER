<?php

namespace App\Services\Store;

use App\Enums\CouponType;
use App\Models\Coupon;
use App\Models\Customer;
use Illuminate\Validation\ValidationException;

/**
 * Store-front coupon validation and discount arithmetic.
 *
 * The service answers two questions for the checkout funnel:
 *  1. Is the code the customer typed actually usable for this cart
 *     (and this customer, if signed in)? Every guard that says "no"
 *     is raised as a ValidationException so the controller can surface
 *     it as a normal 422 the AJAX layer already knows how to render.
 *  2. If it is, what is the discount figure for this subtotal /
 *     shipping fee pair? The arithmetic is delegated to Coupon::calculateDiscount
 *     so percentage / fixed / shipping all share one source of truth.
 *
 * Counter increments (used_count) deliberately live in OrderService — the
 * counter is only correct when the order actually persists, and that happens
 * inside the same DB transaction as the rest of the checkout write.
 */
class CouponService
{
    /**
     * التحقق من صلاحية الكوبون وحساب قيمة الخصم بدقة
     *
     * @return array{coupon: Coupon, discount: float, new_total: float, message: string}
     *
     * @throws ValidationException
     */
    public function validateAndCalculate(string $code, float $subtotal, float $shippingFee = 0.00, ?Customer $customer = null): array
    {
        $cleanCode = strtoupper(trim($code));

        $coupon = Coupon::where('code', $cleanCode)->first();

        if (! $coupon) {
            throw ValidationException::withMessages([
                'coupon' => 'Coupon code is invalid or does not exist.',
            ]);
        }

        if (! $coupon->is_active) {
            throw ValidationException::withMessages([
                'coupon' => 'This coupon is no longer active.',
            ]);
        }

        // فحص التواريخ
        if ($coupon->starts_at && now()->lt($coupon->starts_at)) {
            throw ValidationException::withMessages([
                'coupon' => 'This coupon has not started yet.',
            ]);
        }

        if ($coupon->expires_at && now()->gt($coupon->expires_at)) {
            throw ValidationException::withMessages([
                'coupon' => 'This coupon has expired.',
            ]);
        }

        // فحص أقصى استخدام كلي
        if ($coupon->total_usage_limit !== null && $coupon->used_count >= $coupon->total_usage_limit) {
            throw ValidationException::withMessages([
                'coupon' => 'This coupon has reached its maximum redemption limit.',
            ]);
        }

        // فحص الحد الأدنى لقيمة السلة
        if ($coupon->min_order_total !== null && $subtotal < $coupon->min_order_total) {
            throw ValidationException::withMessages([
                'coupon' => "Minimum order subtotal of {$coupon->min_order_total} EGP is required for this coupon.",
            ]);
        }

        // فحص عدد مرات الاستخدام للعميل المسجل
        if ($customer && $coupon->usage_per_customer_limit !== null) {
            $customerUsage = $customer->orders()->where('coupon_id', $coupon->id)->count();

            if ($customerUsage >= $coupon->usage_per_customer_limit) {
                throw ValidationException::withMessages([
                    'coupon' => 'You have already used this coupon the maximum allowed number of times.',
                ]);
            }
        }

        // حساب الخصم الفعلي
        $discount = $coupon->calculateDiscount($subtotal, $shippingFee);
        $newTotal = max(0, ($subtotal + $shippingFee) - $discount);

        $description = match ($coupon->type) {
            CouponType::Percentage => "{$coupon->value}% discount applied".($coupon->max_discount ? " (up to {$coupon->max_discount} EGP)" : ''),
            CouponType::Fixed => "{$coupon->value} EGP discount applied",
            CouponType::Shipping => 'Free shipping discount applied',
        };

        return [
            'coupon' => $coupon,
            'discount' => $discount,
            'new_total' => $newTotal,
            'message' => $description,
        ];
    }
}
