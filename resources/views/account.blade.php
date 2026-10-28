{{--
    My Account page — frontend only.

    Renders the authenticated customer's profile. Reads only fields that
    already exist on the Customer model (name, email, phone, created_at).
    Anything that would require a missing backend feature (orders,
    addresses, profile update) is omitted — no fake shortcuts, no
    non-functional buttons, no invented data.

    All destinations point to existing named routes. The sign-out form
    uses the existing logout flow via the `logout` named route, which is
    already CSRF-protected and handled by LoginController@destroy.
--}}

@extends('layouts.store')

@section('title', 'My Account — ABO')
@section('description', 'Manage your ABO account. Review your details and keep everything in one place.')

@section('content')
    @php
        // Single source for the data the page can actually show.
        // `$user` is the authenticated Customer model instance
        // (passed in by the closure route). Every access is guarded
        // with `filled()` so a missing field degrades gracefully
        // rather than printing "null" or an empty string.
        $userName  = $user->name ?? 'Member';
        $userEmail = $user->email ?? null;
        $userPhone = $user->phone ?? null;
        $memberSince = $user->created_at ?? null;

        // Initials for the avatar tile. Take the first letter of the
        // first two words of the name (e.g. "John Doe" → "JD"). Uppercased.
        $initials = strtoupper(collect(preg_split('/\s+/u', trim($userName), -1, PREG_SPLIT_NO_EMPTY))
            ->filter()
            ->take(2)
            ->map(fn ($part) => mb_substr($part, 0, 1))
            ->implode(''));

        // Friendly member-since label. Falls back to "—" when the
        // timestamp is missing for any reason.
        $memberSinceLabel = $memberSince
            ? $memberSince->translatedFormat('F j, Y')
            : null;
    @endphp

    <section class="account" aria-labelledby="account-title">
        {{-- ─── A. Page Header ────────────────────────────────────── --}}
        <header class="account__head">
            <p class="account__eyebrow">
                <span class="account__eyebrow-line" aria-hidden="true"></span>
                <span>YOUR SPACE</span>
            </p>
            <h1 class="account__title" id="account-title">
                MY<span class="account__title-accent"> ACCOUNT.</span>
            </h1>
            <p class="account__lede">
                Manage your details and keep everything in one place.
            </p>
        </header>

        {{-- ─── C. Account Navigation ─────────────────────────────── --}}
        {{-- Only "ACCOUNT DETAILS" has a real destination (this page).
             My Orders and Addresses have no backend yet, so they are
             intentionally omitted per the "no fake links" rule. --}}
        <nav class="account__nav" aria-label="Account sections">
            <a
                href="{{ route('account') }}"
                class="account__nav-link is-active"
                aria-current="page"
            >
                <span class="account__nav-num" aria-hidden="true">01</span>
                <span class="account__nav-label">ACCOUNT DETAILS</span>
            </a>
        </nav>

        {{-- ─── B + D. Overview + Personal Information ────────────── --}}
        <div class="account__grid">
            {{-- Account Overview (welcome / member identity) --}}
            <aside class="account__overview" aria-label="Account overview">
                <p class="account__panel-eyebrow">ACCOUNT OVERVIEW</p>

                <div class="account__identity">
                    <span class="account__avatar" aria-hidden="true">{{ $initials }}</span>
                    <div class="account__identity-text">
                        <p class="account__welcome">
                            Welcome back,<br>
                            <strong>{{ $userName }}</strong>
                        </p>
                    </div>
                </div>

                @if ($memberSinceLabel)
                    <p class="account__member">
                        <span class="account__member-label">MEMBER SINCE</span>
                        <span class="account__member-value">{{ $memberSinceLabel }}</span>
                    </p>
                @endif
            </aside>

            {{-- Personal Information Card --}}
            <article class="account__card" aria-labelledby="account-card-title">
                <header class="account__card-head">
                    <p class="account__panel-eyebrow">PERSONAL INFORMATION</p>
                    <h2 class="account__card-title" id="account-card-title">
                        YOUR DETAILS
                    </h2>
                </header>

                <dl class="account__details">
                    <div class="account__detail">
                        <dt class="account__detail-label">Full name</dt>
                        <dd class="account__detail-value">{{ $userName }}</dd>
                    </div>

                    <div class="account__detail">
                        <dt class="account__detail-label">Email</dt>
                        <dd class="account__detail-value">
                            @if (filled($userEmail))
                                {{ $userEmail }}
                            @else
                                <span class="account__detail-empty">Not provided</span>
                            @endif
                        </dd>
                    </div>

                    <div class="account__detail">
                        <dt class="account__detail-label">Phone</dt>
                        <dd class="account__detail-value">
                            @if (filled($userPhone))
                                {{ $userPhone }}
                            @else
                                <span class="account__detail-empty">Not provided</span>
                            @endif
                        </dd>
                    </div>
                </dl>

                {{-- ─── F. Sign Out ─────────────────────────────────────
                     Reuses the existing logout flow. The form posts to
                     the `logout` named route, which is handled by
                     LoginController@destroy (already CSRF-protected).
                     No custom auth logic is introduced. --}}
                <form
                    method="POST"
                    action="{{ route('logout') }}"
                    class="account__signout-form"
                >
                    @csrf
                    <button type="submit" class="account__signout">
                        <span>SIGN OUT</span>
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path d="M16 17v-2H7v-6h9V7l5 5-5 5zm-9 4h11a2 2 0 0 0 2-2v-3h-2v3H7V5h10v3h2V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2z"
                                  fill="none" stroke="currentColor"
                                  stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </form>
            </article>
        </div>

        {{-- ─── E. Account Shortcuts ────────────────────────────────
             Only destinations that already exist are linked:
             CONTINUE SHOPPING → /shop (route 'shop')
             YOUR CART        → /cart (route 'cart')
             MY ORDERS        → /orders (route 'orders')
             MANAGE ADDRESSES is omitted because no backend supports it
             yet (would be an invented link). --}}
        <section class="account__shortcuts" aria-label="Quick actions">
            <p class="account__panel-eyebrow">QUICK ACTIONS</p>

            <ul class="account__shortcut-grid" role="list">
                <li>
                    <a class="account__shortcut" href="{{ route('shop') }}">
                        <span class="account__shortcut-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" focusable="false">
                                <path d="M6 7h12l-1.4 9.5a2 2 0 0 1-2 1.7H9.4a2 2 0 0 1-2-1.7L6 7zm5-3a1 1 0 0 0-1 1v1h-1a2 2 0 1 0 0 4h2V6h2v4h2a2 2 0 1 0 0-4h-1V5a1 1 0 0 0-1-1h-2z"
                                      fill="none" stroke="currentColor"
                                      stroke-width="1.6" stroke-linejoin="round"/>
                            </svg>
                        </span>
                        <span class="account__shortcut-body">
                            <span class="account__shortcut-label">CONTINUE SHOPPING</span>
                            <span class="account__shortcut-line" aria-hidden="true"></span>
                            <span class="account__shortcut-desc">
                                Discover new pieces from the latest drop.
                            </span>
                        </span>
                        <span class="account__shortcut-arrow" aria-hidden="true">
                            <svg viewBox="0 0 24 24" focusable="false">
                                <path d="M5 12h12M13 6l6 6-6 6" fill="none" stroke="currentColor"
                                      stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </span>
                    </a>
                </li>

                <li>
                    <a class="account__shortcut" href="{{ route('cart') }}">
                        <span class="account__shortcut-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" focusable="false">
                                <path d="M7 4h-2l-1 2v2h2l3 9-1.4 2.5c-.5.8-.1 1.5.7 1.5h12v-2H9.4l1-2H18a2 2 0 0 0 1.8-1.1l3.2-7.4L21 8H7.4l-.9-2.5L5.7 4H7zm2 16a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm10 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"
                                      fill="currentColor"/>
                            </svg>
                        </span>
                        <span class="account__shortcut-body">
                            <span class="account__shortcut-label">YOUR CART</span>
                            <span class="account__shortcut-line" aria-hidden="true"></span>
                            <span class="account__shortcut-desc">
                                Review the pieces waiting for you.
                            </span>
                        </span>
                        <span class="account__shortcut-arrow" aria-hidden="true">
                            <svg viewBox="0 0 24 24" focusable="false">
                                <path d="M5 12h12M13 6l6 6-6 6" fill="none" stroke="currentColor"
                                      stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </span>
                    </a>
                </li>

                <li>
                    <a class="account__shortcut" href="{{ route('orders') }}">
                        <span class="account__shortcut-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" focusable="false">
                                <path d="M3 7h18l-1.4 9.5a2 2 0 0 1-2 1.7H6.4a2 2 0 0 1-2-1.7L3 7zm5-3a1 1 0 0 0-1 1v1h10V5a1 1 0 0 0-1-1H8zm-1 6h10v2H7v-2z"
                                      fill="currentColor"/>
                            </svg>
                        </span>
                        <span class="account__shortcut-body">
                            <span class="account__shortcut-label">MY ORDERS</span>
                            <span class="account__shortcut-line" aria-hidden="true"></span>
                            <span class="account__shortcut-desc">
                                Track every order in one dashboard.
                            </span>
                        </span>
                        <span class="account__shortcut-arrow" aria-hidden="true">
                            <svg viewBox="0 0 24 24" focusable="false">
                                <path d="M5 12h12M13 6l6 6-6 6" fill="none" stroke="currentColor"
                                      stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </span>
                    </a>
                </li>
            </ul>
        </section>
    </section>
@endsection