<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An order was cancelled.
 *
 * Dispatched at the end of Admin\OrderService::cancelOrder, after the
 * transaction commits — the status is Cancelled, the stock is back on
 * the books and the history row is written.
 *
 * The event carries who did it, because the bell's answer depends on
 * it: a customer cancelling their own order is something the store
 * wants to know about, while an admin pressing the cancel button is
 * not news to the admin who pressed it.
 */
class OrderCancelled
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  string  $changedByType  'Customer', 'Admin' or 'System'.
     */
    public function __construct(
        public Order $order,
        public string $changedByType = 'Admin',
        public ?int $changedById = null,
    ) {}

    /**
     * Whether the customer cancelled this order themselves.
     */
    public function cancelledByCustomer(): bool
    {
        return strtolower($this->changedByType) === 'customer';
    }
}
