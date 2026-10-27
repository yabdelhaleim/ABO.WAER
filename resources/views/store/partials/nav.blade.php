<header class="store-header" role="banner">
    <div class="store-header__inner">
        <a href="{{ route('home') }}" class="store-header__brand" aria-label="ABO — Home">
            <img src="{{ asset('images/abo-logo.svg') }}" alt="ABO" width="40" height="40">
        </a>

        <nav class="store-header__nav" aria-label="Primary">
            <a href="{{ route('home') }}"
               class="store-header__link {{ request()->routeIs('home') ? 'is-active' : '' }}">Home</a>
            <a href="{{ route('shop') }}"
               class="store-header__link {{ request()->routeIs('shop') ? 'is-active' : '' }}">Shop</a>
            <a href="{{ route('shop') }}"
               class="store-header__link {{ request()->routeIs('shop') ? 'is-active' : '' }}">Categories</a>
            <a href="{{ route('about') }}"
               class="store-header__link {{ request()->routeIs('about') ? 'is-active' : '' }}">About</a>
            <a href="{{ route('contact') }}"
               class="store-header__link {{ request()->routeIs('contact') ? 'is-active' : '' }}">Contact</a>
        </nav>

        <div class="store-header__actions">
            {{-- Search is a real link to the server-rendered search page,
                 so it works without JavaScript and the results page keeps
                 the term in the URL. --}}
            <a href="{{ route('search') }}" class="store-header__icon" aria-label="Search">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M21 20.3 16.7 16a8 8 0 1 0-1.4 1.4L20.3 21 21 20.3zM4 10a6 6 0 1 1 12 0 6 6 0 0 1-12 0z" fill="currentColor"/>
                </svg>
            </a>

            @auth
                <a href="{{ route('account') }}" class="store-header__icon" aria-label="My account">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M12 12c2.7 0 5-2.3 5-5s-2.3-5-5-5-5 2.3-5 5 2.3 5 5 5zm0 2c-3.3 0-10 1.7-10 5v3h20v-3c0-3.3-6.7-5-10-5z" fill="currentColor"/>
                    </svg>
                </a>
                <form method="POST" action="{{ route('logout') }}" class="store-header__logout">
                    @csrf
                    <button type="submit" class="store-header__icon" aria-label="Log out">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M16 17v-2H7v-6h9V7l5 5-5 5zm-9 4h11a2 2 0 0 0 2-2v-3h-2v3H7V5h11v3h2V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2z" fill="currentColor"/>
                        </svg>
                    </button>
                </form>
            @else
                <a href="{{ route('login') }}" class="store-header__icon" aria-label="Log in">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M12 12c2.7 0 5-2.3 5-5s-2.3-5-5-5-5 2.3-5 5 2.3 5 5 5zm0 2c-3.3 0-10 1.7-10 5v3h20v-3c0-3.3-6.7-5-10-5z" fill="currentColor"/>
                    </svg>
                </a>
            @endauth

            <a href="{{ route('cart') }}" class="store-header__icon" aria-label="Cart" data-nav-cart>
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M7 4h-2l-1 2v2h2l3 9-1.4 2.5c-.5.8-.1 1.5.7 1.5h12v-2H9.4l1-2H18a2 2 0 0 0 1.8-1.1l3.2-7.4L21 8H7.4l-.9-2.5L5.7 4H7zm2 16a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm10 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4z" fill="currentColor"/>
                </svg>
                <span class="store-header__cart-count" data-nav-cart-count hidden>0</span>
            </a>

            <button type="button" class="store-header__icon store-header__menu" aria-label="Open menu"
                    data-abo-mobile-menu-trigger aria-expanded="false">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M3 6h18v2H3zm0 5h18v2H3zm0 5h18v2H3z" fill="currentColor"/>
                </svg>
            </button>
        </div>
    </div>

    <div class="store-header__sheet" data-abo-mobile-menu hidden>
        <a href="{{ route('home') }}" class="store-header__sheet-link">Home</a>
        <a href="{{ route('shop') }}" class="store-header__sheet-link {{ request()->routeIs('shop') ? 'is-active' : '' }}">Shop</a>
        <a href="{{ route('shop') }}" class="store-header__sheet-link {{ request()->routeIs('shop') ? 'is-active' : '' }}">Categories</a>
        <a href="{{ route('about') }}"
           class="store-header__sheet-link {{ request()->routeIs('about') ? 'is-active' : '' }}">About</a>
        <a href="{{ route('contact') }}"
           class="store-header__sheet-link {{ request()->routeIs('contact') ? 'is-active' : '' }}">Contact</a>
        <a href="{{ route('search') }}"
           class="store-header__sheet-link {{ request()->routeIs('search') ? 'is-active' : '' }}">Search</a>
        <a href="{{ route('cart') }}" class="store-header__sheet-link">
            Cart
            <span class="store-header__sheet-count" data-nav-cart-count hidden>0</span>
        </a>
        <div class="store-header__sheet-divider" role="presentation"></div>
        @auth
            <form method="POST" action="{{ route('logout') }}" class="store-header__sheet-form">
                @csrf
                <button type="submit" class="store-header__sheet-link store-header__sheet-link--button">
                    Log out ({{ auth()->user()->name }})
                </button>
            </form>
        @else
            <a href="{{ route('login') }}" class="store-header__sheet-link">Log in</a>
            <a href="{{ route('register') }}" class="store-header__sheet-link">Create account</a>
        @endauth
    </div>
</header>
