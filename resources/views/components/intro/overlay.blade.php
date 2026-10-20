@props(['enabled' => true])

@if($enabled)
    {{--
        ABO Cinematic Intro — "Slash + Zip Reveal" (6.5s master timeline)
        Markup only — all motion, geometry and timing are driven by intro.js
        via a single rAF loop so the timeline can be paused / scrubbed / skipped.
    --}}
    <div
        class="abo-intro"
        data-abo-intro
        role="presentation"
        aria-hidden="true"
    >
        {{-- Black screen holder (covers the page while the intro runs) --}}
        <div class="abo-intro__veil" aria-hidden="true"></div>

        {{-- Two split layers that open as the zipper unzips --}}
        <div class="abo-intro__layer abo-intro__layer--left"  data-abo-intro-layer="left"  aria-hidden="true"></div>
        <div class="abo-intro__layer abo-intro__layer--right" data-abo-intro-layer="right" aria-hidden="true"></div>

        {{-- Lime light bleed behind the diagonal line --}}
        <div class="abo-intro__bleed" aria-hidden="true"></div>

        {{-- Zipper geometry: edge polylines + teeth + puller --}}
        <svg
            class="abo-intro__zipper"
            viewBox="0 0 100 100"
            preserveAspectRatio="none"
            aria-hidden="true"
        >
            <polyline
                class="abo-intro__zipper-edge abo-intro__zipper-edge--left"
                data-abo-intro-edge="left"
                points=""
            />
            <polyline
                class="abo-intro__zipper-edge abo-intro__zipper-edge--right"
                data-abo-intro-edge="right"
                points=""
            />
            <line
                class="abo-intro__zipper-teeth"
                data-abo-intro-teeth
                x1="0" y1="0" x2="0" y2="0"
            />
        </svg>

        {{-- Lime "puller" tag that travels down the diagonal --}}
        <div class="abo-intro__puller" data-abo-intro-puller aria-hidden="true">
            <span class="abo-intro__puller-label">ABO</span>
        </div>

        {{-- The ABO logo (revealed through the opening) --}}
        <div class="abo-intro__logo-wrap" data-abo-intro-logo>
            <div class="abo-intro__logo-glow" aria-hidden="true"></div>
            <img
                class="abo-intro__logo"
                src="{{ asset('images/abo-logo.svg') }}"
                alt=""
                width="220"
                height="220"
            >
        </div>

        {{-- Tagline — typed in character by character --}}
        <p
            class="abo-intro__tagline"
            data-abo-intro-tagline
            aria-hidden="true"
        >
            {{-- 21 spans, one per character of "NOT MADE TO BLEND IN." --}}
            @foreach (str_split('NOT MADE TO BLEND IN.') as $char)
                <span class="abo-intro__tagline-char">{{ $char === ' ' ? "\u{00A0}" : $char }}</span>
            @endforeach
            <span class="abo-intro__tagline-sweep" data-abo-intro-sweep aria-hidden="true"></span>
        </p>

        {{-- Skip button — the only focusable element during the intro --}}
        <button
            type="button"
            class="abo-intro__skip"
            data-abo-intro-skip
            tabindex="-1"
            aria-label="Skip intro"
        >
            <span>Skip</span>
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M5 12h12M13 6l6 6-6 6" fill="none" stroke="currentColor"
                      stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </button>
    </div>
@endif
