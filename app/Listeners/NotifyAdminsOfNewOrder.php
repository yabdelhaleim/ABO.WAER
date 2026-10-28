<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Models\Admin;
use App\Models\StoreSetting;
use App\Notifications\NewOrderAdminNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Notify every active admin that a new order landed.
 *
 * The listener is bound to App\Events\OrderCreated via
 * App\Providers\EventServiceProvider (auto-discovered; the
 * `handle()` method's type-hint on the event is what Laravel's
 * dispatcher uses to register the subscription).
 *
 * It deliberately works against the database directly rather than
 * going through Notification::route() so a deactivated admin (whose
 * row remained in the table) is skipped — only `is_active = true`
 * accounts get the notification.
 *
 * The store's bell switch decides whether a new order rings the bell
 * at all: "New order" is the one notification a busy shop may want to
 * turn off.
 */
class NotifyAdminsOfNewOrder
{
    /**
     * Handle the event — fan out the database notification to every
     * admin the store currently trusts to act on a new order.
     */
    public function handle(OrderCreated $event): void
    {
        if (! StoreSetting::get('notifications.newOrder', true)) {
            return;
        }

        $admins = Admin::query()->where('is_active', true)->with('pushSubscriptions')->get();

        if ($admins->isEmpty()) {
            return;
        }

        Notification::send(
            $admins,
            new NewOrderAdminNotification($event->order),
        );
    }
}
