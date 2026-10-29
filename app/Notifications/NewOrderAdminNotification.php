<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

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
     * Database channel for dashboard topbar bell + WebPush for mobile alerts + Mail to admin email.
     *
     * @return array<int, string|class-string>
     */
    public function via(mixed $notifiable): array
    {
        return ['database', 'mail', WebPushChannel::class];
    }

    /**
     * Build the Mail message for admin email notification.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        $url = route('admin.orders.index', ['search' => $this->order->order_number]);

        return (new MailMessage)
            ->subject('🔥 New Order #'.$this->order->order_number.' — ABO Store')
            ->greeting('Hello, Admin!')
            ->line('A new order has just been placed on ABO Store.')
            ->line('Order Number: #'.$this->order->order_number)
            ->line('Customer Name: '.($this->order->customer_name ?? $this->order->shipping_name ?? 'Guest'))
            ->line('Total Amount: '.number_format($this->order->total).' EGP')
            ->action('View Order in Admin Panel', $url)
            ->line('Thank you for managing ABO Store.');
    }

    /**
     * Build the WebPush message for mobile / browser lock-screen notification.
     */
    public function toWebPush(mixed $notifiable, mixed $notification): WebPushMessage
    {
        $url = route('admin.orders.index', ['search' => $this->order->order_number]);

        return (new WebPushMessage)
            ->title('New Order #'.$this->order->order_number)
            ->icon(asset('images/abo-icon-192.png'))
            ->badge(asset('images/abo-badge-96.png'))
            ->body('Order Total: '.number_format($this->order->total).' EGP | Click to view order.')
            ->action('View Order', 'view_order')
            ->data(['url' => $url])
            ->tag('order-'.$this->order->id)
            ->vibrate([200, 100, 200]);
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
