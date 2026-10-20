<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Order Created Event — Phase 8 · Step 3.
 *
 * Dispatched at the very end of OrderService::placeOrder, after the
 * transaction commits and the Order row is fully persisted along with
 * its items, status history and any coupon counter bump. The event
 * carries the freshly built Order model so that listeners (e.g.
 * NotifyAdminsOfNewOrder) and notifiers have stable access to
 * `order_number`, `grand_total`, `created_at`, etc.
 *
 * The event is plain (no broadcasting, no queue) by design: the only
 * listener that reacts to it is the admin notification fan-out, and
 * keeping it synchronous avoids splitting "the order exists" from
 * "an admin sees the notification" into two separate moments the
 * dashboard would otherwise race on.
 */
class OrderCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Order $order) {}
}
