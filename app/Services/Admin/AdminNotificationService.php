<?php

namespace App\Services\Admin;

use App\Models\Admin;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * Admin bell data layer — Phase 8 · Step 3.
 *
 * Owns every read/write against the `notifications` table on behalf of
 * an authenticated admin. The bell in admin/partials/topbar.blade.php
 * does not talk to Eloquent directly: the controller in
 * App\Http\Controllers\Admin\AdminNotificationController delegates to
 * this service, and that one renders the JSON the JS in
 * public/admin/js/admin.js polls.
 *
 * Shape contract with admin.js (single source of truth — keep in sync):
 *   {
 *     id:       string,    // stable id used as data-attribute on the row
 *     type:     string,    // bell-filter tab key (orders | stock | returns)
 *     tone:     string,    // CSS modifier on the dot (lime | coral | …)
 *     title:    string,    // headline shown in the list
 *     time:     string,    // "X minutes ago" relative label
 *     href:     string,    // url the row navigates to on click
 *     unread:   bool,      // computed from `read_at` at serialization time
 *     order_id: ?int,      // raw order PK for non-order notifications
 *   }
 *
 * The service deliberately maps the underlying DatabaseNotification
 * row (carrying serialized JSON in `data`) into that flat shape; the
 * raw Eloquent model is never returned to the JS so a future change
 * to how the notification's payload is stored does not bleed into
 * the front-end contract.
 */
class AdminNotificationService
{
    /**
     * How many recent rows the bell shows the first time it opens.
     *
     * The full notifications history is not paged in the bell UI today,
     * so this cap both controls DOM weight (the dropdown renders
     * `bell-row` per row) and bounds initial paint time. A future
     * "View all" screen would read through the same service.
     */
    private const BELL_LIST_LIMIT = 20;

    /**
     * The most recent notifications targeted at the given admin.
     *
     * The rows are ordered newest-first so the bell's visual order
     * matches the order the JS will read them after a polling refresh.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function listForAdmin(Admin $admin, int $limit = self::BELL_LIST_LIMIT): Collection
    {
        return $admin->notifications()
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (DatabaseNotification $n): array => $this->serialize($n))
            ->values();
    }

    /**
     * The number of unread notifications the bell badge should display.
     */
    public function unreadCountFor(Admin $admin): int
    {
        return $admin->unreadNotifications()->count();
    }

    /**
     * Mark a single notification as read, if it belongs to this admin.
     *
     * The morph `notifiable_id` filter means a notification belonging
     * to another admin cannot be flipped by this caller — `markAsRead`
     * silently returns false in that case rather than 404/403, matching
     * the existing admin route conventions ("answer redirect for
     * cross-tenant probes").
     */
    public function markAsRead(Admin $admin, string $notificationId): bool
    {
        $notification = $admin->notifications()
            ->where('id', $notificationId)
            ->first();

        if ($notification === null) {
            return false;
        }

        if ($notification->read_at !== null) {
            return true;
        }

        $notification->markAsRead();

        return true;
    }

    /**
     * Mark every notification belonging to this admin as read.
     *
     * Returns the number of rows that flipped from unread to read so
     * the JSON response can echo it back to the bell for a Toast.
     */
    public function markAllAsRead(Admin $admin): int
    {
        return $admin->unreadNotifications()->update(['read_at' => now()]);
    }

    /**
     * Map a single notification row to the bell-shape contract.
     *
     * `data` is stored as a serialized JSON string — the array cast
     * happens here so the controller and JS deal with a clean object.
     *
     * @return array<string, mixed>
     */
    protected function serialize(DatabaseNotification $n): array
    {
        $data = $n->data ?? [];

        return [
            'id' => (string) ($data['id'] ?? $n->id),
            'type' => (string) ($data['type'] ?? 'orders'),
            'tone' => (string) ($data['tone'] ?? 'gray'),
            'title' => (string) ($data['title'] ?? 'Notification'),
            'time' => (string) ($data['time'] ?? ($n->created_at?->diffForHumans() ?? '')),
            'href' => (string) ($data['href'] ?? '#'),
            'unread' => $n->read_at === null,
            // back-pointer to the order row if the notification has one.
            // omitting it from the JSON when there is no order keeps the
            // payload tidy for future stock / return notifications.
            'order_id' => $data['order_id'] ?? null,
        ];
    }
}
