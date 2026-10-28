{{--
    The announcement bar — the lime strip above every storefront page.

    The line is measured, not written: it names the live campaign and
    the free-shipping threshold the shipping settings hold. A store with
    neither has nothing to announce, and a bar with nothing in it is
    just a gap — so the bar is left out entirely.

    It used to say "Free shipping over 1,500 EGP · Drop 02 is live" on
    every page of every store, whether or not that was true of them.
--}}
@php
    $announcement = $storefront->announcement();
@endphp

@if ($announcement)
    <aside class="announcement" aria-label="Store announcement">
        <div class="announcement__inner">
            <span>{{ $announcement }}</span>
        </div>
    </aside>
@endif
