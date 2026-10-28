// ABO Admin Dashboard — front-end + live backend wiring for the bell.
//
// Scope:
//   - The sidebar, low-stock list, latest-orders table, KPI cards,
//     status bar and Sales vs Profit chart are rendered server-side by
//     the Blade views; this script only adds behaviour.
//   - The notification bell is the only section that talks to the
//     backend today: it polls /admin/notifications every 30 seconds
//     (configurable via window.ADMIN_NOTIFICATIONS_POLL_MS), marks
//     individual rows as read, and wipes the badge on "Mark all read".
//   - The range segmented control fetches admin.dashboard.range once
//     per click and re-draws the dashboard in place for today / 7 days
//     / 30 days.
//
// Initial data source for the bell:
//   window.INITIAL_ADMIN_NOTIFICATIONS   — the SSR-hydrated payload
//     that AdminNotificationService::listForAdmin() shaped during the
//     page render. The first paint already shows the right rows and
//     badge count instead of a brief empty state.
//   window.ADMIN_NOTIFICATIONS_ENDPOINTS — index / mark-all-read /
//     mark-read-base URLs prepared by route('admin.notifications.*').
//   window.ADMIN_NOTIFICATIONS_POLL_MS   — poll cadence in ms
//     (30_000 by default — the spec asks for "30–45 seconds").
//   window.ADMIN_NOTIFICATIONS_SOUND     — whether a new notification
//     rings a tone. The store's own "Browser sound" setting, read by
//     the server and published here.

/* eslint-disable no-console */

// ─── Tiny helpers ────────────────────────────────────────────

var $ = window.$ || ((sel, root = document) => root.querySelector(sel));
var $$ = window.$$ || ((sel, root = document) => Array.from(root.querySelectorAll(sel)));

function el(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
        if (v == null || v === false) continue;
        if (k === 'class') node.className = v;
        else if (k === 'text') node.textContent = v;
        else if (k === 'html') node.innerHTML = v;
        else node.setAttribute(k, v);
    }
    for (const c of [].concat(children)) {
        if (c == null) continue;
        node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    }
    return node;
}

function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

function endpoints() {
    return window.ADMIN_NOTIFICATIONS_ENDPOINTS || {};
}

// ─── Initial bell data ──────────────────────────────────────
//
// Clone the SSR payload so the in-memory state is safe to mutate as
// the user marks rows read. Fall back to [] when the global is absent
// (e.g. a page outside the admin layout) so the bell never throws.

function initialNotifications() {
    const seed = window.INITIAL_ADMIN_NOTIFICATIONS;
    return Array.isArray(seed) ? seed.map((n) => ({ ...n })) : [];
}

function initialUnreadCount(items) {
    return items.filter((n) => n.unread).length;
}

// ─── Notification bell ───────────────────────────────────────

function bellState() {
    return {
        open: false,
        filter: 'all',
        items: initialNotifications(),
    };
}

function unreadCount(items) {
    return items.filter((n) => n.unread).length;
}

function renderBellList(state, listEl) {
    listEl.replaceChildren();
    const filtered = state.items.filter((n) => state.filter === 'all' || n.type === state.filter);
    if (!filtered.length) {
        listEl.appendChild(el('li', { class: 'abo-admin-bell__empty' }, 'No notifications.'));
        return;
    }
    for (const n of filtered) {
        const li = el('li', {
            class: `abo-admin-bell__item ${n.unread ? 'is-unread' : ''}`,
            role: 'button',
            tabindex: '0',
            'data-abo-admin-bell-item': n.id,
            'data-abo-admin-bell-order-id': n.order_id || '',
            'aria-label': `${n.title}, ${n.time}`,
        });
        li.appendChild(el('span', { class: `abo-admin-bell__dot abo-admin-bell__dot--${n.tone}`, 'aria-hidden': 'true' }));
        const body = el('div', { class: 'abo-admin-bell__item-body' });
        body.appendChild(el('p', { class: 'abo-admin-bell__item-title', text: n.title }));
        body.appendChild(el('p', { class: 'abo-admin-bell__item-time', text: n.time }));
        li.appendChild(body);
        listEl.appendChild(li);
    }
}

function updateBadge(badgeEl, count) {
    badgeEl.textContent = String(count);
    badgeEl.hidden = count === 0;
}

function openBell(panel, trigger, badgeEl, state, listEl) {
    state.open = true;
    panel.hidden = false;
    trigger.setAttribute('aria-expanded', 'true');
    // Move focus into the panel for keyboard users.
    const first = panel.querySelector('[data-abo-admin-bell-mark]');
    if (first) first.focus({ preventScroll: true });
    renderBellList(state, listEl);
}

function closeBell(panel, trigger, state) {
    state.open = false;
    panel.hidden = true;
    trigger.setAttribute('aria-expanded', 'false');
}

function markItemRead(state, id) {
    const item = state.items.find((n) => n.id === id);
    if (!item) return null;
    item.unread = false;
    return item.href || null;
}

function markAllRead(state) {
    for (const item of state.items) item.unread = false;
}

// ─── Network ────────────────────────────────────────────────
//
// All three calls go through fetch() with cache disabled. keepalive
// would let a click survive a navigation, but the PATCH is short and
// the round-trip is well under the user-perceptible budget.

async function fetchNotifications() {
    const url = endpoints().index;
    if (!url) return null;

    const res = await fetch(url, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        cache: 'no-store',
    });
    if (!res.ok) return null;
    return res.json().catch(() => null);
}

async function patchMarkRead(notificationId) {
    const base = endpoints().markReadBase;
    if (!base) return null;

    const res = await fetch(`${base}${encodeURIComponent(notificationId)}/read`, {
        method: 'PATCH',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': getCsrfToken(),
        },
        credentials: 'same-origin',
        cache: 'no-store',
    });
    if (!res.ok) return null;
    return res.json().catch(() => null);
}

async function postMarkAllRead() {
    const url = endpoints().markAllRead;
    if (!url) return null;

    const res = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': getCsrfToken(),
        },
        credentials: 'same-origin',
        cache: 'no-store',
    });
    if (!res.ok) return null;
    return res.json().catch(() => null);
}

// Replaces the in-memory list with the payload returned by the
// server, then re-renders the badge and the visible rows.
function applyServerPayload(state, badgeEl, listEl, payload) {
    if (!payload || !Array.isArray(payload.notifications)) return;
    // The ids the browser already knows about. A row the server sends
    // that is not among them is news, and news is what makes the bell
    // ring.
    const known = new Set(state.items.map((n) => n.id));
    state.items = payload.notifications.map((n) => ({ ...n }));
    const count = typeof payload.unread_count === 'number'
        ? payload.unread_count
        : unreadCount(state.items);
    updateBadge(badgeEl, count);
    renderBellList(state, listEl);
    if (payload.notifications.some((n) => !known.has(n.id))) {
        playNotificationTone();
    }
}

// ─── Notification sound ──────────────────────────────────────
//
// The settings screen offers a "Browser sound" switch. The server
// publishes the store's answer as window.ADMIN_NOTIFICATIONS_SOUND —
// the browser keeps no copy of a setting it cannot see.
//
// The tone is synthesised with the Web Audio API, so the admin panel
// ships no audio file and no second request to make one.

function playNotificationTone() {
    if (window.ADMIN_NOTIFICATIONS_SOUND !== true) return;

    const Ctor = window.AudioContext || window.webkitAudioContext;
    if (!Ctor) return;

    try {
        const ctx = new Ctor();
        const now = ctx.currentTime;

        const gain = ctx.createGain();
        gain.gain.setValueAtTime(0.0001, now);
        gain.gain.exponentialRampToValueAtTime(0.08, now + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.4);
        gain.connect(ctx.destination);

        // Two notes: the shop's little "someone is waiting" chime.
        for (const [frequency, offset] of [[784, 0], [1046.5, 0.12]]) {
            const osc = ctx.createOscillator();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(frequency, now + offset);
            osc.connect(gain);
            osc.start(now + offset);
            osc.stop(now + offset + 0.3);
        }

        setTimeout(() => {
            if (typeof ctx.close === 'function') ctx.close().catch(() => {});
        }, 900);
    } catch (err) {
        // A browser that refuses to open an audio context is not a
        // reason to break the bell.
    }
}

// ─── Polling ────────────────────────────────────────────────
//
// 30s is the spec'd cadence. The timer is paused while the bell
// panel is open — the user is already looking at the list, the next
// poll will run when they close it. This keeps idle server load low
// and makes "Mark all read" clicks feel instant.

function startPolling(state, badgeEl, listEl) {
    const interval = Number(window.ADMIN_NOTIFICATIONS_POLL_MS) || 30000;
    setInterval(async () => {
        if (state.open) return;
        const payload = await fetchNotifications();
        if (!payload) return;
        applyServerPayload(state, badgeEl, listEl, payload);
    }, interval);
}

function initBell() {
    const wrap = $('[data-abo-admin-bell]');
    if (!wrap) return;
    const trigger = $('[data-abo-admin-bell-trigger]', wrap);
    const panel = $('[data-abo-admin-bell-panel]', wrap);
    const badge = $('[data-abo-admin-bell-badge]', wrap);
    const list = $('[data-abo-admin-bell-list]', wrap);
    const markBtn = $('[data-abo-admin-bell-mark]', wrap);
    const filters = $$('[data-abo-admin-bell-filter]', wrap);
    if (!trigger || !panel || !badge || !list) return;

    const state = bellState();
    updateBadge(badge, initialUnreadCount(state.items));

    trigger.addEventListener('click', () => {
        if (state.open) closeBell(panel, trigger, state);
        else openBell(panel, trigger, badge, state, list);
    });

    markBtn.addEventListener('click', async () => {
        // Optimistic UI: flip every row to read instantly so the badge
        // disappears without waiting on the network. The server call
        // reconciles if it fails — the badge would re-emerge on the
        // next poll, but no row is left half-marked.
        markAllRead(state);
        updateBadge(badge, 0);
        renderBellList(state, list);
        markBtn.setAttribute('disabled', 'true');

        const result = await postMarkAllRead();
        if (!result || result.success !== true) {
            // The server didn't accept it — fall back to a fresh poll
            // so the badge reflects whatever is still unread.
            const payload = await fetchNotifications();
            if (payload) applyServerPayload(state, badge, list, payload);
        }
        setTimeout(() => markBtn.removeAttribute('disabled'), 600);
    });

    for (const f of filters) {
        f.addEventListener('click', () => {
            state.filter = f.dataset.aboAdminBellFilter || 'all';
            for (const other of filters) {
                other.classList.toggle('is-active', other === f);
                other.setAttribute('aria-selected', other === f ? 'true' : 'false');
            }
            renderBellList(state, list);
        });
    }

    list.addEventListener('click', async (ev) => {
        const item = ev.target.closest('[data-abo-admin-bell-item]');
        if (!item) return;
        const id = item.dataset.aboAdminBellItem;
        const href = markItemRead(state, id);
        // Animate-out by adding the marking class for a tick.
        item.classList.add('is-marking-read');
        item.classList.remove('is-unread');
        updateBadge(badge, unreadCount(state.items));
        setTimeout(() => renderBellList(state, list), 240);

        // Hit the server in the background so the read flag is
        // persisted before the next poll runs. Failure is non-fatal:
        // the row is already read locally and the next poll would
        // re-emit the badge if the server rejected the change.
        if (id) patchMarkRead(id);

        if (href) {
            // Close panel then navigate. The href may be a hash
            // (preview mode) or a full admin URL — assigning to .hash
            // keeps hash-only links off a reload while relative paths
            // still get a navigation.
            closeBell(panel, trigger, state);
            if (href.startsWith('#')) {
                window.location.hash = href.replace(/^#/, '');
            } else if (/^https?:/i.test(href)) {
                window.location.href = href;
            } else {
                window.location.assign(href);
            }
        }
    });

    list.addEventListener('keydown', (ev) => {
        if (ev.key !== 'Enter' && ev.key !== ' ') return;
        const item = ev.target.closest('[data-abo-admin-bell-item]');
        if (!item) return;
        ev.preventDefault();
        item.click();
    });

    // Close on outside click.
    document.addEventListener('click', (ev) => {
        if (!state.open) return;
        if (wrap.contains(ev.target)) return;
        closeBell(panel, trigger, state);
    });

    // Close on Escape; return focus to the bell.
    document.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape' && state.open) {
            closeBell(panel, trigger, state);
            trigger.focus();
        }
    });

    // Kick off the polling loop AFTER the DOM is settled. The initial
    // payload already came from the server so the first 30s tick is
    // just an "is anything new?" probe.
    startPolling(state, badge, list);
}

// ─── Date range segmented control ────────────────────────────

// Geometry shared with the Blade fallback so a re-rendered chart is
// indistinguishable from the server-rendered first paint. The canvas
// is a fixed 700x220 viewBox with a 30/20 gutter; each point then gets
// an equal share of what is left.
const CHART = {
    width: 700,
    height: 220,
    gutterStart: 30,
    gutterEnd: 20,
    baseline: 170,
    plotHeight: 150,
    labelY: 200,
};

function renderSpark(key, points) {
    const line = $(`[data-abo-admin-kpi="${key}"] [data-abo-admin-spark] polyline`);
    if (!line) return;
    const max = Math.max(0, points.length - 1);
    const step = max > 0 ? 100 / max : 0;
    line.setAttribute('points', points.map((v, i) => `${(i * step).toFixed(2)},${(28 - v * 24 - 2).toFixed(2)}`).join(' '));
}

/**
 * Redraw the Sales vs Profit bars from an API series.
 *
 * The series arrives already scaled into K EGP, one entry per point,
 * so the only maths here is layout: a shared max across both measures
 * keeps the two bars comparable, and the slot width adapts to the point
 * count so 7 days and 24 hours both fill the canvas.
 */
function renderSalesProfitChart(points) {
    const group = $('[data-abo-admin-chart-series]');
    if (!group) return;

    group.replaceChildren();

    if (!Array.isArray(points) || !points.length) return;

    // Floor of 1 keeps an all-zero series from dividing by zero.
    const max = Math.max(
        1,
        ...points.map((p) => Math.max(Number(p.sales) || 0, Number(p.profit) || 0)),
    );

    const count = points.length;
    const slot = (CHART.width - CHART.gutterStart - CHART.gutterEnd) / count;
    const salesWidth = Math.max(Math.min(slot - 6, 34), 1);
    const profitWidth = Math.max(Math.min(slot * 0.22, 8), 1);
    const centre = CHART.gutterStart + slot / 2 - 3;

    points.forEach((point, index) => {
        const sales = Number(point.sales) || 0;
        const profit = Number(point.profit) || 0;
        const salesH = (sales / max) * CHART.plotHeight;
        const profitH = (profit / max) * CHART.plotHeight;
        const x = CHART.gutterStart + index * slot;

        group.appendChild(el('rect', {
            class: `abo-admin-chart__bar ${point.current ? 'abo-admin-chart__bar--current' : 'abo-admin-chart__bar--sales'}`,
            'data-abo-admin-chart-bar': 'sales',
            x: x.toFixed(2),
            y: (CHART.baseline - salesH).toFixed(2),
            width: salesWidth.toFixed(2),
            height: salesH.toFixed(2),
            rx: '2',
        }));

        group.appendChild(el('rect', {
            class: 'abo-admin-chart__bar abo-admin-chart__bar--profit',
            'data-abo-admin-chart-bar': 'profit',
            x: (x + slot - 6).toFixed(2),
            y: (CHART.baseline - profitH).toFixed(2),
            width: profitWidth.toFixed(2),
            height: profitH.toFixed(2),
            rx: '1',
        }));

        group.appendChild(el('text', {
            class: 'abo-admin-chart__label',
            'data-abo-admin-chart-label': '',
            x: centre.toFixed(2),
            y: CHART.labelY,
            'text-anchor': 'middle',
            text: point.day,
        }));

        group.appendChild(el('text', {
            class: 'abo-admin-chart__value',
            'data-abo-admin-chart-value': '',
            x: centre.toFixed(2),
            y: (CHART.baseline - salesH - 6).toFixed(2),
            'text-anchor': 'middle',
            text: sales,
        }));
    });
}

/**
 * Rewrite the four KPI cards in place.
 *
 * Each card is described by the payload, so switching between
 * "SALES TODAY" and "SALES" — or between a delta line and an
 * "Avg …" sub-line — is the same code path.
 */
function renderKpis(kpis) {
    if (!kpis) return;

    for (const [key, card] of Object.entries(kpis)) {
        const root = $(`[data-abo-admin-kpi="${key}"]`);
        if (!root) continue;

        const label = $('[data-abo-admin-kpi-label]', root);
        if (label) label.textContent = card.label;

        const value = $('[data-abo-admin-kpi-value]', root);
        if (value) value.textContent = card.value;

        const delta = $('[data-abo-admin-kpi-delta]', root);
        const deltaValue = $('[data-abo-admin-kpi-delta-value]', root);
        if (delta && deltaValue) deltaValue.textContent = card.delta || card.sub || '';

        // Only the delta cards carry the ▲ arrow; the Avg / waste cards
        // are a plain sub-line. Toggling the node keeps the two
        // presentations exclusive without re-creating the card.
        const arrow = $('.abo-admin-kpi__delta-arrow', root);
        if (arrow) arrow.hidden = !card.delta;
    }
}

/**
 * Redraw the Orders by Status bar and its legend.
 *
 * The payload carries the percentage per bucket, so the segment widths
 * and the legend counts are written from the same source and cannot
 * drift apart.
 */
function renderOrdersByStatus(segments, total) {
    const bar = $('[data-abo-admin-status-bar]');
    const legend = $('[data-abo-admin-status-legend]');
    const totalEl = $('[data-abo-admin-status-total]');

    if (totalEl && typeof total === 'number') totalEl.textContent = `${total} orders`;

    if (!bar || !legend || !Array.isArray(segments)) return;

    const counts = $$('[data-abo-admin-status-count]', legend);

    bar.replaceChildren();
    counts.forEach((node) => node.remove());

    segments.forEach((segment) => {
        bar.appendChild(el('span', {
            class: `abo-admin-status__seg abo-admin-status__seg--${segment.hue}`,
            style: `--seg: ${segment.percent}%`,
            title: `${segment.label}: ${segment.count}`,
        }));

        legend.appendChild(el('li', {}, [
            el('span', { class: `abo-admin-status__dot abo-admin-status__dot--${segment.hue}`, 'aria-hidden': 'true' }),
            el('span', { class: 'abo-admin-status__name', text: segment.label }),
            el('span', { class: 'abo-admin-status__count', 'data-abo-admin-status-count': '', text: String(segment.count) }),
        ]));
    });
}

function initRange() {
    const wrap = $('[data-abo-admin-range]');
    if (!wrap) return;
    const buttons = $$('button[data-range]', wrap);
    const urlTemplate = wrap.dataset.aboAdminRangeUrl || '/admin/dashboard/range/__range__';

    // The EXPORT REPORT link downloads the dashboard for the range that
    // is on screen, so its URL follows the segmented control. The
    // template comes from the view (the route lives with the view that
    // serves it), and the placeholder is the same one the range
    // endpoint uses.
    const exportLink = $('[data-abo-admin-export]');
    const exportTemplate = exportLink?.dataset.aboAdminExportUrl || '';

    // Ignore responses for a range the admin has already moved on
    // from, so a slow reply cannot repaint the page with stale numbers.
    let requested = null;

    function markActive(range) {
        for (const b of buttons) {
            const isActive = b.dataset.range === range;
            b.classList.toggle('is-active', isActive);
            b.setAttribute('aria-selected', isActive ? 'true' : 'false');
        }
        wrap.dataset.aboAdminRangeActive = range;

        if (exportLink && exportTemplate) {
            exportLink.setAttribute(
                'href',
                exportTemplate.replace('__range__', encodeURIComponent(range))
            );
        }
    }

    function apply(data) {
        renderKpis(data.kpis);
        renderSalesProfitChart(data.salesProfit);
        renderOrdersByStatus(data.ordersByStatus, data.ordersTotal);
        for (const [key, points] of Object.entries(data.sparklines || {})) renderSpark(key, points);
    }

    function activate(range) {
        markActive(range);

        const url = urlTemplate.replace('__range__', encodeURIComponent(range));

        fetch(url, { headers: { Accept: 'application/json' } })
            .then((res) => {
                if (!res.ok) throw new Error(`Dashboard range request failed (${res.status}).`);
                return res.json();
            })
            .then((data) => {
                if (requested === range) apply(data);
            })
            .catch((error) => {
                // Leave the previously rendered numbers on screen
                // rather than blanking the dashboard on a flaky fetch.
                console.error(error);
            });
    }

    for (const b of buttons) {
        b.addEventListener('click', () => {
            requested = b.dataset.range || '7d';
            activate(requested);
        });
    }
}

// ─── Today's date label ──────────────────────────────────────

function setTodayDate() {
    const target = $('#abo-admin-today-date');
    if (!target) return;
    const now = new Date();
    const fmt = new Intl.DateTimeFormat('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    target.textContent = fmt.format(now);
}

// ─── Web Push Registration (Service Worker) ──────────────────

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

async function registerWebPush() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        return;
    }

    const vapidKey = window.VAPID_PUBLIC_KEY;
    const endpoints = endpointsConfig();
    const subscribeUrl = endpoints.pushSubscribe;

    if (!vapidKey || !subscribeUrl) {
        return;
    }

    try {
        const registration = await navigator.serviceWorker.register('/sw.js');
        await navigator.serviceWorker.ready;

        // Check current permission
        let permission = Notification.permission;
        if (permission === 'default') {
            // Prompt user on first interaction or request
            permission = await Notification.requestPermission();
        }

        if (permission !== 'granted') {
            return;
        }

        let subscription = await registration.pushManager.getSubscription();
        if (!subscription) {
            subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(vapidKey),
            });
        }

        // Send subscription to server
        const token = csrfToken();
        await fetch(subscribeUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(subscription),
        });
    } catch (err) {
        console.warn('WebPush registration failed:', err);
    }
}

// ─── Boot ────────────────────────────────────────────────────

function init() {
    initBell();
    initRange();
    setTodayDate();
    registerWebPush();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
