<?php

namespace App\Http\Controllers;

use App\Notifications\TestWebPushNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PushSubscriptionController extends Controller
{
    /**
     * Store or update a push subscription for the authenticated user (Admin or Customer).
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
        ]);

        $user = auth('admin')->user() ?? auth('customer')->user() ?? auth('web')->user() ?? auth()->user();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $subscription = $user->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'],
            $validated['keys']['auth']
        );

        Log::info('WebPush subscription registered.', [
            'user_type' => get_class($user),
            'user_id' => $user->id,
            'subscription_id' => $subscription->id ?? null,
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Delete a push subscription when user disables it.
     */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string'],
        ]);

        $user = auth('admin')->user() ?? auth('customer')->user() ?? auth('web')->user() ?? auth()->user();

        if ($user) {
            $user->deletePushSubscription($validated['endpoint']);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Send an instant test push notification to the authenticated user.
     */
    public function sendTest(Request $request): JsonResponse
    {
        $user = auth('admin')->user() ?? auth('customer')->user() ?? auth('web')->user() ?? auth()->user();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $count = $user->pushSubscriptions()->count();
        if ($count === 0) {
            return response()->json([
                'success' => false,
                'message' => 'No active push subscriptions found for this account. Please enable notifications first.',
            ], 422);
        }

        try {
            $user->notify(new TestWebPushNotification);

            return response()->json([
                'success' => true,
                'message' => 'Test notification sent successfully!',
            ]);
        } catch (\Throwable $e) {
            Log::error('Test WebPush delivery failed: '.$e->getMessage(), [
                'user_type' => get_class($user),
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Delivery error: '.$e->getMessage(),
            ], 500);
        }
    }
}
