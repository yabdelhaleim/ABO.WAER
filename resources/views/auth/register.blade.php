@extends('layouts.auth')

@section('title', 'Join the ABO community')

@section('content')
    <main class="abo-auth" data-reduced-motion="respect">
        <section class="abo-auth__visual" aria-label="ABO brand experience">
            <div class="abo-auth__media">
                <img
                    src="{{ asset('images/streetwear-hero.jpg') }}"
                    alt="A man in dark urban streetwear, captured in cinematic monochrome light."
                    class="abo-auth__photo"
                    loading="eager"
                    decoding="async"
                    width="1400"
                    height="2010"
                >
                <div class="abo-auth__media-overlay" aria-hidden="true"></div>
                <div class="abo-auth__media-grid" aria-hidden="true"></div>
            </div>

            <div class="abo-auth__visual-content">
                <a href="{{ route('home') }}" class="abo-auth__brand" aria-label="ABO home">
                    <img src="{{ asset('images/abo-logo.svg') }}" alt="ABO" class="abo-auth__logo" width="64" height="64">
                </a>

                <p class="abo-auth__tag" data-anim="fade-up" data-anim-delay="120">
                    <span>URBAN</span><span class="abo-auth__tag-sep">/</span>
                    <span>STREETWEAR</span><span class="abo-auth__tag-sep">/</span>
                    <span>LIFESTYLE</span>
                </p>

                <h1 class="abo-auth__wordmark" data-anim="rise" data-anim-delay="220" aria-label="ABO">
                    <span class="abo-auth__wordmark-letter">A</span>
                    <span class="abo-auth__wordmark-letter">B</span>
                    <span class="abo-auth__wordmark-letter abo-auth__wordmark-letter--o">O</span>
                </h1>

                <div class="abo-auth__strokes" aria-hidden="true">
                    <span class="abo-auth__stroke abo-auth__stroke--1"></span>
                    <span class="abo-auth__stroke abo-auth__stroke--2"></span>
                    <span class="abo-auth__stroke abo-auth__stroke--3"></span>
                </div>

                <div class="abo-auth__headline">
                    <p data-anim="rise" data-anim-delay="360">MODERN FIT.</p>
                    <p data-anim="rise" data-anim-delay="460">URBAN VIBE.</p>
                </div>

                <div class="abo-auth__supporting">
                    <p class="abo-auth__supporting-line" data-anim="fade-up" data-anim-delay="560">
                        PREMIUM MEN'S WEAR
                    </p>
                    <p class="abo-auth__supporting-line" data-anim="fade-up" data-anim-delay="600">
                        BUILT FOR THE NEXT GENERATION.
                    </p>
                </div>

                <p class="abo-auth__slogan" data-anim="rise" data-anim-delay="680">
                    Better<br>Than<br>Yesterday
                </p>

                <span class="abo-auth__est" aria-hidden="true">EST. 2024</span>
            </div>

            <p class="abo-auth__copyright" data-anim="fade-up" data-anim-delay="780">
                © 2024 ABO. ALL RIGHTS RESERVED.
            </p>
        </section>

        <section class="abo-auth__panel" aria-label="Create your ABO account">
            <header class="abo-auth__panel-head">
                <a href="{{ route('home') }}" class="abo-auth__panel-logo" aria-label="ABO home">
                    <img src="{{ asset('images/abo-logo.svg') }}" alt="ABO" width="48" height="48">
                </a>
                <p class="abo-auth__panel-eyebrow" data-anim="fade-up" data-anim-delay="60">
                    <span class="abo-auth__panel-eyebrow-line"></span>
                    <span>CREATE YOUR STYLE</span>
                </p>
            </header>

            <div class="abo-auth__panel-body">
                <div class="abo-auth__form">
                    <p class="abo-auth__welcome" data-anim="rise" data-anim-delay="80">JOIN ABO TODAY</p>

                    <h2 class="abo-auth__heading abo-auth__heading--join" data-anim="rise" data-anim-delay="160">
                        <span class="abo-auth__heading-line">JOIN THE ABO</span>
                        <span class="abo-auth__heading-line abo-auth__heading-line--accent">COMMUNITY</span>
                    </h2>

                    <p class="abo-auth__lede" data-anim="fade-up" data-anim-delay="240">
                        Create your account and discover new collections, exclusive offers, and more.
                    </p>

                    @if($errors->any() && !$errors->has('name') && !$errors->has('email') && !$errors->has('phone') && !$errors->has('password') && !$errors->has('password_confirmation') && !$errors->has('terms'))
                        <div class="abo-auth__alert" role="alert" data-anim="fade-up">
                            <ul>
                                @foreach($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if(session('status'))
                        <div class="abo-auth__alert abo-auth__alert--success" role="status" data-anim="fade-up">
                            {{ session('status') }}
                        </div>
                    @endif

                    @php
                        $googleEnabled = filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
                        $termsHref = '#';
                        $termsAvailable = Route::has('terms') || Route::has('terms.show') || Route::has('pages.terms');
                    @endphp

                    <form method="POST" action="{{ route('register') }}" class="abo-auth__form" data-login-form novalidate>
                        @csrf

                        <div data-anim="rise" data-anim-delay="300">
                            <x-auth.field
                                name="name"
                                type="text"
                                :value="old('name')"
                                placeholder="Full Name"
                                icon="user"
                                autocomplete="name"
                                :autofocus="!$errors->any()"
                            />
                        </div>

                        <div data-anim="rise" data-anim-delay="360">
                            <x-auth.field
                                name="email"
                                type="email"
                                :value="old('email')"
                                placeholder="Email Address"
                                icon="envelope"
                                autocomplete="email"
                                inputmode="email"
                            />
                        </div>

                        <div data-anim="rise" data-anim-delay="420">
                            <x-auth.field
                                name="phone"
                                type="tel"
                                :value="old('phone')"
                                placeholder="Phone Number"
                                icon="phone"
                                autocomplete="tel"
                                inputmode="tel"
                            />
                        </div>

                        <div data-anim="rise" data-anim-delay="480">
                            <x-auth.password-field
                                name="password"
                                label="Password"
                                placeholder="Password"
                                autocomplete="new-password"
                            />
                        </div>

                        <div data-anim="rise" data-anim-delay="520">
                            <x-auth.password-field
                                name="password_confirmation"
                                label="Confirm Password"
                                placeholder="Confirm Password"
                                autocomplete="new-password"
                            />
                        </div>

                        <div data-anim="fade-up" data-anim-delay="560">
                            <label class="auth-checkbox auth-terms">
                                <input
                                    type="checkbox"
                                    name="terms"
                                    value="1"
                                    @checked(old('terms'))
                                    required
                                />
                                <span class="auth-checkbox__box" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" focusable="false">
                                        <path d="M9.6 16.6 5.4 12.4 4 13.8l5.6 5.6 11-11L19.2 7z" fill="currentColor"/>
                                    </svg>
                                </span>
                                <span class="auth-checkbox__label">
                                    I agree to the
                                    @if(Route::has('terms'))
                                        <a href="{{ route('terms') }}" class="abo-auth__link abo-auth__link--inline">Terms of Service</a>
                                    @else
                                        <span class="abo-auth__link abo-auth__link--inline abo-auth__link--muted"
                                              title="Terms of Service page is not configured.">Terms of Service</span>
                                    @endif
                                    and
                                    @if(Route::has('privacy'))
                                        <a href="{{ route('privacy') }}" class="abo-auth__link abo-auth__link--inline">Privacy Policy</a>
                                    @else
                                        <span class="abo-auth__link abo-auth__link--inline abo-auth__link--muted"
                                              title="Privacy Policy page is not configured.">Privacy Policy</span>
                                    @endif
                                </span>
                            </label>
                        </div>

                        <div data-anim="rise" data-anim-delay="600">
                            <x-auth.primary-button loading-text="Creating account…">
                                <span>CREATE ACCOUNT</span>
                                <svg viewBox="0 0 24 24" class="auth-btn__arrow" aria-hidden="true" focusable="false">
                                    <path d="M5 12h12M13 6l6 6-6 6" fill="none" stroke="currentColor"
                                          stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </x-auth.primary-button>
                        </div>

                        <div class="abo-auth__divider" data-anim="fade-up" data-anim-delay="660" aria-hidden="true">
                            <span></span><em>OR</em><span></span>
                        </div>

                        <div data-anim="rise" data-anim-delay="720">
                            <x-auth.google-button :enabled="$googleEnabled" :href="route('auth.google')" />
                        </div>

                        <p class="abo-auth__register" data-anim="fade-up" data-anim-delay="780">
                            Already have an account?
                            <a href="{{ route('login') }}" class="abo-auth__link">
                                <span>Login</span>
                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                    <path d="M5 12h12M13 6l6 6-6 6" fill="none" stroke="currentColor"
                                          stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </a>
                        </p>
                    </form>
                </div>
            </div>

            <span class="abo-auth__corner" aria-hidden="true"></span>
        </section>
    </main>
@endsection

@push('scripts')
    <script>
        (function () {
            const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            document.documentElement.classList.toggle('is-reduced-motion', reduce);
        })();
    </script>
@endpush
