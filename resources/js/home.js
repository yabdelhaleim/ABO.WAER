// ABO Home — master entrance timeline + scroll/interaction behaviors.
// ----------------------------------------------------------------
// Spec section 4: 2.6s master timeline, no setTimeout chains.
// Spec section 5: scroll reveal, sticky header, mouse parallax, count-up.
//
// T0 trigger priority:
//   1. window 'abo:intro-reveal' (dispatched by intro.js at 4.4s)
//   2. window 'abo:home-reveal'  (dispatched here on DOMContentLoaded
//      when the intro is disabled / skipped / already seen)
// The timeline is the same in both cases.

import { apiRequest } from './cart-client.js';

const HOME_TOTAL_MS = 2600;
const REDUCED_HOME_MS = 400;

const T = {
    HEADER:    [100, 800],
    EYEBROW:   [300, 1000],
    H1_LINE_1: [500, 1400],
    H1_LINE_2: [680, 1580],
    SWEEP:     [800, 2100],
    PARAGRAPH: [1150, 1950],
    CTAS:      [1350, 2050],
    STATS:     [1700, 2600],
    PHOTO:     [0, 1900], // T0 is the reveal moment
};

// ─── Helpers ────────────────────────────────────────────────────

const clamp = (v, lo, hi) => Math.min(Math.max(v, lo), hi);
const clamp01 = (v) => clamp(v, 0, 1);

const ease = {
    linear:     (t) => t,
    power2in:   (t) => t * t,
    power2out:  (t) => 1 - Math.pow(1 - t, 2),
    power2inOut:(t) => (t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2),
    power3inOut:(t) => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2),
    power4out:  (t) => 1 - Math.pow(1 - t, 4),
};

const inRange = (elapsed, range) => clamp01((elapsed - range[0]) / (range[1] - range[0]));

function prefersReducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function isTouch() {
    return 'ontouchstart' in window || navigator.maxTouchPoints > 0;
}

// ─── Hero entrance timeline ────────────────────────────────────

function initHero() {
    const root = document.querySelector('[data-home-hero]');
    if (!root) return;

    const els = {
        header:    document.querySelector('.store-header'),
        photo:     root.querySelector('[data-home-photo]'),
        photoWrap: root.querySelector('[data-home-photo-wrap]'),
        sweep:     root.querySelector('[data-home-sweep]'),
        eyebrow:   root.querySelector('[data-home-eyebrow]'),
        eyebrowLine: root.querySelector('[data-home-eyebrow-line]'),
        h1Lines:   root.querySelectorAll('[data-home-h1-line]'),
        paragraph: root.querySelector('[data-home-paragraph]'),
        ctas:      root.querySelectorAll('[data-home-cta]'),
        stats:     root.querySelectorAll('[data-home-stat]'),
        statValues: root.querySelectorAll('[data-home-stat-value]'),
    };

    // Apply final visible state for no-JS / reduced-motion fallback.
    if (prefersReducedMotion()) {
        root.classList.add('is-reduced');
        // Count-up still runs in reduced motion (no motion sickness).
        runCountUp(els);
        return;
    }

    if (isTouch()) {
        root.classList.add('is-touch');
    }

    // Snapshot the final stat numbers from the markup so we can count
    // up from 0 without an extra config file.
    const finalStats = Array.from(els.statValues).map((el) => el.textContent);

    function start() {
        const startTime = performance.now();
        // On touch devices the 2.6s entrance is imperceptibly long and the
        // user may not wait. Scale the whole timeline to 600ms instead so
        // the text is visible within half a second of the reveal event.
        const mobileDuration = 600;
        const timeScale = isTouch() ? (HOME_TOTAL_MS / mobileDuration) : 1;
        const raf = { id: 0, done: false };

        function tick(now) {
            if (raf.done) return;
            const elapsed = (now - startTime) * timeScale;
            if (elapsed >= HOME_TOTAL_MS) {
                applyFrame(HOME_TOTAL_MS, els, finalStats, /* countUp */ true);
                finalize(els);
                return;
            }
            applyFrame(elapsed, els, finalStats, /* countUp */ true);
            raf.id = requestAnimationFrame(tick);
        }

        raf.id = requestAnimationFrame(tick);
    }

    // Listen for the intro's reveal event. If the intro never runs we
    // dispatch abo:home-reveal ourselves on DOMContentLoaded.
    if (root.dataset.homeRevealed === '1') {
        start();
    } else {
        const onReveal = () => {
            root.dataset.homeRevealed = '1';
            window.removeEventListener('abo:intro-reveal', onReveal);
            window.removeEventListener('abo:home-reveal', onReveal);
            start();
        };
        window.addEventListener('abo:intro-reveal', onReveal, { once: true });
        window.addEventListener('abo:home-reveal', onReveal, { once: true });

        // Safety fallback: if reveal doesn't fire within 600ms, force start & finalize
        setTimeout(() => {
            if (root.dataset.homeRevealed !== '1') {
                root.dataset.homeRevealed = '1';
                finalize(els);
            }
        }, 600);
    }
}

function applyFrame(elapsed, els, finalStats, countUp) {
    // ── Header slide down 14px + fade
    if (els.header) {
        const p = ease.power2out(inRange(elapsed, T.HEADER));
        els.header.style.setProperty('--home-reveal-translate', `${14 - 14 * p}px`);
        els.header.style.setProperty('--home-reveal-opacity', p.toFixed(3));
    }

    // ── Hero photo: opacity 0→1 + scale 1.12→1.00 (ease power3.out)
    if (els.photo) {
        const p = ease.power3inOut(inRange(elapsed, T.PHOTO));
        const scale = (1.12 - 0.12 * p).toFixed(4);
        const opacity = p.toFixed(3);
        els.photo.style.opacity = opacity;
        els.photo.style.transform = `scale(${scale})`;
    }

    // ── Eyebrow: fade + 18px up, line draws from width 0
    if (els.eyebrow) {
        const p = ease.power2out(inRange(elapsed, T.EYEBROW));
        els.eyebrow.style.opacity = p.toFixed(3);
        els.eyebrow.style.transform = `translateY(${(18 - 18 * p).toFixed(2)}px)`;
    }
    if (els.eyebrowLine) {
        const p = ease.power2inOut(inRange(elapsed, T.EYEBROW));
        els.eyebrowLine.style.transform = `scaleX(${p.toFixed(3)})`;
    }

    // ── H1 line 1 reveal (translateY 105% + skewX -8deg → 0)
    const h1Count = els.h1Lines.length;
    for (let i = 0; i < h1Count; i++) {
        const el = els.h1Lines[i];
        const range = i === 0 ? T.H1_LINE_1 : T.H1_LINE_2;
        const p = ease.power4out(inRange(elapsed, range));
        // The inner span is what animates — wrapper has overflow:hidden.
        const inner = el.querySelector('span') || el;
        inner.style.transform = `translateY(${(105 - 105 * p).toFixed(2)}%) skewX(${(-8 + 8 * p).toFixed(2)}deg)`;
        el.style.opacity = p > 0.02 ? '1' : '0';
    }

    // ── Lime light sweep: thin line travels L→R across hero.
    if (els.sweep) {
        const p = inRange(elapsed, T.SWEEP);
        if (p > 0 && p < 1) {
            const x = -10 + 110 * p; // -10% to 100% of the hero width
            // ease-in for the x position; fade out near the end.
            const easedX = ease.power2in(p);
            const opacity = p < 0.85 ? 1 : (1 - p) / 0.15;
            els.sweep.style.opacity = String(opacity);
            els.sweep.style.transform = `translate(${-10 + 110 * easedX}%, -50%)`;
        } else {
            els.sweep.style.opacity = '0';
        }
    }

    // ── Paragraph: fade + 18px up
    if (els.paragraph) {
        const p = ease.power2out(inRange(elapsed, T.PARAGRAPH));
        els.paragraph.style.opacity = p.toFixed(3);
        els.paragraph.style.transform = `translateY(${(18 - 18 * p).toFixed(2)}px)`;
    }

    // ── CTA buttons: stagger 0.12s
    els.ctas.forEach((el, i) => {
        const offset = i * 120;
        const p = ease.power2out(clamp01((elapsed - T.CTAS[0] - offset) / (T.CTAS[1] - T.CTAS[0])));
        el.style.opacity = p.toFixed(3);
        el.style.transform = `translateY(${(18 - 18 * p).toFixed(2)}px)`;
        // Primary gets one soft lime glow pulse at the end.
        if (i === 0 && p >= 1) {
            el.classList.add('is-arrived');
        } else {
            el.classList.remove('is-arrived');
        }
    });

    // ── Stats: stagger 0.1s + count-up over the last 1.2s
    els.stats.forEach((el, i) => {
        const offset = i * 100;
        const p = ease.power2out(clamp01((elapsed - T.STATS[0] - offset) / (T.STATS[1] - T.STATS[0])));
        el.style.opacity = p.toFixed(3);
        el.style.transform = `translateY(${(18 - 18 * p).toFixed(2)}px)`;
    });

    if (countUp) {
        runCountUpFrame(els.statValues, finalStats, elapsed, T.STATS[0], T.STATS[1]);
    }
}

function finalize(els) {
    const hero = document.querySelector('[data-home-hero]');
    hero?.classList.add('is-arrived');

    if (els.eyebrow) {
        els.eyebrow.style.opacity = '1';
        els.eyebrow.style.transform = 'none';
    }
    if (els.eyebrowLine) {
        els.eyebrowLine.style.transform = 'scaleX(1)';
    }
    if (els.photo) {
        els.photo.style.opacity = '1';
        els.photo.style.transform = 'none';
    }
    if (els.header) {
        els.header.style.removeProperty('--home-reveal-translate');
        els.header.style.removeProperty('--home-reveal-opacity');
    }
    if (els.sweep) {
        els.sweep.style.opacity = '0';
    }
    els.h1Lines.forEach((el) => {
        el.style.opacity = '1';
        const inner = el.querySelector('span') || el;
        inner.style.transform = 'none';
    });
    if (els.paragraph) {
        els.paragraph.style.opacity = '1';
        els.paragraph.style.transform = 'none';
    }
    els.ctas.forEach((el) => {
        el.style.opacity = '1';
        el.style.transform = 'none';
        if (el.classList.contains('store-btn--primary')) {
            el.classList.add('is-arrived');
        }
    });
    els.stats.forEach((el) => {
        el.style.opacity = '1';
        el.style.transform = 'none';
    });
}

// ─── Count-up ──────────────────────────────────────────────────

function parseStat(text) {
    // "120+", "48h", "14d" → [120, '+']
    const m = String(text).match(/^(\d+)(.*)$/);
    if (!m) return [0, text];
    return [parseInt(m[1], 10), m[2] || ''];
}

function runCountUpFrame(els, finalStats, elapsed, start, end) {
    const t = clamp01((elapsed - start) / (end - start));
    if (t === 0) {
        els.forEach((el) => {
            const [num, suffix] = parseStat(el.dataset.finalValue || finalStats[Array.from(els).indexOf(el)]);
            el.textContent = `0${suffix}`;
        });
        return;
    }
    const eased = 1 - Math.pow(1 - t, 3); // easeOutCubic
    els.forEach((el, i) => {
        const [num, suffix] = parseStat(finalStats[i] || el.textContent);
        const current = Math.round(num * eased);
        el.textContent = `${current}${suffix}`;
    });
}

function runCountUp(els) {
    els.statValues.forEach((el, i) => {
        const final = el.textContent;
        el.dataset.finalValue = final;
        const [num] = parseStat(final);
        el.textContent = `0${parseStat(final)[1] || ''}`;
        // reduced motion: snap to final after 0.4s
        setTimeout(() => { el.textContent = final; }, REDUCED_HOME_MS);
    });
}

// ─── Mouse parallax on hero photo ─────────────────────────────
//
// The photo only moves where it has room to move. On a phone the hero
// is stacked — the photograph keeps its own ratio and the copy sits
// below it — so there is nothing for the parallax to shift, and
// moving the photo would only open a gap between it and the black
// underneath.

const STACKED_HERO_QUERY = '(max-width: 899px)';

function initParallax() {
    if (prefersReducedMotion() || isTouch()) return;
    const wrap = document.querySelector('[data-home-photo-wrap]');
    if (!wrap) return;
    const photo = wrap.querySelector('[data-home-photo]');
    if (!photo) return;

    let targetX = 0, targetY = 0, currentX = 0, currentY = 0, raf = 0, active = false;
    const MAX_X = 16, MAX_Y = 10;
    const root = wrap.closest('[data-home-hero]');

    function onMove(ev) {
        if (window.matchMedia?.(STACKED_HERO_QUERY)?.matches) return;

        const rect = wrap.getBoundingClientRect();
        const cx = rect.left + rect.width / 2;
        const cy = rect.top + rect.height / 2;
        targetX = clamp(((ev.clientX - cx) / rect.width) * 2, -1, 1) * MAX_X;
        targetY = clamp(((ev.clientY - cy) / rect.height) * 2, -1, 1) * MAX_Y;
        if (!active) {
            active = true;
            raf = requestAnimationFrame(loop);
        }
    }
    function onLeave() {
        targetX = 0;
        targetY = 0;
    }
    function loop() {
        currentX += (targetX - currentX) * 0.12;
        currentY += (targetY - currentY) * 0.12;
        if (root?.classList.contains('is-arrived')) {
            photo.style.setProperty('--parallax-x', `${currentX.toFixed(2)}px`);
            photo.style.setProperty('--parallax-y', `${currentY.toFixed(2)}px`);
        }
        if (Math.abs(targetX - currentX) > 0.1 || Math.abs(targetY - currentY) > 0.1) {
            raf = requestAnimationFrame(loop);
        } else {
            active = false;
        }
    }
    wrap.addEventListener('mousemove', onMove);
    wrap.addEventListener('mouseleave', onLeave);
}

// ─── Scroll reveal (sections below the hero) ───────────────────

function initScrollReveal() {
    if (prefersReducedMotion()) {
        document.querySelectorAll('[data-home-reveal]').forEach((el) => {
            el.classList.add('is-revealed');
        });
        return;
    }
    const items = document.querySelectorAll('[data-home-reveal]');
    if (!('IntersectionObserver' in window)) {
        items.forEach((el) => el.classList.add('is-revealed'));
        return;
    }
    const obs = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                const el = entry.target;
                // Card stagger inside grids.
                const parent = el.closest('[data-home-stagger]');
                if (parent) {
                    const idx = Array.from(parent.children).indexOf(el);
                    el.style.transitionDelay = `${idx * 80}ms`;
                }
                el.classList.add('is-revealed');
                obs.unobserve(el);
            }
        });
    }, { threshold: 0.12 });
    items.forEach((el) => obs.observe(el));
}

// ─── Sticky header strength after 40px scroll ─────────────────

function initStickyHeader() {
    const header = document.querySelector('.store-header');
    if (!header) return;
    const update = () => {
        header.classList.toggle('is-scrolled', window.scrollY > 40);
    };
    update();
    window.addEventListener('scroll', update, { passive: true });
}

// ─── Newsletter ──────────────────────────────────────────────────
//
// The form used to be a theatre: the browser checked the address,
// waited 700ms and printed "YOU'RE IN ✓" without sending anything.
// The shopper was told they had subscribed and the store never
// learned their address.
//
// The form posts to the server now, and the success state appears
// only once the server has written the subscription down. A request
// that fails says so — the page never claims a subscription that did
// not happen.

function initNewsletter() {
    const form = document.querySelector('[data-home-newsletter]');
    if (!form) return;
    const input = form.querySelector('input[type="email"]');
    const btn = form.querySelector('button[type="submit"]');
    const success = form.querySelector('[data-home-newsletter-success]');
    const hint = document.querySelector('.newsletter__hint');

    const validEmail = (s) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(s || '').trim());

    const say = (text) => {
        if (hint) hint.textContent = text || '';
    };

    form.addEventListener('submit', async (ev) => {
        ev.preventDefault();

        if (!validEmail(input.value)) {
            input.setAttribute('aria-invalid', 'true');
            say('Please enter a valid email address.');
            input.focus();
            return;
        }

        input.removeAttribute('aria-invalid');
        btn.setAttribute('data-loading', 'true');
        btn.disabled = true;
        say('');

        const result = await apiRequest('POST', form.getAttribute('action'), {
            email: input.value.trim(),
        });

        btn.removeAttribute('data-loading');
        btn.disabled = false;

        if (!result.ok) {
            // The address was not saved. Say so — do not thank the
            // shopper for a subscription that did not happen.
            say(result.message || 'We could not save your subscription. Please try again.');
            return;
        }

        say(result.data && result.data.message ? result.data.message : '');

        if (success) success.hidden = false;

        input.value = '';
        input.disabled = true;
    });
}

// ─── Boot ──────────────────────────────────────────────────────

function boot() {
    initHero();
    initParallax();
    initScrollReveal();
    initStickyHeader();
    initNewsletter();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}

// If the intro is disabled, dispatch the home reveal after first paint
// so the hero entrance still plays.
function triggerRevealFallback() {
    requestAnimationFrame(() => {
        if (!document.querySelector('[data-home-hero]')) return;
        setTimeout(() => {
            const root = document.querySelector('[data-home-hero]');
            if (root && root.dataset.homeRevealed !== '1') {
                root.dataset.homeRevealed = '1';
                window.dispatchEvent(new Event('abo:home-reveal'));
            }
        }, 50);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', triggerRevealFallback);
} else {
    triggerRevealFallback();
}
