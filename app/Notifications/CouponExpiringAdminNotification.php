<?php

namespace App\Notifications;

use App\Models\Coupon;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Database-channel notification for "a coupon is about to expire".
 *
 * The bell's switch said the store would be told when a coupon was
 * about to expire or had expired, and nothing told it. The command
 * App\Console\Commands\ReportExpiringCoupons looks for the coupons
 * whose window is closing and sends this.
 *
 * The row lands under the bell's "Coupons" tab, next to the orders and
 * the stock alerts.
 */
class CouponExpiringAdminNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Coupon $coupon,
        public bool $expired = false,
    ) {}

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
        $when = $this->coupon->expires_at?->diffForHumans();

        $title = $this->expired
            ? 'Coupon '.$this->coupon->code.' has expired'
            : 'Coupon '.$this->coupon->code.' expires '.($when ?? 'soon');

        return [
            'id' => 'coupon-'.$this->coupon->id,
            'type' => 'coupons',
            'tone' => 'amber',
            'title' => $title,
            'time' => now()->diffForHumans(),
            'href' => route('admin.coupons.index'),
            'order_id' => null,
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
