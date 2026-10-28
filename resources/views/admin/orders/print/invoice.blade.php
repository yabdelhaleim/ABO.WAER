{{--
    The printable invoice for one order.

    Rendered by Admin\OrderController::invoice from the payload that
    Admin\Services\Admin\ReceiptService measured: the order's own rows,
    its item rows, and the receipt settings the store saved.

    Every row on this page is optional, and the settings decide which
    ones are here — a receipt the store has trimmed down to a phone
    number and a total is a receipt they meant to print.

    The page is deliberately outside the admin chrome: it is handed to
    a customer, not to the back office.
--}}
@php
    $layout = $receipt['layout'];
    $store = $receipt['store'];
    $order = $receipt['order'];
    $items = $receipt['items'];
    $fields = $layout['fields'];

    $money = fn (float $amount): string => number_format($amount, 2).' EGP';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Invoice — {{ $store['name'] }}</title>
    @include('admin.orders.print.partials.styles', ['paper' => $layout['paper']])
</head>
<body>
{{-- The bar is screen chrome: it never reaches the paper, and it
     repeats nothing the receipt itself decides to carry. --}}
<div class="abo-print__bar">
    <span class="abo-print__bar-label">Invoice</span>
    <button type="button" onclick="window.print()">Print</button>
</div>

<div class="abo-print">
    <header class="abo-print__head">
        <div class="abo-print__brand">
            @if ($layout['showLogo'] && $store['logo'] !== '')
                <img class="abo-print__logo" src="{{ $store['logo'] }}" alt="{{ $store['name'] }}">
            @endif
            <div class="abo-print__store">{{ $store['name'] }}</div>
            @if ($layout['headerText'] !== '')
                <div class="abo-print__header-text">{{ $layout['headerText'] }}</div>
            @endif
            @if ($store['phone'] !== '')
                <div class="abo-print__header-text">{{ $store['phone'] }}</div>
            @endif
        </div>
        <div class="abo-print__doc">
            <div class="abo-print__doc-kind">Invoice</div>
            @if ($fields['orderNumber'])
                <div class="abo-print__doc-number">{{ $order['number'] }}</div>
            @endif
            @if ($fields['date'])
                <div class="abo-print__doc-number">{{ $order['date'] }}</div>
            @endif
        </div>
    </header>

    <section class="abo-print__section">
        <p class="abo-print__label">Customer</p>
        <div class="abo-print__grid">
            <div>
                <p class="abo-print__value">{{ $order['customer'] }}</p>
                @if ($fields['customerPhone'])
                    <p class="abo-print__value">{{ $order['phone'] }}</p>
                @endif
            </div>
            @if ($fields['address'])
                <div>
                    <p class="abo-print__value">
                        {{ $order['governorate'] }}
                        @if ($order['area'] !== '')
                            — {{ $order['area'] }}
                        @endif
                    </p>
                    <p class="abo-print__value">{{ $order['address'] }}</p>
                </div>
            @endif
        </div>
    </section>

    <section class="abo-print__section">
        <p class="abo-print__label">Items</p>
        <table class="abo-print__table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th class="is-numeric">Qty</th>
                    <th class="is-numeric">Price</th>
                    <th class="is-numeric">Total</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $item)
                    <tr>
                        <td>
                            {{ $item['name'] }}
                            @if ($item['color'] !== '' || $item['size'] !== '')
                                <div class="abo-print__variant">
                                    {{ collect([$item['color'], $item['size']])->filter()->implode(' / ') }}
                                </div>
                            @endif
                        </td>
                        <td class="is-numeric">{{ $item['quantity'] }}</td>
                        <td class="is-numeric">{{ $money($item['unitPrice']) }}</td>
                        <td class="is-numeric">{{ $money($item['total']) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">No items on this order.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <section class="abo-print__section abo-print__totals">
        <div class="abo-print__total-row">
            <span>Subtotal</span>
            <span>{{ $money($order['subtotal']) }}</span>
        </div>
        @if ($order['discount'] > 0)
            <div class="abo-print__total-row">
                <span>Discount{{ $order['coupon'] !== '' ? ' ('.$order['coupon'].')' : '' }}</span>
                <span>−{{ $money($order['discount']) }}</span>
            </div>
        @endif
        @if ($fields['shippingFee'])
            <div class="abo-print__total-row">
                <span>Shipping</span>
                <span>{{ $money($order['shipping']) }}</span>
            </div>
        @endif
        <div class="abo-print__total-row abo-print__total-row--grand">
            <span>Total</span>
            <span>{{ $money($order['total']) }}</span>
        </div>
        @if ($fields['paymentMethod'])
            <div class="abo-print__total-row">
                <span>Payment method</span>
                <span>{{ $order['paymentMethod'] }}</span>
            </div>
        @endif
    </section>

    @if ($order['notes'] !== '')
        <section class="abo-print__section">
            <p class="abo-print__label">Notes</p>
            <p class="abo-print__value">{{ $order['notes'] }}</p>
        </section>
    @endif

    @if ($layout['footerNote'] !== '')
        <footer class="abo-print__foot">{{ $layout['footerNote'] }}</footer>
    @endif
</div>

{{-- The print dialog opens by itself: the operator asked for a
     document, and the document is what they want on the printer. --}}
<script>
    window.addEventListener('load', function () { window.print(); });
</script>
</body>
</html>
