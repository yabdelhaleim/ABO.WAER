@php
    use App\Models\StoreSetting;

    // The footer's columns and the store's contact details are measured,
    // not written: they come from the categories, the published pages
    // and the store's own settings. The service is injected by the
    // storefront layout — @inject('storefront', …) — and shared with
    // every partial the layout includes.
    $footerData = $storefront->footer();
    $footerCols = $footerData['columns'];
    $contact = $footerData['contact'];
    $footerTag = $footer['tagline'] ?? "Not made to blend in — premium men's streetwear from Cairo.";
    $storeName = StoreSetting::storeName();
@endphp

<footer class="store-footer" role="contentinfo">
    <div class="store-footer__inner">
        <div class="store-footer__brand">
            <a href="{{ route('home') }}" class="store-footer__logo" aria-label="{{ $storeName }} — Home">
                <img src="{{ StoreSetting::logoUrl() }}" alt="{{ $storeName }}" width="44" height="44">
            </a>
            <p class="store-footer__tagline">{{ $footerTag }}</p>

            {{-- The store's own details. A store that has not typed a
                 phone number or an address does not get one here — the
                 old footer invented neither, but it also showed none,
                 so a shopper had no way to reach the shop. --}}
            @if ($contact['phone'] !== '' || $contact['email'] !== '' || $contact['address'] !== '')
                <ul class="store-footer__contact">
                    @if ($contact['phone'] !== '')
                        <li>
                            <a href="tel:{{ preg_replace('/\s+/', '', $contact['phone']) }}">{{ $contact['phone'] }}</a>
                        </li>
                    @endif
                    @if ($contact['whatsapp'] !== '')
                        <li>
                            <a href="https://wa.me/{{ preg_replace('/\D+/', '', $contact['whatsapp']) }}" rel="noopener">WhatsApp {{ $contact['whatsapp'] }}</a>
                        </li>
                    @endif
                    @if ($contact['email'] !== '')
                        <li>
                            <a href="mailto:{{ $contact['email'] }}">{{ $contact['email'] }}</a>
                        </li>
                    @endif
                    @if ($contact['address'] !== '')
                        <li>{{ $contact['address'] }}</li>
                    @endif
                </ul>
            @endif
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
        <p>© {{ date('Y') }} {{ $storeName }}. All rights reserved.</p>
        <p class="store-footer__base-meta">Crafted in Cairo.</p>
    </div>
</footer>
