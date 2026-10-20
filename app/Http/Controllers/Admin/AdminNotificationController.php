<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Admin\AdminNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Admin bell JSON endpoints — Phase 8 · Step 3.
 *
 * Three endpoints wire the bell in resources/views/admin/partials/topbar.blade.php:
 *
 *   GET  /admin/notifications                   → list + unread count
 *                                                  (admin.js polls this every 30s)
 *   PATCH /admin/notifications/{id}/read       → mark one notification as read
 *                                                  (fired when an admin clicks a row)
 *   POST /admin/notifications/mark-all-read    → wipe the unread counter
 *                                                  (fired by "Mark all read")
 *
 * Every action is reached through the admin route group, so by the
 * time control reaches `Auth::guard('admin')->user()` a non-null
 * Admin instance is guaranteed. The control flow therefore skips
 * the defensive `?->` checks the campaign controllers use; the
 * `admin()` helper below keeps the cast in one place so the route
 * group's `admin` middleware is the single source of auth.
 */
class AdminNotificationController extends Controller
{
    public function __construct(protected AdminNotificationService $notifications) {}

    /**
     * The bell payload — recent notifications and the unread counter.
     *
     * The response shape is the contract admin.js polls:
     *   {
     *     unread_count: int,
     *     notifications: array<int, array<string, mixed>>
     *   }
     *
     * The `no-store` header keeps the bell fresh — admin.js already
     * fetches with cache disabled, but a stale intermediate proxy
     * would still land on the same URL every 30s without it.
     */
    public function index(): JsonResponse
    {
        $admin = $this->admin();

        return response()->json([
            'unread_count' => $this->notifications->unreadCountFor($admin),
            'notifications' => $this->notifications->listForAdmin($admin)->all(),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * Mark one notification as read.
     *
     * `{id}` is the UUID assigned to the `notifications.id` column
     * (Laravel's `Str::uuid()` is the default for the database
     * channel). A non-existent or already-read id is accepted and
     * answered with the current unread count so the bell can refresh
     * itself idempotently.
     */
    public function markAsRead(string $id): JsonResponse
    {
        $admin = $this->admin();

        $this->notifications->markAsRead($admin, $id);

        return response()->json([
            'success' => true,
            'unread_count' => $this->notifications->unreadCountFor($admin),
        ]);
    }

    /**
     * Mark every notification belonging to the admin as read.
     */
    public function markAllAsRead(): JsonResponse
    {
        $admin = $this->admin();

        $updated = $this->notifications->markAllAsRead($admin);

        return response()->json([
            'success' => true,
            'updated' => (int) $updated,
            'unread_count' => 0,
        ]);
    }

    /**
     * Resolve the admin from the active guard.
     *
     * Kept as a tiny helper so every action reads the same user
     * consistently — and so an eventual "impersonation" guard
     * configuration has a single line to swap.
     */
    protected function admin(): Admin
    {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        return $admin;
    }
}
