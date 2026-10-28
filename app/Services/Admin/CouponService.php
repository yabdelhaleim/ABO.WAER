<?php

namespace App\Services\Admin;

use App\Models\Coupon;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin coupons screen: reads, serialises and mutates coupons.
 *
 * public/admin/js/coupons.js walks a single COUPONS array injected into
 * the page via window.__COUPONS_DATA__. Every field it touches is read
 * without guards — a missing `cap` renders as the literal text
 * "undefined" in the summary line, a missing `usesMax` breaks the
 * progress bar math. getCouponsPayload() therefore emits the exact
 * shape that file walks, with `null` for the unused caps and a string
 * id so the DOM stays in sync with the DOM data-attributes the file
 * stamps on each row.
 */
class CouponService
{
    /**
     * Every coupon, newest first, in the shape coupons.js walks.
     *
     * The payload joins coupons with orders twice: once for the
     * redemptions counter (uses = used_count) and once for the
     * cumulative discount given (sum of orders.discount_amount).
     * The join for discountGiven is aggregated in SQL so this stays
     * O(1) queries over the coupon list regardless of order count.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCouponsPayload(): array
    {
        $coupons = Coupon::query()
            ->withCount('orders')
            ->latest('id')
            ->get();

        $discountsGiven = Order::query()
            ->whereNotNull('coupon_id')
            ->groupBy('coupon_id')
            ->selectRaw('coupon_id, SUM(discount_amount) as total_discount')
            ->pluck('total_discount', 'coupon_id');

        return $coupons->map(fn (Coupon $coupon): array => $this->serializeCoupon(
            $coupon,
            (float) ($discountsGiven[$coupon->id] ?? 0.00),
        ))->all();
    }

    /**
     * One coupon, in the shape coupons.js walks.
     *
     * The screen reads the coupon the server sends back, so a save
     * replaces the row with the row the database holds.
     *
     * @return array<string, mixed>
     */
    public function payload(Coupon $coupon): array
    {
        $discountGiven = (float) Order::query()
            ->where('coupon_id', $coupon->id)
            ->sum('discount_amount');

        return $this->serializeCoupon($coupon, $discountGiven);
    }

    /**
     * Serialise one coupon row into the object coupons.js walks.
     *
     * @return array<string, mixed>
     */
    protected function serializeCoupon(Coupon $coupon, float $discountGiven): array
    {
        return [
            // coupons.js matches coupons by strict === against a DOM
            // data-attribute, which is always a string.
            'id' => (string) $coupon->id,
            'code' => $coupon->code,
            'type' => $coupon->type->value ?? (string) $coupon->type,
            'value' => (float) $coupon->value,
            'cap' => $coupon->max_discount !== null ? (float) $coupon->max_discount : null,
            'minimum' => $coupon->min_order_total !== null ? (float) $coupon->min_order_total : null,
            'usesMax' => $coupon->total_usage_limit !== null ? (int) $coupon->total_usage_limit : null,
            'usesPerCustomer' => $coupon->usage_per_customer_limit !== null ? (int) $coupon->usage_per_customer_limit : null,
            'uses' => (int) $coupon->used_count,
            'startsAt' => $coupon->starts_at ? $coupon->starts_at->format('Y-m-d') : '',
            'endsAt' => $coupon->expires_at ? $coupon->expires_at->format('Y-m-d') : '',
            'active' => (bool) $coupon->is_active,
            'discountGiven' => (int) $discountGiven,
        ];
    }

    /**
     * Create a new coupon from validated form data.
     */
    public function create(array $data): Coupon
    {
        return DB::transaction(function () use ($data): Coupon {
            $data['code'] = strtoupper(trim($data['code']));
            $data['is_active'] = $data['is_active'] ?? true;

            return Coupon::create($data);
        });
    }

    /**
     * Update an existing coupon from validated form data.
     */
    public function update(Coupon $coupon, array $data): Coupon
    {
        return DB::transaction(function () use ($coupon, $data): Coupon {
            if (isset($data['code'])) {
                $data['code'] = strtoupper(trim($data['code']));
            }

            $coupon->update($data);

            return $coupon->fresh();
        });
    }

    /**
     * Flip the is_active flag and return the refreshed model.
     */
    public function toggleActive(Coupon $coupon): Coupon
    {
        $coupon->update(['is_active' => ! $coupon->is_active]);

        return $coupon->fresh();
    }

    /**
     * Delete a coupon, but only if it has never been redeemed.
     *
     * Coupons attached to a paid or shipped order are part of the
     * customer's invoice history — dropping the row would orphan the
     * order's coupon_code snapshot and break reporting. The admin can
     * still retire the coupon through the is_active toggle.
     *
     * @throws ValidationException
     */
    public function delete(Coupon $coupon): void
    {
        if ($coupon->orders()->exists() || (int) $coupon->used_count > 0) {
            throw ValidationException::withMessages([
                'coupon' => 'Cannot delete a coupon that has already been redeemed in customer orders. Please deactivate it instead.',
            ]);
        }

        $coupon->delete();
    }
}
