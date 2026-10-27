@extends('layouts.auth')

@section('title', 'Admin Sign In — ABO Portal')

@section('content')
    <main class="abo-auth" data-reduced-motion="respect">
        {{-- ── Left visual panel ────────────────────────────────── --}}
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
                    <span>PORTAL</span><span class="abo-auth__tag-sep">/</span>
                    <span>MANAGEMENT</span><span class="abo-auth__tag-sep">/</span>
                    <span>OPERATIONS</span>
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
                    <p data-anim="rise" data-anim-delay="360">COMMAND CENTER.</p>
                    <p data-anim="rise" data-anim-delay="460">OPERATIONAL CONTROL.</p>
                </div>

                <div class="abo-auth__supporting">
                    <p class="abo-auth__supporting-line" data-anim="fade-up" data-anim-delay="560">
                        Authorized administrative personnel only.
                    </p>
                    <p class="abo-auth__supporting-line" data-anim="fade-up" data-anim-delay="600">
                        Access store catalog, fulfilment, orders, campaigns and real-time revenue analytics.
                    </p>
                </div>

                <p class="abo-auth__slogan" data-anim="rise" data-anim-delay="680">
                    <span>NOT MADE TO BLEND IN.</span>
                </p>
            </div>
        </section>

        {{-- ── Right form panel ─────────────────────────────────── --}}
        <section class="abo-auth__panel" aria-label="Admin Sign in to your account">
            <header class="abo-auth__panel-head">
                <a href="{{ route('home') }}" class="abo-auth__panel-logo" aria-label="ABO home">
                    <img src="{{ asset('images/abo-logo.svg') }}" alt="ABO" width="48" height="48">
                </a>
                <p class="abo-auth__panel-eyebrow" data-anim="fade-up" data-anim-delay="60">
                    <span class="abo-auth__panel-eyebrow-line"></span>
                    <span>SECURE PORTAL</span>
                </p>
            </header>

            <div class="abo-auth__panel-body">
                <div class="abo-auth__form">
                    <p class="abo-auth__welcome" data-anim="rise" data-anim-delay="80">ADMINISTRATOR</p>

                    <h2 class="abo-auth__heading" data-anim="rise" data-anim-delay="160">
                        <span class="abo-auth__heading-line">SIGN IN TO</span>
                        <span class="abo-auth__heading-line abo-auth__heading-line--accent">DASHBOARD</span>
                    </h2>

                    <p class="abo-auth__lede" data-anim="fade-up" data-anim-delay="240">
                        Enter your administrative credentials to access operations.
                    </p>

                    @if($errors->any() && !$errors->has('email') && !$errors->has('password'))
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

                    <form method="POST" action="{{ route('admin.login') }}" class="abo-auth__form" novalidate>
                        @csrf

                        <div data-anim="rise" data-anim-delay="300">
                            <x-auth.field
                                name="email"
                                type="email"
                                :value="old('email')"
                                placeholder="admin@abostore.com"
                                icon="envelope"
                                autocomplete="email"
                                inputmode="email"
                                :autofocus="!$errors->any()"
                            />
                        </div>

                        <div data-anim="rise" data-anim-delay="380">
                            <x-auth.password-field name="password" placeholder="Password" />
                        </div>

                        <div class="abo-auth__form-row" data-anim="fade-up" data-anim-delay="440">
                            <x-auth.checkbox name="remember" label="Remember me" />

                            <a href="{{ route('admin.password.request') }}" class="abo-auth__link">
                                Forgot password?
                            </a>
                        </div>

                        <div data-anim="rise" data-anim-delay="500">
                            <x-auth.primary-button>
                                <span>ACCESS DASHBOARD</span>
                                <svg viewBox="0 0 24 24" class="auth-btn__arrow" aria-hidden="true" focusable="false">
                                    <path d="M5 12h12M13 6l6 6-6 6" fill="none" stroke="currentColor"
                                          stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </x-auth.primary-button>
                        </div>

                        <p class="abo-auth__register" data-anim="fade-up" data-anim-delay="580">
                            Are you a customer?
                            <a href="{{ route('login') }}" class="abo-auth__link">
                                <span>Store Sign In</span>
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
