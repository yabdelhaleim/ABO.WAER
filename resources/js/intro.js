// ABO Cinematic Intro — "Slash + Zip Reveal"
// ------------------------------------------------------------------
// 6.5s master timeline driven by a single requestAnimationFrame loop.
// The timeline can be paused, scrubbed, and skipped cleanly. No GSAP —
// the WAAPI would scatter state across many Animation objects, making
// scrub/skip fragile. A rAF loop keeps a single `elapsed` value as the
// source of truth.
//
// Dev flags:
//   ?intro=1   — force the intro to play this session.
//   ?intro=0   — disable the intro entirely for this load.
//   ?intro=t<ms> — scrub to a specific time (debug only).
//
// Storage key:  abo_intro_seen   (set in sessionStorage once finished).

const STORAGE_KEY = 'abo_intro_seen';
const TOTAL_MS = 6500;
const FALLBACK_MS = 600;

// Phase boundaries in milliseconds (from spec section 3).
const PHASE = {
    BLACK:        [0,    800],
    LINE_DRAW:    [800,  1600],
    ZIPPER_FORM:  [1600, 2000],
    PULLER_TRAVEL:[2000, 4400],
    LOGO_FADE:    [3000, 4600],
    HALVES_SLIDE: [4400, 5400],
    LOGO_FADE_OUT:[4700, 5200],
    TAGLINE_TYPE: [4800, 5800],
    TAGLINE_OUT:  [5800, 6200],
    SETTLE:       [5800, 6500],
};

const TAGLINE = 'NOT MADE TO BLEND IN.';
const TAGLINE_LEN = TAGLINE.length; // 21
const LINE_SAMPLES = 21;            // y from 0 to 100 in 5% steps

// ─── Helpers ────────────────────────────────────────────────────────

const clamp = (v, lo, hi) => Math.min(Math.max(v, lo), hi);
const clamp01 = (v) => clamp(v, 0, 1);
const lerp = (a, b, t) => a + (b - a) * t;

const ease = {
    linear:     (t) => t,
    power2in:   (t) => t * t,
    power2out:  (t) => 1 - Math.pow(1 - t, 2),
    power2inOut:(t) => (t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2),
    power3inOut:(t) => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2),
};

function inRange(elapsed, [start, end]) {
    return clamp01((elapsed - start) / (end - start));
}

function isMobilePortrait() {
    return window.matchMedia('(max-width: 767px)').matches;
}

function prefersReducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function pointsToAttr(points) {
    return points.map(([x, y]) => `${x.toFixed(3)},${y.toFixed(3)}`).join(' ');
}

function pointsToLeftPolygon(points) {
    // points: [x, y] from top to bottom (left edge of the opening)
    // polygon(0% 0%, x0 y0, x1 y1, ..., 0% 100%)
    const head = points.map(([x, y]) => `${x.toFixed(3)}% ${y.toFixed(3)}%`).join(', ');
    return `polygon(0% 0%, ${head}, 0% 100%)`;
}

function pointsToRightPolygon(points) {
    // polygon(x0 y0, x1 y1, ..., 100% 100%, 100% 0%)
    const head = points.map(([x, y]) => `${x.toFixed(3)}% ${y.toFixed(3)}%`).join(', ');
    return `polygon(${head}, 100% 100%, 100% 0%)`;
}

// ─── Geometry ────────────────────────────────────────────────────────

function getGeometry(elapsed) {
    const top = isMobilePortrait() ? 65 : 62;
    const bottom = isMobilePortrait() ? 35 : 38;

    // Puller progress p: 0 → 1 across 2.0s → 4.4s (PULLER_TRAVEL).
    const p = ease.power2inOut(inRange(elapsed, PHASE.PULLER_TRAVEL));

    // "open" — how much the two halves have slid off screen, after the
    // puller reaches the bottom. power3inOut over 4.4s → 5.4s.
    const open = ease.power3inOut(inRange(elapsed, PHASE.HALVES_SLIDE));

    const seamAt = (y) => top + (bottom - top) * (y / 100);

    // Gap at height y while zipping (spec section 4).
    // g(y) = wmax * clamp(p*1.5) * (1 - y / (p*100 + 0.001))
    // After the puller reaches the bottom, an extra open*190 widens the gap
    // so both halves exit the screen.
    const wmax = 30;
    const pClamped = clamp(p * 1.5, 0, 1);
    const denom = Math.max(p * 100, 0.001);
    const gapAt = (y) => {
        const base = wmax * pClamped * (1 - y / (denom + 0.001));
        return Math.max(0, base) + open * 190;
    };

    const leftEdge = [];
    const rightEdge = [];
    for (let i = 0; i <= LINE_SAMPLES; i++) {
        const y = (i / LINE_SAMPLES) * 100;
        const s = seamAt(y);
        const g = gapAt(y);
        leftEdge.push([s - g / 2, y]);
        rightEdge.push([s + g / 2, y]);
    }

    return {
        p,
        open,
        top,
        bottom,
        leftEdge,
        rightEdge,
        pullerX: top - (top - bottom) * p,
        pullerY: p * 100,
    };
}

// ─── State application (called every frame) ─────────────────────────

function applyState(elapsed, els) {
    const g = getGeometry(elapsed);

    // ── Skip button (visible from 0.3s, fades in over 200ms)
    const skipOp = ease.power2out(inRange(elapsed, [300, 500]));
    els.skip.style.opacity = String(skipOp);
    if (skipOp > 0 && els.skip.dataset.aboIntroFocusable !== '1') {
        els.skip.removeAttribute('tabindex');
        els.skip.dataset.aboIntroFocusable = '1';
    }

    // ── Diagonal line draw (0.8 → 1.6s) + bleed. The line and the edge
    // polylines are the same visual element — keep a single opacity
    // expression so we never overwrite the line's opacity with the
    // edges' "visible after 1.7s" calculation.
    const lineProg = ease.power2inOut(inRange(elapsed, PHASE.LINE_DRAW));
    const lineOpacity = clamp01((elapsed - 600) / 200) - clamp01((elapsed - 5200) / 200);
    els.line.style.opacity = String(clamp01(lineOpacity));
    els.edgeLeft.style.opacity = String(clamp01(lineOpacity));
    els.edgeRight.style.opacity = String(clamp01(lineOpacity));
    // stroke-dasharray + offset give the "drawing in" effect. Length is
    // an approximation of the diagonal — clamped by `pathLength` already
    // baked into the markup, so we just need a large enough number.
    els.line.style.strokeDasharray = '600';
    els.line.style.strokeDashoffset = String(600 * (1 - lineProg));

    // Bleed follows the same diagonal — a faint blurred glow
    els.bleed.style.opacity = String(clamp01(lineOpacity) * 0.6);
    els.bleed.style.transform = `rotate(${isMobilePortrait() ? '-23.5deg' : '-21deg'}) scale(${1 + lineProg * 0.4})`;

    // Edges are the same polylines as the line — keep their `points`
    // attribute in sync so the geometry matches the visual state.
    els.edgeLeft.setAttribute('points', pointsToAttr(g.leftEdge));
    els.edgeRight.setAttribute('points', pointsToAttr(g.rightEdge));

    // ── Teeth: dashed line from puller position straight down (spec says
    // teeth exist only BELOW the puller).
    const teethOpacity = clamp01((elapsed - 1700) / 200) * (1 - clamp01((elapsed - 4400) / 200));
    els.teeth.setAttribute('x1', String(g.pullerX));
    els.teeth.setAttribute('y1', String(g.pullerY));
    els.teeth.setAttribute('x2', String(g.pullerX));
    els.teeth.setAttribute('y2', '100');
    els.teeth.style.opacity = String(clamp01(teethOpacity));

    // ── Puller: appears at 1.6s, follows diagonal 2.0 → 4.4s, fades 4.4 → 4.7
    const pullerOp =
        clamp01((elapsed - 1600) / 200) *
        (1 - clamp01((elapsed - 4400) / 300));
    els.puller.style.opacity = String(clamp01(pullerOp));
    els.puller.style.left = `${g.pullerX}%`;
    els.puller.style.top = `${g.pullerY}%`;

    // ── Two split layers
    els.leftLayer.style.clipPath = pointsToLeftPolygon(g.leftEdge);
    els.rightLayer.style.clipPath = pointsToRightPolygon(g.rightEdge);

    // ── Logo: fade in 3.0 → 4.6s (scale 0.90 → 1.00), fade out 4.7 → 5.2s
    const logoIn = ease.power2out(inRange(elapsed, PHASE.LOGO_FADE));
    const logoOut = 1 - ease.power2inOut(inRange(elapsed, PHASE.LOGO_FADE_OUT));
    const logoScale = 0.9 + 0.1 * ease.power2out(inRange(elapsed, [3000, 4600]));
    els.logoWrap.style.opacity = String(logoIn * logoOut);
    els.logoWrap.style.transform = `translate(-50%, -50%) scale(${logoScale.toFixed(3)})`;

    // Glow pulses once during 3.0 → 4.6s
    const glowPulse = Math.sin(inRange(elapsed, [3000, 4600]) * Math.PI);
    els.logoGlow.style.opacity = String(glowPulse * 0.22);

    // ── Tagline typing 4.8 → 5.8s, fade out 5.8 → 6.2s
    const typeProg = inRange(elapsed, PHASE.TAGLINE_TYPE);
    const visibleChars = Math.floor(typeProg * TAGLINE_LEN);
    for (let i = 0; i < els.taglineChars.length; i++) {
        els.taglineChars[i].style.opacity = i < visibleChars ? '1' : '0';
    }
    const taglineOut = 1 - ease.power2inOut(inRange(elapsed, PHASE.TAGLINE_OUT));
    els.tagline.style.opacity = String(taglineOut);

    // Lime light sweep across the tagline, ~ once during 4.8 → 5.8s.
    const sweepProg = inRange(elapsed, [4800, 5800]);
    els.sweep.style.opacity = String(sweepProg > 0 && sweepProg < 1 ? '1' : '0');
    els.sweep.style.backgroundPosition = `${100 - sweepProg * 130}% 0%`;

    // ── Reveal handoff (spec §9). The Home hero owns its own scale
    // animation; this intro only signals the exact T0 = 4400ms moment.
    // We dispatch on the first frame past 4400ms — the rest of this
    // frame still finishes drawing the intro. The home page listens
    // to `abo:intro-reveal` and runs its own 2.6s timeline.
    if (!els.overlay.dataset.aboIntroRevealed && elapsed >= 4400) {
        els.overlay.dataset.aboIntroRevealed = '1';
        window.dispatchEvent(new Event('abo:intro-reveal'));
    }
}

// ─── Reduced-motion fallback ────────────────────────────────────────

function playReducedMotion(els) {
    // 0.6s: logo fades in then out, then hero reveals.
    els.skip.style.opacity = '1';
    els.skip.removeAttribute('tabindex');
    els.skip.dataset.aboIntroFocusable = '1';

    const start = performance.now();
    const step = (now) => {
        const e = now - start;
        const inProg = clamp01(e / 300);
        const outProg = clamp01((e - 300) / 300);
        const opacity = inProg * (1 - outProg);
        els.logoWrap.style.opacity = String(opacity);
        els.logoWrap.style.transform = `translate(-50%, -50%) scale(${(0.92 + 0.08 * inProg).toFixed(3)})`;
        els.logoGlow.style.opacity = String(inProg * (1 - outProg) * 0.22);

        if (e < FALLBACK_MS) {
            requestAnimationFrame(step);
        } else {
            finish(els, /* skipped */ false, /* fromFallback */ true);
        }
    };
    requestAnimationFrame(step);
}

// ─── Master timeline driver ─────────────────────────────────────────

function runTimeline(els, scrubMs) {
    const start = performance.now();
    let elapsed = typeof scrubMs === 'number' ? scrubMs : 0;
    let rafId = 0;
    let ended = false;

    const tick = (now) => {
        if (ended) return;
        if (typeof scrubMs === 'number') {
            // Scrubbing: don't advance time, just render.
            applyState(elapsed, els);
            return;
        }
        elapsed = now - start;
        if (elapsed >= TOTAL_MS) {
            elapsed = TOTAL_MS;
            applyState(elapsed, els);
            finish(els, false, false);
            return;
        }
        applyState(elapsed, els);
        rafId = requestAnimationFrame(tick);
    };

    // Skip handler: jump to end and fade out.
    const onSkip = () => {
        if (ended) return;
        ended = true;
        cancelAnimationFrame(rafId);
        finish(els, true, false);
    };

    els.skip.addEventListener('click', onSkip, { once: true });
    const onKey = (ev) => {
        if (ev.key === 'Escape' || ev.key === 'Enter' || ev.key === ' ') {
            ev.preventDefault();
            onSkip();
        }
    };
    document.addEventListener('keydown', onKey);

    // If the tab becomes hidden, end the intro so it never reappears on
    // return (the hero is already revealed underneath).
    const onVisibility = () => {
        if (document.hidden) onSkip();
    };
    document.addEventListener('visibilitychange', onVisibility);

    // Store cleanup hooks on the element so finish() can release them.
    els.overlay._aboIntroCleanup = () => {
        document.removeEventListener('keydown', onKey);
        document.removeEventListener('visibilitychange', onVisibility);
    };

    rafId = requestAnimationFrame(tick);
}

function finish(els, skipped, fromFallback) {
    if (els.overlay.dataset.aboIntroEnded === '1') return;
    els.overlay.dataset.aboIntroEnded = '1';

    if (typeof els.overlay._aboIntroCleanup === 'function') {
        els.overlay._aboIntroCleanup();
    }

    // Reveal hero fully (home page owns the actual scale + entrance).
    if (els.hero) {
        els.hero.style.opacity = '1';
    }

    els.veil.style.opacity = '1'; // start fade-out
    // We use a 400ms transition on the overlay, so flip visibility hidden
    // after it lands.
    els.overlay.style.opacity = '0';
    document.documentElement.classList.remove('abo-intro-lock');

    // Move focus to the page's main content (a11y requirement).
    const main = document.querySelector('main#content') || document.querySelector('main');
    if (main) {
        if (!main.hasAttribute('tabindex')) {
            main.setAttribute('tabindex', '-1');
        }
        // Defer focus until the overlay is fully gone.
        setTimeout(() => {
            try { main.focus({ preventScroll: true }); } catch (e) { main.focus(); }
        }, 420);
    }

    setTimeout(() => {
        if (els.overlay.parentNode) {
            els.overlay.parentNode.removeChild(els.overlay);
        }
    }, 420);

    if (!fromFallback) {
        try { sessionStorage.setItem(STORAGE_KEY, '1'); } catch (e) {}
    }
}

// ─── Public init ────────────────────────────────────────────────────

function shouldPlay() {
    try {
        const params = new URLSearchParams(window.location.search);
        const flag = params.get('intro');
        if (flag === '0') return { play: false, scrub: null, forced: false };
        if (flag && flag.startsWith('t')) {
            document.documentElement.classList.add('force-intro');
            return { play: true, scrub: parseInt(flag.slice(1), 10) || 0, forced: true };
        }
        if (flag === '1' || window.location.hash === '#intro') {
            document.documentElement.classList.add('force-intro');
            return { play: true, scrub: null, forced: true };
        }
        // Play once per session so navigating between pages stays light and fast
        if (sessionStorage.getItem(STORAGE_KEY) === '1') {
            return { play: false, scrub: null, forced: false };
        }
    } catch (e) { /* fall through */ }
    return { play: true, scrub: null, forced: false };
}

function preloadAssets() {
    // Spec: start loading the Hero image and fonts at page start so the
    // reveal at 4.4s is instant with zero layout shift. If the Hero isn't
    // ready when the reveal starts, hold the last frame (max 2s) until it
    // is. We use <link rel="preload"> in the layout for the hero photo;
    // the fonts are handled by the bunny.net Vite plugin via @font-face.
    // As a belt-and-braces, kick off a JS Image() load here too.
    const heroImg = document.querySelector('.store-hero__photo, [data-home-photo], .hero__photo');
    if (heroImg && heroImg.src) {
        const im = new Image();
        im.src = heroImg.src;
    }
}

function initIntro() {
    const overlay = document.querySelector('[data-abo-intro]');
    if (!overlay) return;

    // Expose developer / user replay helper
    window.replayIntro = () => {
        sessionStorage.removeItem(STORAGE_KEY);
        window.location.href = window.location.pathname + '?intro=1';
    };

    const decision = shouldPlay();
    if (!decision.play) {
        overlay.remove();
        // No intro this load — the home page still plays its own
        // entrance animation from the abo:home-reveal signal it
        // dispatches on DOMContentLoaded. Make sure the hero is
        // visible to the home JS so the scale 1.12 → 1.00 lands.
        const hero = document.querySelector('.store-hero, [data-home-hero], .hero');
        if (hero) {
            hero.style.opacity = '1';
        }
        return;
    }

    const els = {
        overlay,
        veil:      overlay.querySelector('.abo-intro__veil'),
        leftLayer: overlay.querySelector('[data-abo-intro-layer="left"]'),
        rightLayer:overlay.querySelector('[data-abo-intro-layer="right"]'),
        line:      overlay.querySelector('[data-abo-intro-edge="left"]'),
        edgeLeft:  overlay.querySelector('[data-abo-intro-edge="left"]'),
        edgeRight: overlay.querySelector('[data-abo-intro-edge="right"]'),
        teeth:     overlay.querySelector('[data-abo-intro-teeth]'),
        puller:    overlay.querySelector('[data-abo-intro-puller]'),
        logoWrap:  overlay.querySelector('[data-abo-intro-logo]'),
        logoGlow:  overlay.querySelector('.abo-intro__logo-glow'),
        tagline:   overlay.querySelector('[data-abo-intro-tagline]'),
        taglineChars: overlay.querySelectorAll('.abo-intro__tagline-char'),
        sweep:     overlay.querySelector('[data-abo-intro-sweep]'),
        bleed:     overlay.querySelector('.abo-intro__bleed'),
        skip:      overlay.querySelector('[data-abo-intro-skip]'),
        hero:      document.querySelector('.store-hero, [data-home-hero], .hero'),
    };

    // The home page sets its own hero initial state (opacity:0,
    // scale:1.12) so the two timelines never fight. We just hand off.

    // Lock body scroll while the intro runs.
    document.documentElement.classList.add('abo-intro-lock');

    preloadAssets();

    if (prefersReducedMotion() && !decision.forced) {
        // The visitor prefers reduced motion and didn't force the intro
        // via ?intro=1 or ?intro=t<ms>. Use the 0.6s logo-fade fallback.
        playReducedMotion(els);
        return;
    }

    runTimeline(els, decision.scrub);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initIntro);
} else {
    initIntro();
}
