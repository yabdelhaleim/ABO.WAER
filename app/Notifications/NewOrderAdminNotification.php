<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Database-channel notification for "a new order was placed".
 *
 * The notification is delivered solely through the `database` channel
 * because the dashboard bell (admin/partials/topbar.blade.php) reads
 * the bell rows straight off the `notifications` table. The payload
 * mirrors the shape public/admin/js/admin.js expects so the JS can
 * render the row without re-querying for the order.
 *
 * Fields:
 *  - id       : a stable string ("order-{id}") that doubles as the key
 *               used by the front-end list (it never collides with a
 *               future campaign or stock notification that uses an
 *               integer-ish id).
 *  - type     : the tab the bell filter switches to ("orders").
 *  - tone     : the dot accent the bell uses ("lime" — orders are
 *               positive/conversion events).
 *  - title    : the human-readable row text.
 *  - time     : a "X minutes ago" relative timestamp computed once at
 *               notification time so subsequent re-reads do not drift.
 *  - href     : the deep-link into the orders page, pre-filtered by
 *               the new order's number so the admin lands on it.
 *  - order_id : the actual order PK — handy for backend lookups and
 *               for tests.
 */
class NewOrderAdminNotification extends Notification
{
    use Queueable;

    public function __construct(public Order $order) {}

    /**
     * Only the database channel. The "mark as read" surface is the
     * notifications table; nothing about this notification needs an
     * email or push payload yet.
     *
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'id' => 'order-'.$this->order->id,
            'type' => 'orders',
            'tone' => 'lime',
            'title' => "New order #{$this->order->order_number} — "
                .number_format($this->order->total).' EGP',
            'time' => $this->order->created_at->diffForHumans(),
            'href' => route('admin.orders.index', ['search' => $this->order->order_number]),
            'order_id' => $this->order->id,
        ];
    }

    /**
     * The database-channel payload — identical to toArray() so both
     * reads (database row + array cast) return the same shape.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(mixed $notifiable): array
    {
        return $this->toArray($notifiable);
    }
}
