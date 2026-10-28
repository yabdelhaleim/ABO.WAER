{{--
    The printable shipping labels for a batch of orders.

    Rendered by Admin\OrderController::labels from the payload that
    App\Services\Admin\ReceiptService measured — one label per selected
    order, each one built from that order's own rows.

    The label answers the courier's three questions: who receives it,
    where, and how much to collect. A card order was paid for before it
    shipped, so it asks the courier to collect nothing — asking for the
    money twice is worse than asking for none.

    The page is deliberately outside the admin chrome: a label is handed
    to a courier, not to the back office.
--}}
@php
    $money = fn (float $amount): string => number_format($amount, 2).' EGP';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Shipping labels — {{ count($labels) }}</title>
    @include('admin.orders.print.partials.styles', ['paper' => $layout['paper']])
</head>
<body>
{{-- The bar is screen chrome: it never reaches the paper. --}}
<div class="abo-print__bar">
    <span class="abo-print__bar-label">{{ count($labels) }} label(s)</span>
    <button type="button" onclick="window.print()">Print</button>
</div>

<div class="abo-print">
    <div class="abo-labels">
        @forelse ($labels as $label)
            <article class="abo-labels__label">
                <div class="abo-labels__number">{{ $label['number'] }}</div>

                <div class="abo-labels__customer">{{ $label['customer'] }}</div>

                @if ($label['phone'] !== '')
                    <div class="abo-labels__line">{{ $label['phone'] }}</div>
                @endif

                <div class="abo-labels__line">
                    {{ $label['governorate'] }}
                    @if ($label['area'] !== '')
                        — {{ $label['area'] }}
                    @endif
                </div>

                <div class="abo-labels__line">{{ $label['address'] }}</div>

                @if ($label['notes'] !== '')
                    <div class="abo-labels__line">Note: {{ $label['notes'] }}</div>
                @endif

                <div class="abo-labels__line">{{ $label['itemsCount'] }} item(s)</div>

                <div class="abo-labels__collect">
                    <span>{{ $label['paymentMethod'] === 'cod' ? 'Collect' : 'Paid' }}</span>
                    <span>{{ $label['collect'] > 0 ? $money($label['collect']) : '—' }}</span>
                </div>
            </article>
        @empty
            <p class="abo-print__value">No orders were selected.</p>
        @endforelse
    </div>
</div>

{{-- The print dialog opens by itself: the operator asked for labels,
     and labels on the printer is what they asked for. --}}
<script>
    window.addEventListener('load', function () { window.print(); });
</script>
</body>
</html>
