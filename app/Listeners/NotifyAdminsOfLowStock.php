<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Models\Admin;
use App\Models\StoreSetting;
use App\Models\Variant;
use App\Notifications\LowStockAdminNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Tell the admins when an order empties the shelf.
 *
 * The inventory screen promises it: "Products at or below this appear
 * as Low stock and trigger notifications." Nothing triggered them — the
 * threshold was a number on a settings screen and a label in a list.
 *
 * This listener keeps the promise. When an order lands, the variants
 * it took are read back and compared against the store's threshold;
 * the ones at or below it ring the bell. The threshold and the switch
 * are the store's own, so a store that raises the threshold hears about
 * it later, and a store that turns the switch off hears nothing.
 */
class NotifyAdminsOfLowStock
{
    /**
     * Handle the event — the order has been written, the stock is off
     * the books, and whatever it emptied is now known.
     */
    public function handle(OrderCreated $event): void
    {
        if (! StoreSetting::get('notifications.lowStock', true)) {
            return;
        }

        $threshold = (int) StoreSetting::get('inventory.lowStockThreshold', 5);

        if ($threshold < 1) {
            return;
        }

        $variantIds = $event->order->items()
            ->whereNotNull('variant_id')
            ->pluck('variant_id')
            ->unique()
            ->values();

        if ($variantIds->isEmpty()) {
            return;
        }

        $low = Variant::query()
            ->with(['product', 'color', 'size'])
            ->whereIn('id', $variantIds)
            ->where('quantity', '<=', $threshold)
            ->get();

        if ($low->isEmpty()) {
            return;
        }

        $admins = Admin::query()->where('is_active', true)->get();

        if ($admins->isEmpty()) {
            return;
        }

        foreach ($low as $variant) {
            Notification::send($admins, new LowStockAdminNotification($variant, $threshold));
        }
    }
}
