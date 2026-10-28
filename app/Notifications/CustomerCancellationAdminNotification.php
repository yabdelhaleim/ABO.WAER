<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Database-channel notification for "a customer cancelled their order".
 *
 * The bell's switch used to say "A customer asks for a return or
 * exchange" — a request the shop cannot receive: there is no returns
 * desk and no exchange flow. What the shop does have is a customer who
 * cancels an order of their own accord, which is the same news the
 * switch was reaching for: an order the store was counting on is not
 * going to ship.
 *
 * The row lands under the bell's "Returns" tab, next to the orders the
 * store itself has sent back.
 */
class CustomerCancellationAdminNotification extends Notification
{
    use Queueable;

    public function __construct(public Order $order) {}

    /**
     * The bell reads the database channel.
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
        $reason = trim((string) ($this->order->cancellation_note ?? ''));

        $title = 'Order '.$this->order->order_number.' was cancelled by the customer';

        if ($reason !== '') {
            $title .= ' — '.$reason;
        }

        return [
            'id' => 'cancel-'.$this->order->id,
            'type' => 'returns',
            'tone' => 'amber',
            'title' => $title,
            'time' => $this->order->updated_at?->diffForHumans() ?? '',
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
