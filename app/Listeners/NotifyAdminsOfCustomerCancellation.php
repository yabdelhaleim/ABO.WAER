<?php

namespace App\Listeners;

use App\Events\OrderCancelled;
use App\Models\Admin;
use App\Models\StoreSetting;
use App\Notifications\CustomerCancellationAdminNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Tell the admins when a customer cancels an order.
 *
 * The bell's switch used to promise a return or exchange request — a
 * request this shop cannot receive, because it has no returns desk.
 * What it does have is a customer who cancels an order of their own
 * accord, and that is worth a bell: the order the store was counting
 * on is not going to ship.
 *
 * An admin cancelling an order is not news to the admin who cancelled
 * it, and neither is the system cancelling an unpaid one, so only the
 * customer's own cancellations ring the bell.
 */
class NotifyAdminsOfCustomerCancellation
{
    /**
     * Handle the event — the order is cancelled and its stock is back.
     */
    public function handle(OrderCancelled $event): void
    {
        if (! $event->cancelledByCustomer()) {
            return;
        }

        if (! StoreSetting::get('notifications.customerCancellation', true)) {
            return;
        }

        $admins = Admin::query()->where('is_active', true)->get();

        if ($admins->isEmpty()) {
            return;
        }

        Notification::send(
            $admins,
            new CustomerCancellationAdminNotification($event->order),
        );
    }
}
