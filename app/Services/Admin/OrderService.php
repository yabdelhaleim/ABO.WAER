<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Events\OrderCancelled;
use App\Models\CancellationReason;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Variant;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Admin orders screen: reads, serialises and mutates orders.
 *
 * public/admin/js/orders.js is a strict consumer of one JSON array
 * injected into a <script type="application/json"> tag, and it reads
 * its fields without guards — a missing money field renders as
 * "EGP NaN", a missing status renders the literal text "undefined".
 * serializeOrder() therefore emits exactly the shape that file walks,
 * flat money keys included; it deliberately does not nest money under
 * a `totals` object.
 */
class OrderService
{
    /**
     * Date shape shared by order.createdAt, timeline[].at and notes[].at.
     *
     * orders.js compares these as raw strings against
     * `dateFrom + 'T00:00:00'` and sorts on them, so a timezone
     * suffix or fractional seconds would break both comparisons.
     */
    public const DATE_FORMAT = 'Y-m-d\TH:i:s';

    /**
     * Stored status -> the status bucket orders.js knows about.
     *
     * The front-end ships six buckets while the enum has seven cases.
     * `pending` is what the UI calls "new", and `confirmed` has no
     * bucket of its own so it folds into processing.
     *
     * @var array<string, string>
     */
    private const STATUS_KEYS = [
        'pending' => 'new',
        'confirmed' => 'processing',
        'processing' => 'processing',
        'shipped' => 'shipped',
        'delivered' => 'delivered',
        'cancelled' => 'cancelled',
        'returned' => 'returned',
    ];

    /**
     * Front-end status bucket -> the label orders.js displays and writes
     * back into a timeline entry. Kept in sync with STATUS_LABELS in
     * that file so a status applied in the browser and one read back
     * from the database read identically.
     *
     * @var array<string, string>
     */
    private const STATUS_LABELS = [
        'new' => 'New',
        'processing' => 'Processing',
        'shipped' => 'Shipped',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        'returned' => 'Returned',
    ];

    /**
     * Status bucket -> the stored statuses it holds.
     *
     * The inverse of STATUS_KEYS: the browser filters by bucket, the
     * database stores the enum. An export that filtered by bucket has
     * to expand it again, or "Processing" would drop the confirmed
     * orders that live in the same tab.
     *
     * @var array<string, array<int, string>>
     */
    private const STATUS_BUCKETS = [
        'new' => ['pending'],
        'processing' => ['confirmed', 'processing'],
        'shipped' => ['shipped'],
        'delivered' => ['delivered'],
        'cancelled' => ['cancelled'],
        'returned' => ['returned'],
    ];

    /**
     * Stored payment status -> the pill orders.js can style.
     *
     * orders.js has no `failed` entry, and its drawer interpolates the
     * map value straight into a class attribute, so an unmapped status
     * would render class="...undefined" plus the text "undefined".
     * A failed payment is therefore reported as still pending, which
     * is what the admin can act on.
     *
     * @var array<string, string>
     */
    private const PAYMENT_STATUS_KEYS = [
        'pending' => 'pending',
        'paid' => 'paid',
        'refunded' => 'refunded',
        'failed' => 'pending',
    ];

    /**
     * orders has no delivery-method column; every order currently ships
     * by the standard courier.
     */
    private const DEFAULT_DELIVERY_METHOD = 'Standard delivery';

    /**
     * Every order, newest first, in the shape orders.js expects.
     *
     * The payload is built from the snapshot columns on the order row
     * rather than its customer / governorate relations, so only the
     * two relations actually read (items and statusHistory) are
     * eager-loaded — that keeps this to three queries no matter how
     * many orders exist.
     *
     * When a customer id is given, only that customer's orders are
     * read: the screen reached with ?customer=<id> should not carry
     * the whole book to show a handful of rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOrdersPayload(?int $customerId = null): array
    {
        $query = Order::with(['items', 'statusHistory'])->latest('id');

        if ($customerId !== null) {
            $query->where('customer_id', $customerId);
        }

        return $query->get()
            ->map(fn (Order $order): array => $this->serializeOrder($order))
            ->all();
    }

    /**
     * The orders an export should carry, in the order the screen shows
     * them.
     *
     * The export is a second reading of the same book the screen shows.
     * The browser sends the filters it is currently displaying, the
     * database does the filtering, and the rows come back from the
     * orders table — not from the copy the page already carries, which
     * only holds the rows the page was opened with.
     *
     * @param  array{statuses?: array<int, string>, search?: string, payment?: string, governorate?: string, customer_id?: int|null, date_from?: string|null, date_to?: string|null, sort?: string, direction?: string}  $filters
     * @return Collection<int, Order>
     */
    public function exportOrders(array $filters): Collection
    {
        $query = Order::query()->with('items');

        $statuses = $filters['statuses'] ?? [];

        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $like = '%'.$search.'%';

            $query->where(function ($builder) use ($like): void {
                $builder->where('order_number', 'like', $like)
                    ->orWhere('customer_name', 'like', $like)
                    ->orWhere('customer_phone', 'like', $like);
            });
        }

        if (! empty($filters['payment'])) {
            $query->where('payment_method', $filters['payment']);
        }

        if (! empty($filters['governorate'])) {
            $query->where('governorate_name', $filters['governorate']);
        }

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', (int) $filters['customer_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        // The screen sorts by date or by total; the export follows the
        // same choice so the file reads like the list.
        $descending = ($filters['direction'] ?? 'desc') !== 'asc';

        if (($filters['sort'] ?? 'date') === 'total') {
            $query->orderBy('total', $descending ? 'desc' : 'asc');
        } else {
            $query->orderBy('created_at', $descending ? 'desc' : 'asc');
        }

        $query->orderBy('id', $descending ? 'desc' : 'asc');

        return $query->get();
    }

    /**
     * The stored statuses a status bucket holds.
     *
     * @return array<int, string>
     */
    public function statusesForBucket(string $bucket): array
    {
        return self::STATUS_BUCKETS[$bucket] ?? [];
    }

    /**
     * Move an order to a new status and append the move to its history.
     *
     * @param  string|null  $comment  shown in the drawer timeline; a
     *                                default is written when omitted
     */
    public function updateOrderStatus(
        Order $order,
        OrderStatus $newStatus,
        ?string $comment = null,
        ?int $adminId = null,
    ): Order {
        return DB::transaction(function () use ($order, $newStatus, $comment, $adminId): Order {
            $order->update(['status' => $newStatus]);

            $order->statusHistory()->create([
                'status' => $newStatus,
                'comment' => $comment ?: sprintf(
                    'Status changed to %s by admin.',
                    self::STATUS_LABELS[$this->statusKey($newStatus)],
                ),
                'changed_by_type' => 'Admin',
                'changed_by_id' => $adminId,
            ]);

            return $order;
        });
    }

    /**
     * Cancel an order, recording why, and put the sold units back on
     * the books.
     *
     * Variants are locked for the duration of the transaction so two
     * simultaneous cancellations cannot both hand back the same stock.
     * An order that is already cancelled returns untouched — otherwise
     * a double submit would inflate the variant quantities.
     *
     * This is the one place an order is cancelled and its stock returned,
     * shared with the store front: Store\OrderController reaches it through
     * OrderService::cancelOrderForCustomer, which adds the customer-only
     * "still Pending" precondition before delegating here. Both callers name
     * who cancelled and pass that actor's id so the order_status_history
     * audit trail stays accurate on both sides.
     */
    public function cancelOrder(
        Order $order,
        ?int $reasonId = null,
        ?string $customNote = null,
        ?int $changedById = null,
        string $changedByType = 'Admin',
    ): Order {
        $cancelled = DB::transaction(function () use ($order, $reasonId, $customNote, $changedById, $changedByType): Order {
            if ($order->status === OrderStatus::Cancelled) {
                return $order;
            }

            foreach ($order->items()->with('variant')->get() as $item) {
                /** @var OrderItem $item */
                if ($item->variant_id === null) {
                    continue;
                }

                Variant::whereKey($item->variant_id)
                    ->lockForUpdate()
                    ->first()?->increment('quantity', (int) $item->quantity);
            }

            $order->update([
                'status' => OrderStatus::Cancelled,
                'cancellation_reason_id' => $reasonId,
                'cancellation_note' => $customNote,
            ]);

            $reasonText = $reasonId !== null
                ? CancellationReason::find($reasonId)?->reason
                : null;

            $order->statusHistory()->create([
                'status' => OrderStatus::Cancelled,
                'comment' => 'Order cancelled by '.strtolower($changedByType).'. Reason: '.($reasonText ?: $customNote ?: 'Unspecified'),
                'changed_by_type' => $changedByType,
                'changed_by_id' => $changedById,
            ]);

            return $order;
        });

        // The bell hears about the cancellations it cares about. The
        // event is dispatched outside the transaction, so a listener
        // that reads the order reads the cancelled one — and a
        // listener that fails cannot roll the cancellation back.
        if ($cancelled->status === OrderStatus::Cancelled) {
            OrderCancelled::dispatch($cancelled, $changedByType, $changedById);
        }

        return $cancelled;
    }

    /**
     * Store the courier's waybill number on the order.
     */
    public function updateTracking(Order $order, ?string $trackingNumber): Order
    {
        $order->update(['tracking_number' => $trackingNumber]);

        return $order;
    }

    /**
     * Translate a status bucket posted by orders.js into a stored enum.
     *
     * The front-end offers "new", which the enum calls Pending; every
     * other bucket matches its enum case by value.
     */
    public function resolveStatus(string $statusKey): ?OrderStatus
    {
        return match ($statusKey) {
            'new' => OrderStatus::Pending,
            'processing' => OrderStatus::Processing,
            'shipped' => OrderStatus::Shipped,
            'delivered' => OrderStatus::Delivered,
            'cancelled' => OrderStatus::Cancelled,
            'returned' => OrderStatus::Returned,
            default => null,
        };
    }

    /**
     * Every status bucket orders.js offers, for validating a posted
     * status against.
     *
     * @return array<int, string>
     */
    public function availableStatusKeys(): array
    {
        return array_keys(self::STATUS_LABELS);
    }

    /**
     * The bucket orders.js uses for a given stored status.
     */
    public function statusKey(OrderStatus $status): string
    {
        return self::STATUS_KEYS[$status->value] ?? 'new';
    }

    /**
     * The label the screen prints for a stored status.
     *
     * An export is read next to the screen it was taken from, so it
     * carries the same English label rather than the bilingual one the
     * enum offers.
     */
    public function statusLabel(OrderStatus $status): string
    {
        return self::STATUS_LABELS[$this->statusKey($status)];
    }

    /**
     * Serialise one order row into the object orders.js walks.
     *
     * @return array<string, mixed>
     */
    protected function serializeOrder(Order $order): array
    {
        $createdAt = $this->formatDate($order->created_at);

        $items = $order->items->map(fn (OrderItem $item): array => [
            'name' => (string) $item->product_name,
            'color' => $item->color_name ?? '',
            'size' => $item->size_name ?? '',
            'qty' => (int) $item->quantity,
            'unitPrice' => round((float) $item->unit_price, 2),
            'lineTotal' => round((float) $item->total_price, 2),
        ])->values()->all();

        return [
            // orders.js matches orders by strict === against a DOM
            // attribute, which is always a string.
            'id' => (string) $order->order_number,
            'createdAt' => $createdAt,
            'customer' => [
                // The customer the order belongs to, so the orders
                // screen can be narrowed to one account — the link
                // from the customers screen leads here.
                'id' => (string) $order->customer_id,
                'name' => (string) $order->customer_name,
                // The drawer runs .replace() on the phone, so it must
                // never arrive as a number.
                'phone' => (string) $order->customer_phone,
                'email' => $order->customer_email ?? '',
                'address' => [
                    'governorate' => (string) $order->governorate_name,
                    'city' => (string) $order->area,
                    'street' => (string) $order->address,
                    'building' => '',
                    'notes' => $order->notes ?? '',
                ],
            ],
            'deliveryMethod' => self::DEFAULT_DELIVERY_METHOD,
            'items' => $items,
            'itemsCount' => (int) $order->items->sum('quantity'),
            // Flat, not nested under `totals` — the drawer reads these
            // keys straight off the order and prints them with
            // Intl.NumberFormat, which renders a missing value "NaN".
            'subtotal' => round((float) $order->subtotal, 2),
            'shipping' => round((float) $order->shipping_fee, 2),
            'discount' => round((float) $order->discount_amount, 2),
            // The code the customer actually used, snapshotted on the
            // order when it was placed. The drawer prints it beside the
            // discount, so it has to come from the row and not from a
            // constant.
            'coupon' => (string) ($order->coupon_code ?? ''),
            'total' => round((float) $order->total, 2),
            'payment' => [
                'method' => $order->payment_method ?? 'cod',
                'status' => self::PAYMENT_STATUS_KEYS[$order->payment_status->value] ?? 'pending',
            ],
            'status' => $this->statusKey($order->status),
            'timeline' => $this->serializeTimeline($order->statusHistory, $createdAt),
            'notes' => $order->notes
                ? [['at' => $createdAt, 'text' => (string) $order->notes]]
                : [],
        ];
    }

    /**
     * The drawer's timeline, newest first.
     *
     * Order::statusHistory() already orders by descending id, which is
     * the direction orders.js itself writes into when it unshifts a
     * status change onto the front of the list.
     *
     * @param  Collection<int, OrderStatusHistory>  $history
     * @return array<int, array<string, string>>
     */
    protected function serializeTimeline(Collection $history, string $createdAt): array
    {
        $timeline = $history->map(fn (OrderStatusHistory $entry): array => [
            'at' => $this->formatDate($entry->created_at),
            'label' => self::STATUS_LABELS[$this->statusKey($entry->status)],
        ])->values()->all();

        if ($timeline === []) {
            return [['at' => $createdAt, 'label' => self::STATUS_LABELS['new']]];
        }

        return $timeline;
    }

    /**
     * Format a timestamp the way orders.js expects to read it back.
     */
    protected function formatDate(?DateTimeInterface $date): string
    {
        return $date?->format(self::DATE_FORMAT) ?? now()->format(self::DATE_FORMAT);
    }
}
