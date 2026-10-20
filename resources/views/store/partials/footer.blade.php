@php
    $year = date('Y');
    // Footer columns: 3 (Shop / Help / Follow) per spec §2.10.
    // Fallback columns if the home view didn't pass $footer.
    $footerCols = $footer['columns'] ?? [
        'Shop' => [
            ['label' => 'All products', 'href' => '#shop'],
            ['label' => 'New arrivals', 'href' => '#new'],
            ['label' => 'Categories',   'href' => '#categories'],
            ['label' => 'Sale',         'href' => '#sale'],
        ],
        'Help' => [
            ['label' => 'Shipping', 'href' => route('shipping')],
            ['label' => 'Returns',  'href' => route('returns')],
            ['label' => 'Exchange', 'href' => route('exchange')],
            ['label' => 'About us', 'href' => route('about')],
            ['label' => 'Contact us', 'href' => route('contact')],
        ],
        'Follow' => [
            ['label' => 'Instagram',  'href' => '#instagram'],
            ['label' => 'TikTok',     'href' => '#tiktok'],
            ['label' => 'X / Twitter','href' => '#x'],
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
