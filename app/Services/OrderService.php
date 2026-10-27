<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Events\OrderCreated;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Governorate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ShippingSetting;
use App\Models\Variant;
use App\Services\Admin\OrderService as AdminOrderService;
use App\Services\Store\CouponService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Order placement: stock check, pricing, persistence, cart cleanup.
 *
 * Every read of stock and every write to orders / order_items /
 * order_status_history / cart_items happens inside a single DB
 * transaction. A variant row is locked for the duration of the
 * transaction so two simultaneous checkouts for the last unit can
 * never both succeed.
 *
 * The checkout form posts one line per item in the cart. Prices and
 * stock figures are always read from the database — the client cannot
 * influence what is charged or how much is deducted.
 *
 * The same class also owns the customer-facing read model (the "My
 * Orders" payload) and customer-initiated cancellation, so the store
 * front and the back office agree on what a status means and on the
 * single place stock is deducted from and returned to.
 */
class OrderService
{
    /**
     * The timeline rows the "My Orders" stepper renders, in order.
     *
     * `step` is the order step at (or beyond) which the row is marked done,
     * and it is deliberately offset from the row index: the timeline carries
     * one row ("Payment confirmed") more than the stepper has labels, which
     * is why row 1 completes at step 1 while row 2 completes at step 1 too.
     *
     * `status` names the order_status_history row that supplies the real
     * timestamp for that milestone. "Out for delivery" has no dedicated
     * status in the enum — it is the courier leg of "Shipped" — so it reads
     * the shipped timestamp and stays pending until the order is delivered.
     *
     * @var list<array{title: string, step: int, status: OrderStatus}>
     */
    private const TIMELINE_STAGES = [
        ['title' => 'Order placed', 'step' => 0, 'status' => OrderStatus::Pending],
        ['title' => 'Payment confirmed', 'step' => 1, 'status' => OrderStatus::Confirmed],
        ['title' => 'Packed in warehouse', 'step' => 1, 'status' => OrderStatus::Processing],
        ['title' => 'Shipped', 'step' => 2, 'status' => OrderStatus::Shipped],
        ['title' => 'Out for delivery', 'step' => 3, 'status' => OrderStatus::Shipped],
        ['title' => 'Delivered', 'step' => 4, 'status' => OrderStatus::Delivered],
    ];

    public function __construct(
        protected CartService $cartService,
        protected AdminOrderService $adminOrders,
        protected CouponService $couponService,
    ) {}

    /**
     * Run the full purchase flow for the given cart and recipient.
     *
     * The order is created with status = Pending and payment_status =
     * Pending (no payment gateway yet; COD pays on delivery). The
     * cart is emptied only after every other step succeeds.
     *
     * @param  array<string, mixed>  $checkoutData  the validated form input
     *
     * @throws ValidationException when the cart is empty or a variant is short on stock
     */
    public function placeOrder(?Customer $customer, string $sessionId, array $checkoutData): Order
    {
        // A blocked account may not place an order. The check lives
        // here rather than in the controller because the checkout is
        // open to the public: a session that was signed in before the
        // account was blocked is still a session, and the order has to
        // be refused where it is written.
        if ($customer !== null && $customer->is_blocked) {
            throw ValidationException::withMessages([
                'email' => 'This account has been suspended.',
            ]);
        }

        $order = DB::transaction(function () use ($customer, $sessionId, $checkoutData): Order {
            $governorate = $this->resolveGovernorate($checkoutData['governorate']);

            $cartItems = $this->resolveCartItems(
                $customer,
                $sessionId,
                $checkoutData['cart_json'] ?? null,
            );

            if ($cartItems->isEmpty()) {
                throw ValidationException::withMessages([
                    'cart' => 'Your cart is empty.',
                ]);
            }

            $subtotal = 0.00;
            $itemsToCreate = [];

            foreach ($cartItems as $item) {
                /** @var Variant $variant */
                $variant = Variant::with(['product', 'color', 'size'])
                    ->whereKey($item['variant_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($variant->quantity < $item['quantity']) {
                    throw ValidationException::withMessages([
                        'stock' => "Insufficient stock for '{$variant->product->name}'"
                            .' ('.($variant->color?->name ?? '—').' / '.($variant->size?->name ?? '—').').'
                            ." Available: {$variant->quantity}.",
                    ]);
                }

                // Stock is taken off the books immediately. If anything
                // downstream fails the surrounding transaction rolls
                // this back together with the order row.
                $variant->decrement('quantity', $item['quantity']);

                $unitPrice = (float) $variant->final_price;
                $lineTotal = $unitPrice * $item['quantity'];
                $subtotal += $lineTotal;

                $itemsToCreate[] = [
                    'product_id' => $variant->product_id,
                    'variant_id' => $variant->id,
                    'product_name' => $variant->product->name_ar ?? $variant->product->name,
                    'color_name' => $variant->color?->name,
                    'size_name' => $variant->size?->name,
                    'sku' => $variant->sku,
                    'unit_price' => $unitPrice,
                    'quantity' => $item['quantity'],
                    'total_price' => $lineTotal,
                ];
            }

            $shippingFee = $this->calculateShippingFee($governorate, $subtotal);

            // تطبيق الكوبون إن وُجد — التحقق والحساب داخل نفس الـ Transaction
            // حتى لا تتغير صلاحية الكوبون بين الفحص والكتابة.
            $coupon = null;
            $discountAmount = 0.00;

            if (! empty($checkoutData['coupon_code'])) {
                $couponResult = $this->couponService->validateAndCalculate(
                    code: (string) $checkoutData['coupon_code'],
                    subtotal: $subtotal,
                    shippingFee: $shippingFee,
                    customer: $customer,
                );
                $coupon = $couponResult['coupon'];
                $discountAmount = $couponResult['discount'];
            }

            $total = max(0, ($subtotal + $shippingFee) - $discountAmount);

            $order = Order::create([
                'order_number' => Order::generateOrderNumber(),
                'customer_id' => $customer?->id,
                'customer_name' => $checkoutData['name'],
                'customer_phone' => $checkoutData['phone'],
                'customer_email' => $checkoutData['email'] ?? $customer?->email,
                'governorate_id' => $governorate?->id,
                'governorate_name' => $governorate?->name ?? (string) $checkoutData['governorate'],
                'area' => $checkoutData['area'],
                'address' => $checkoutData['address'],
                'notes' => $checkoutData['notes'] ?? null,
                'subtotal' => $subtotal,
                'shipping_fee' => $shippingFee,
                'discount_amount' => $discountAmount,
                'total' => $total,
                'coupon_id' => $coupon?->id,
                'coupon_code' => $coupon?->code,
                'payment_method' => $checkoutData['payment_method'] ?? 'cod',
                'payment_status' => PaymentStatus::Pending,
                'status' => OrderStatus::Pending,
            ]);

            foreach ($itemsToCreate as $itemData) {
                $order->items()->create($itemData);
            }

            // زيادة عداد استخدام الكوبون — داخل نفس الـ Transaction،
            // فإذا فشلت كتابة الأوردر لأي سبب يتم التراجع تلقائياً.
            if ($coupon instanceof Coupon) {
                $coupon->increment('used_count');
            }

            $order->statusHistory()->create([
                'status' => OrderStatus::Pending,
                'comment' => 'Order placed successfully by customer.',
                'changed_by_type' => $customer ? 'Customer' : 'Guest',
                'changed_by_id' => $customer?->id,
            ]);

            $this->cartService->clearCart($customer, $sessionId);

            return $order->load(['items', 'governorate']);
        });

        // إشعار الأدمنز يخرج من خارج الـ Transaction حتى لا تتأثر كتابة
        // الإشعار نفسها بحدوث Rollback بعد فشل الإدراج. الـ dispatch هنا
        // متزامن لأن الإشعار المطلوب (database channel) بسيط ولا يستحق
        // تحويله إلى Queue.
        OrderCreated::dispatch($order);

        return $order;
    }

    /**
     * Look a governorate up either by id or by name. The form posts
     * the visible option text; an API caller would post the id. Both
     * shapes are accepted so the controller stays free of branching.
     */
    protected function resolveGovernorate(mixed $govInput): ?Governorate
    {
        if (is_numeric($govInput)) {
            return Governorate::with('zone')->find($govInput);
        }

        return Governorate::with('zone')
            ->where('name', $govInput)
            ->orWhere('name_ar', $govInput)
            ->first();
    }

    /**
     * Resolve the cart lines to purchase.
     *
     * Order of preference:
     *  1. A persisted cart on the DB for the customer or session.
     *  2. The cart_json payload the browser sends when it never logged in.
     *  3. An empty collection — the controller will surface the
     *     "Your cart is empty" message.
     *
     * @return Collection<int, array{variant_id: int, quantity: int}>
     */
    protected function resolveCartItems(?Customer $customer, string $sessionId, ?string $cartJson): Collection
    {
        $dbItems = $customer
            ? CartItem::where('customer_id', $customer->id)->get()
            : CartItem::whereNull('customer_id')->where('session_id', $sessionId)->get();

        if ($dbItems->isNotEmpty()) {
            return $dbItems->map(fn (CartItem $i): array => [
                'variant_id' => (int) $i->variant_id,
                'quantity' => (int) $i->quantity,
            ])->values();
        }

        if (! empty($cartJson)) {
            $parsed = json_decode($cartJson, true);
            $lines = is_array($parsed) ? ($parsed['items'] ?? $parsed) : [];

            return collect($lines)
                ->map(function ($line): ?array {
                    if (! is_array($line)) {
                        return null;
                    }

                    if (! empty($line['variant_id'])) {
                        return [
                            'variant_id' => (int) $line['variant_id'],
                            'quantity' => max(1, (int) ($line['qty'] ?? $line['quantity'] ?? 1)),
                        ];
                    }

                    // Fallback: resolve by product slug when the
                    // browser only knows the product identity. Picks
                    // the cheapest in-stock variant for that slug —
                    // the JS sends the same selector shape.
                    if (! empty($line['slug'])) {
                        $product = Product::query()
                            ->active()
                            ->where('slug', $line['slug'])
                            ->first();

                        $variant = $product?->variants()
                            ->where('quantity', '>', 0)
                            ->orderBy('price')
                            ->first();

                        if ($variant) {
                            return [
                                'variant_id' => (int) $variant->id,
                                'quantity' => max(1, (int) ($line['qty'] ?? 1)),
                            ];
                        }
                    }

                    return null;
                })
                ->filter()
                ->values();
        }

        return collect();
    }

    /**
     * Every order belonging to a customer, shaped for the store front.
     *
     * The shape is the contract resources/js/orders.js renders from: a step
     * index the stepper and status badge key off, an eta line, line items,
     * and a timeline. Timestamps come from order_status_history rather than
     * the order row so every milestone shows when it actually happened.
     *
     * @return list<array<string, mixed>>
     */
    public function ordersForCustomer(Customer $customer): array
    {
        return Order::query()
            ->where('customer_id', $customer->id)
            ->with(['items', 'statusHistory'])
            ->latest('id')
            ->get()
            ->map(fn (Order $order): array => $this->presentOrder($order))
            ->values()
            ->all();
    }

    /**
     * Cancel a customer-owned order and put its stock back.
     *
     * Only a Pending order may be cancelled by its customer — once the
     * warehouse has picked the parcel up the units are physically gone and
     * returning them would oversell. The precondition is checked here, then
     * the restock and status flip are delegated to
     * Admin\OrderService::cancelOrder so the store front and the back office
     * share a single implementation that cannot drift apart.
     *
     * @param  array<string, mixed>  $data  the validated cancel payload
     *
     * @throws ValidationException when the order has moved past Pending
     */
    public function cancelOrderForCustomer(Order $order, Customer $customer, array $data): void
    {
        if ($order->status !== OrderStatus::Pending) {
            throw ValidationException::withMessages([
                'status' => 'This order can no longer be cancelled.',
            ]);
        }

        $this->adminOrders->cancelOrder(
            order: $order,
            reasonId: $data['cancellation_reason_id'] ?? null,
            customNote: $data['cancellation_note'] ?? 'Cancelled by customer',
            changedById: $customer->id,
            changedByType: 'Customer',
        );
    }

    /**
     * Shape one order into the payload the store front renders.
     *
     * @return array<string, mixed>
     */
    protected function presentOrder(Order $order): array
    {
        $step = $this->stepForStatus($order->status);
        $isCancelled = $order->status === OrderStatus::Cancelled;
        $reached = $this->statusTimestamps($order);
        $returnOpen = $this->isReturnWindowOpen($order);

        return [
            'id' => $order->order_number,
            'date' => $order->created_at->format('Y-m-d'),
            'dateLabel' => $order->created_at->format('M d, Y'),
            'step' => $step,
            'cancelled' => $isCancelled,
            'etaText' => $this->etaTextFor($order, $isCancelled),
            'items' => $order->items->map(fn (OrderItem $item): array => [
                'name' => $item->product_name,
                'variant' => $this->variantLabelFor($item),
                'qty' => (int) $item->quantity,
                'price' => (int) $item->unit_price,
            ])->values()->all(),
            'shipping' => (int) $order->shipping_fee,
            'payment' => strtoupper((string) $order->payment_method),
            'address' => $order->area.', '.$order->governorate_name,
            'returnOpen' => $returnOpen,
            'timeline' => $isCancelled
                ? $this->buildCancelledTimeline($order)
                : $this->buildProgressTimeline($step, $reached, $order),
        ];
    }

    /**
     * Map a status onto the stepper index the front end renders.
     *
     * The stepper labels are Placed / Packed / Shipped / Out for delivery /
     * Delivered, so the step is a presentation index and not a count of
     * statuses. Confirmed and Processing both read as "Packed" because the
     * warehouse only picks the parcel up once the order is confirmed.
     */
    protected function stepForStatus(OrderStatus $status): int
    {
        return match ($status) {
            OrderStatus::Pending => 0,
            OrderStatus::Confirmed, OrderStatus::Processing => 1,
            OrderStatus::Shipped => 2,
            OrderStatus::Delivered, OrderStatus::Returned => 4,
            OrderStatus::Cancelled => 0,
        };
    }

    /**
     * When each status was first reached, keyed by the status value.
     *
     * `statusHistory` is ordered newest first, so the first row seen for a
     * status is the earliest time the order reached it.
     *
     * @return array<string, Carbon>
     */
    protected function statusTimestamps(Order $order): array
    {
        $timestamps = [];

        foreach ($order->statusHistory as $entry) {
            if (! $entry->status instanceof OrderStatus) {
                continue;
            }

            $timestamps[$entry->status->value] ??= $entry->created_at;
        }

        return $timestamps;
    }

    /**
     * The six-row timeline for an order still moving through the warehouse.
     *
     * @param  array<string, Carbon>  $reached
     * @return list<array{title: string, time: string, status: string}>
     */
    protected function buildProgressTimeline(int $step, array $reached, Order $order): array
    {
        return array_map(
            fn (array $stage): array => $this->timelineRow(
                $stage['title'],
                $stage['step'] <= $step,
                $reached[$stage['status']->value] ?? $order->updated_at,
            ),
            self::TIMELINE_STAGES,
        );
    }

    /**
     * A cancelled order gets a short, all-cancelled timeline instead of the
     * forward-looking one — the stepper is hidden for these cards.
     *
     * @return list<array{title: string, time: string, status: string}>
     */
    protected function buildCancelledTimeline(Order $order): array
    {
        $rows = [
            $this->timelineRow('Order placed', true, $order->created_at),
            $this->timelineRow('Cancelled by you', true, $order->updated_at),
        ];

        if ($order->payment_status === PaymentStatus::Paid) {
            $rows[] = $this->timelineRow('Refund issued', true, $order->updated_at);
        }

        return array_map(
            fn (array $row): array => ['title' => $row['title'], 'time' => $row['time'], 'status' => 'cancelled'],
            $rows,
        );
    }

    /**
     * @return array{title: string, time: string, status: string}
     */
    protected function timelineRow(string $title, bool $isDone, ?Carbon $occurredAt): array
    {
        return [
            'title' => $title,
            'time' => $isDone && $occurredAt ? $occurredAt->format('M d, Y · H:i') : '—',
            'status' => $isDone ? 'done' : 'pending',
        ];
    }

    /**
     * The one-line summary under the stepper.
     */
    protected function etaTextFor(Order $order, bool $isCancelled): string
    {
        if ($isCancelled) {
            return $order->payment_status === PaymentStatus::Paid
                ? 'Cancelled by you · refund issued'
                : 'Cancelled by you · refund on delivery';
        }

        return match ($order->status) {
            OrderStatus::Pending => 'Order placed · awaiting confirmation',
            OrderStatus::Confirmed => 'Confirmed · awaiting packing',
            OrderStatus::Processing => 'Packed in warehouse · preparing shipment',
            OrderStatus::Shipped => 'Shipped · on the way to you',
            OrderStatus::Delivered => 'Delivered '.($this->returnDeadline($order)?->format('M j') ?? ''),
            OrderStatus::Returned => 'Returned · refund issued',
            OrderStatus::Cancelled => 'Cancelled by you',
        };
    }

    /**
     * The size/colour line under a product name, e.g. "L · Black".
     *
     * Items whose variant has since been deleted leave both columns null;
     * the SKU snapshot is the only thing left to show.
     */
    protected function variantLabelFor(OrderItem $item): string
    {
        $parts = array_filter([
            $item->size_name ? trim($item->size_name) : null,
            $item->color_name ? trim($item->color_name) : null,
        ]);

        return $parts === [] ? ($item->sku ?? '—') : implode(' · ', $parts);
    }

    /**
     * Whether the 14-day return window is still open.
     *
     * The clock runs from when the order was actually delivered, not from
     * the last time the row was touched.
     */
    protected function isReturnWindowOpen(Order $order): bool
    {
        return $order->status === OrderStatus::Delivered
            && ($this->returnDeadline($order)?->isFuture() ?? false);
    }

    /**
     * The last day the customer can still return a delivered order.
     */
    protected function returnDeadline(Order $order): ?Carbon
    {
        $deliveredAt = $this->statusTimestamps($order)[OrderStatus::Delivered->value] ?? $order->updated_at;

        return $deliveredAt->copy()->addDays(14);
    }

    /**
     * Decide what to charge for shipping.
     *
     * The free-shipping threshold lives in shipping_settings; when the
     * subtotal clears it the fee is zero regardless of governorate.
     * Otherwise the governorate's own fee wins (or its zone's, when
     * the governorate inherits), and the global default is the last
     * resort for any name the admin never persisted.
     */
    protected function calculateShippingFee(?Governorate $gov, float $subtotal): float
    {
        $freeShippingEnabled = (bool) ShippingSetting::get('free_shipping_enabled', false);
        $threshold = (float) ShippingSetting::get('free_shipping_threshold', 0);

        if ($freeShippingEnabled && $threshold > 0 && $subtotal >= $threshold) {
            return 0.00;
        }

        if ($gov) {
            return (float) $gov->effective_fee;
        }

        return (float) ShippingSetting::get('default_fee', 50.00);
    }
}
