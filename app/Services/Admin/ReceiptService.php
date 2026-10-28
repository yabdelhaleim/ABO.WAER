<?php

namespace App\Services\Admin;

use App\Models\Order;
use App\Services\ImageService;
use Illuminate\Support\Collection;

/**
 * The printable documents the orders screen produces.
 *
 * The admin could press "Print invoice" and "Print labels" and the
 * screen answered "Invoice ready (demo)" — two toasts and no document.
 * There was no receipt printer behind them at all.
 *
 * This service builds what the two documents actually carry, and it
 * builds it from the same place the rest of the back office reads the
 * store from: the `store_settings` table. The receipt section of the
 * settings screen is not a picture of a preference — it is the
 * preference:
 *
 *   receipts.paper          a4 | thermal — the sheet the document is
 *                           laid out for
 *   receipts.showLogo       whether the store's logo heads the invoice
 *   receipts.headerText     the line under the logo
 *   receipts.footerNote     the note under the totals
 *   receipts.fields.*       which rows the invoice carries at all
 *
 * The two documents answer different questions:
 *
 *   - The invoice is the money: what was ordered, what it cost, what
 *     the customer owes. It is what the customer is handed.
 *   - The label is the delivery: who receives it, where, how much the
 *     courier collects. It is what the courier carries.
 *
 * Both are measured from the database — the order's own rows and the
 * item rows that belong to it — never from the copy the browser
 * happens to be holding.
 */
class ReceiptService
{
    public function __construct(
        protected SettingsService $settings,
    ) {}

    /**
     * The invoice for one order.
     *
     * @return array<string, mixed>
     */
    public function invoice(Order $order): array
    {
        $order->loadMissing('items');

        return [
            'layout' => $this->layout(),
            'store' => $this->store(),
            'order' => $this->order($order),
            'items' => $this->items($order),
        ];
    }

    /**
     * The labels for a batch of orders — one per order, in the order
     * they were handed in.
     *
     * @param  Collection<int, Order>  $orders
     * @return array<int, array<string, mixed>>
     */
    public function labels(Collection $orders): array
    {
        return $orders
            ->map(fn (Order $order): array => $this->label($order))
            ->values()
            ->all();
    }

    /**
     * The label for one order.
     *
     * @return array<string, mixed>
     */
    public function label(Order $order): array
    {
        $order->loadMissing('items');

        return [
            'number' => (string) $order->order_number,
            'date' => $order->created_at?->format('Y-m-d H:i'),
            'customer' => (string) $order->customer_name,
            'phone' => (string) $order->customer_phone,
            'governorate' => (string) $order->governorate_name,
            'area' => (string) $order->area,
            'address' => (string) $order->address,
            'notes' => (string) ($order->notes ?? ''),
            'itemsCount' => (int) $order->items->sum('quantity'),
            // The amount the courier collects. Only a cash-on-delivery
            // order carries anything to collect — a card order was
            // paid for before it shipped, so a label that asks the
            // courier to collect it would be asking for money twice.
            'collect' => $order->payment_method === 'cod'
                ? round((float) $order->total, 2)
                : 0.0,
            'paymentMethod' => (string) ($order->payment_method ?? 'cod'),
            'tracking' => (string) ($order->tracking_number ?? ''),
            'status' => (string) ($order->status?->value ?? ''),
        ];
    }

    /**
     * The receipt layout the settings ask for.
     *
     * @return array<string, mixed>
     */
    public function layout(): array
    {
        $receipts = $this->settings->section('receipts');
        $fields = is_array($receipts['fields'] ?? null) ? $receipts['fields'] : [];

        return [
            'paper' => ($receipts['paper'] ?? 'a4') === 'thermal' ? 'thermal' : 'a4',
            'showLogo' => (bool) ($receipts['showLogo'] ?? true),
            'headerText' => (string) ($receipts['headerText'] ?? ''),
            'footerNote' => (string) ($receipts['footerNote'] ?? ''),
            'fields' => [
                'orderNumber' => (bool) ($fields['orderNumber'] ?? true),
                'date' => (bool) ($fields['date'] ?? true),
                'customerPhone' => (bool) ($fields['customerPhone'] ?? true),
                'address' => (bool) ($fields['address'] ?? false),
                'paymentMethod' => (bool) ($fields['paymentMethod'] ?? true),
                'shippingFee' => (bool) ($fields['shippingFee'] ?? true),
            ],
        ];
    }

    /**
     * The store's own details — the ones the invoice is headed with.
     *
     * @return array<string, mixed>
     */
    public function store(): array
    {
        $store = $this->settings->section('store');

        return [
            'name' => (string) ($store['name'] ?: 'ABO'),
            'phone' => (string) ($store['phone'] ?? ''),
            'whatsapp' => (string) ($store['whatsapp'] ?? ''),
            'email' => (string) ($store['email'] ?? ''),
            'address' => (string) ($store['address'] ?? ''),
            'logo' => ImageService::publicUrl($store['logo'] ?? null) ?? '',
        ];
    }

    /**
     * The order's own rows, as the invoice reads them.
     *
     * @return array<string, mixed>
     */
    protected function order(Order $order): array
    {
        return [
            'number' => (string) $order->order_number,
            'date' => $order->created_at?->format('Y-m-d H:i'),
            'customer' => (string) $order->customer_name,
            'phone' => (string) $order->customer_phone,
            'email' => (string) ($order->customer_email ?? ''),
            'governorate' => (string) $order->governorate_name,
            'area' => (string) $order->area,
            'address' => (string) $order->address,
            'notes' => (string) ($order->notes ?? ''),
            'subtotal' => round((float) $order->subtotal, 2),
            'shipping' => round((float) $order->shipping_fee, 2),
            'discount' => round((float) $order->discount_amount, 2),
            'coupon' => (string) ($order->coupon_code ?? ''),
            'total' => round((float) $order->total, 2),
            'paymentMethod' => $this->paymentMethodLabel((string) ($order->payment_method ?? 'cod')),
            'paymentStatus' => (string) ($order->payment_status?->value ?? 'pending'),
            'status' => (string) ($order->status?->value ?? ''),
        ];
    }

    /**
     * The order's line items.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function items(Order $order): array
    {
        return $order->items
            ->map(fn ($item): array => [
                'name' => (string) $item->product_name,
                'color' => (string) ($item->color_name ?? ''),
                'size' => (string) ($item->size_name ?? ''),
                'sku' => (string) ($item->sku ?? ''),
                'quantity' => (int) $item->quantity,
                'unitPrice' => round((float) $item->unit_price, 2),
                'total' => round((float) $item->total_price, 2),
            ])
            ->values()
            ->all();
    }

    /**
     * The wording for a payment method the order was placed with.
     */
    protected function paymentMethodLabel(string $method): string
    {
        return match ($method) {
            'cod' => 'Cash on delivery',
            'card' => 'Card',
            'wallet' => 'Wallet',
            default => ucfirst($method),
        };
    }
}
