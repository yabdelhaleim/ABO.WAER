<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\Store\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AJAX surface for coupon validation during checkout.
 *
 * The browser calls this endpoint as the customer types a coupon code
 * (or hits the "apply" button) so the order summary can update without
 * a full form submit. The actual discount is still applied server-side
 * during order placement; this endpoint only previews it.
 */
class CouponController extends Controller
{
    public function __construct(protected CouponService $couponService) {}

    /**
     * Validate a coupon code against the current cart totals.
     *
     * Returns the discount and the new grand total as JSON so the
     * checkout summary can re-render immediately. Validation failures
     * (unknown code, expired, minimum not met, etc.) come back as a
     * standard 422 validation envelope the JS already handles.
     */
    public function validateCoupon(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'shipping_fee' => ['nullable', 'numeric', 'min:0'],
        ]);

        $result = $this->couponService->validateAndCalculate(
            code: $validated['code'],
            subtotal: (float) $validated['subtotal'],
            shippingFee: (float) ($validated['shipping_fee'] ?? 0.00),
            customer: $request->user('web'),
        );

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'code' => $result['coupon']->code,
            'discount' => $result['discount'],
            'new_total' => $result['new_total'],
        ]);
    }
}
