@php
    $year = date('Y');
    // Footer columns: Shop / Help / Account. Every link points at a real
    // route — there are no placeholder anchors and no dead `#` links.
    //
    // The columns live here, not in resources/views/store/data/home.php,
    // so every page of the store (shop, product, cart, checkout, the
    // content pages) renders the same footer. The Account column adapts
    // to the session: a guest gets the sign-in links, a signed-in
    // customer gets their own account.
    $footerCols = [
        'Shop' => [
            ['label' => 'All products', 'href' => route('shop')],
            ['label' => 'New arrivals', 'href' => route('shop').'?sort=newest'],
            ['label' => 'Categories',   'href' => route('shop').'#categories'],
            ['label' => 'Sale',         'href' => route('shop').'?sort=price-asc'],
        ],
        'Help' => [
            ['label' => 'Shipping', 'href' => route('shipping')],
            ['label' => 'Returns',  'href' => route('returns')],
            ['label' => 'Exchange', 'href' => route('exchange')],
            ['label' => 'About us', 'href' => route('about')],
            ['label' => 'Contact us', 'href' => route('contact')],
        ],
        'Account' => auth()->check()
            ? [
                ['label' => 'My orders', 'href' => route('orders')],
                ['label' => 'My account', 'href' => route('account')],
                ['label' => 'Cart', 'href' => route('cart')],
            ]
            : [
                ['label' => 'Log in', 'href' => route('login')],
                ['label' => 'Create account', 'href' => route('register')],
                ['label' => 'Cart', 'href' => route('cart')],
            ],
    ];
    $footerTag = $footer['tagline'] ?? "Not made to blend in — premium men's streetwear from Cairo.";
@endphp

<footer class="store-footer" role="contentinfo">
    <div class="store-footer__inner">
        <div class="store-footer__brand">
            <a href="{{ route('home') }}" class="store-footer__logo" aria-label="ABO — Home">
                <img src="{{ asset('images/abo-logo.svg') }}" alt="ABO" width="44" height="44">
            </a>
            <p class="store-footer__tagline">{{ $footerTag }}</p>
        </div>

        @foreach ($footerCols as $heading => $links)
            <nav class="store-footer__group" aria-label="{{ $heading }}">
                <h2 class="store-footer__heading">{{ $heading }}</h2>
                <ul>
                    @foreach ($links as $link)
                        <li><a href="{{ $link['href'] }}">{{ $link['label'] }}</a></li>
                    @endforeach
                </ul>
            </nav>
        @endforeach
    </div>

    <div class="store-footer__base">
        <p>© {{ $year }} ABO. All rights reserved.</p>
        <p class="store-footer__base-meta">Crafted in Cairo.</p>
    </div>
</footer>
