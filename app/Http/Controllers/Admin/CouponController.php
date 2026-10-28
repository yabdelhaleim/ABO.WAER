<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CouponRequest;
use App\Models\Coupon;
use App\Services\Admin\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class CouponController extends Controller
{
    public function __construct(protected CouponService $couponService) {}

    /**
     * Render the admin coupons index with the JSON payload injected
     * for public/admin/js/coupons.js to hydrate on load.
     */
    public function index(): View
    {
        $couponsPayload = $this->couponService->getCouponsPayload();
        $couponsJson = json_encode($couponsPayload, JSON_UNESCAPED_UNICODE);

        // The endpoints the screen talks to. The placeholders are
        // filled in by coupons.js with the coupon id.
        $routesJson = json_encode([
            'store' => route('admin.coupons.store'),
            'update' => route('admin.coupons.update', ['coupon' => '__COUPON__']),
            'toggle' => route('admin.coupons.toggle', ['coupon' => '__COUPON__']),
            'destroy' => route('admin.coupons.destroy', ['coupon' => '__COUPON__']),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return view('admin.coupons.index', compact('couponsJson', 'routesJson'));
    }

    /**
     * Persist a new coupon and return the saved record.
     */
    public function store(CouponRequest $request): JsonResponse
    {
        $coupon = $this->couponService->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Coupon created successfully.',
            'coupon' => $this->couponService->payload($coupon),
        ], 201);
    }

    /**
     * Update an existing coupon and return the refreshed record.
     */
    public function update(CouponRequest $request, Coupon $coupon): JsonResponse
    {
        $updated = $this->couponService->update($coupon, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Coupon updated successfully.',
            'coupon' => $this->couponService->payload($updated),
        ]);
    }

    /**
     * Flip the is_active flag via the dedicated AJAX endpoint.
     */
    public function toggle(Coupon $coupon): JsonResponse
    {
        $updated = $this->couponService->toggleActive($coupon);

        return response()->json([
            'success' => true,
            'message' => 'Coupon status updated.',
            'is_active' => (bool) $updated->is_active,
            'coupon' => $this->couponService->payload($updated),
        ]);
    }

    /**
     * Delete a coupon. Throws a 422 validation error if it has already
     * been redeemed in a customer order.
     */
    public function destroy(Coupon $coupon): JsonResponse
    {
        $this->couponService->delete($coupon);

        return response()->json([
            'success' => true,
            'message' => 'Coupon deleted successfully.',
        ]);
    }
}
