// ABO — "My Orders" front-end module.
//
// Scope:
//   - Orders come from a single function `getOrders()`, which fetches
//     the customer's own orders from the API. There is no mock
//     fallback: a page that shows orders the customer never placed is
//     worse than an empty page, so a failed request surfaces as an
//     empty list and the page renders its empty state.
//   - Sorting, filtering and search run in the browser on the array.
//   - Buttons that would need a backend only show a non-blocking
//     lime toast — they do not perform any other action.
//
// Page bootstrap (`init()`) runs when the module loads, and
// re-renders on `?state=loading|empty|error` for design review.

// ─── Single-source-of-truth data wrapper ────────────────────────────

/**
 * Returns the list of orders for the current user, newest first.
 *
 * Fetches the customer's own orders from `orders.data`; the response
 * shape is the one documented at the top of the `OrderController`.
 *
 * Always returns a promise so the call site does not need to know
 * whether the source is sync or async.
 */
export async function getOrders() {
    try {
        const response = await fetch('/orders/data', {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (response.ok) {
            const data = await response.json();

            if (data && Array.isArray(data.orders)) {
                return data.orders;
            }
        }
    } catch (err) {
        console.warn('[orders] Failed to fetch live orders.', err);
    }

    // No mock fallback — a failed request means an empty list, and the
    // page renders its empty state.
    return [];
}

// ─── Tiny helpers ───────────────────────────────────────────────────

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

function formatEGP(n) {
    return `${Number(n).toLocaleString('en-EG')} EGP`;
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
    ));
}

function lineTotal(order) {
    const items = order.items.reduce(
        (sum, it) => sum + (Number(it.price) * Number(it.qty)),
        0
    );
    return items + (Number(order.shipping) || 0);
}

// ─── State ──────────────────────────────────────────────────────────

const state = {
    orders: [],     // current dataset
    filter: 'all',  // all | in-progress | delivered | cancelled
    search: '',
};

// ─── Stepper helpers ─────────────────────────────────────────────────

const STEP_LABELS = [
    'Placed', 'Packed', 'Shipped', 'Out for delivery', 'Delivered',
];

function statusOf(order) {
    if (order.cancelled) return 'cancelled';
    if (order.step === 4) return 'delivered';
    return 'in-progress';
}

function statusBadgeLabel(order) {
    if (order.cancelled) return 'Cancelled';
    if (order.step === 4) return 'Delivered ✓';
    if (order.step === 1) return 'Processing';
    if (order.step === 2) return 'In transit';
    if (order.step === 3) return 'Out for delivery';
    return 'Order placed';
}

// ─── Rendering ──────────────────────────────────────────────────────

function renderStats() {
    const total = state.orders.length;
    const inProgress = state.orders.filter((o) => statusOf(o) === 'in-progress').length;
    const spent = state.orders
        .filter((o) => !o.cancelled)
        .reduce((sum, o) => sum + lineTotal(o), 0);

    const totalEl = $('[data-orders-stat-total]');
    const progEl  = $('[data-orders-stat-progress]');
    const spentEl = $('[data-orders-stat-spent]');

    if (totalEl) totalEl.textContent = String(total);
    if (progEl)  progEl.textContent  = String(inProgress);
    if (spentEl) spentEl.textContent = formatEGP(spent);
}

function renderToolbarCounts() {
    const counts = state.orders.reduce((acc, o) => {
        acc.all += 1;
        acc[statusOf(o)] += 1;
        return acc;
    }, { all: 0, 'in-progress': 0, delivered: 0, cancelled: 0 });

    for (const key of ['all', 'in-progress', 'delivered', 'cancelled']) {
        const el = $(`[data-orders-tab-count="${key}"]`);
        if (el) el.textContent = String(counts[key]);
    }

    // Active tab.
    $$('[data-orders-tab]').forEach((tab) => {
        const isActive = tab.dataset.ordersTab === state.filter;
        tab.classList.toggle('orders__tab--active', isActive);
        tab.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });
}

function applyFilters(list) {
    const q = state.search.trim().toLowerCase();
    return list.filter((o) => {
        // Filter.
        if (state.filter !== 'all' && statusOf(o) !== state.filter) return false;
        // Search by id OR any product name.
        if (q) {
            const inId = o.id.toLowerCase().includes(q);
            const inName = o.items.some((it) => it.name.toLowerCase().includes(q));
            if (!inId && !inName) return false;
        }
        return true;
    });
}

function buildItemRow(item) {
    const li = document.createElement('li');
    li.className = 'orders__item';

    const thumb = document.createElement('div');
    thumb.className = 'orders__item-thumb';
    thumb.setAttribute('aria-hidden', 'true');
    thumb.innerHTML = `
        <span class="orders__item-thumb-watermark">ABO</span>
    `;
    li.appendChild(thumb);

    const body = document.createElement('div');
    body.className = 'orders__item-body';
    body.innerHTML = `
        <p class="orders__item-name">${escapeHtml(item.name)}</p>
        <p class="orders__item-meta">${escapeHtml(item.variant)} · Qty ${item.qty}</p>
    `;
    li.appendChild(body);

    const price = document.createElement('div');
    price.className = 'orders__item-price';
    price.textContent = formatEGP(Number(item.price) * Number(item.qty));
    li.appendChild(price);

    return li;
}

function buildStepper(order) {
    const ol = document.createElement('ol');
    ol.className = 'orders__stepper';
    ol.setAttribute('role', 'list');
    ol.setAttribute('aria-label', 'Delivery progress');

    STEP_LABELS.forEach((label, i) => {
        const li = document.createElement('li');
        li.className = 'orders__step';

        let stateClass = '';
        if (i < order.step) stateClass = 'orders__step--done';
        else if (i === order.step) stateClass = 'orders__step--current';

        li.className = `orders__step ${stateClass}`.trim();

        const dot = document.createElement('span');
        dot.className = 'orders__step-dot';
        dot.setAttribute('aria-hidden', 'true');
        li.appendChild(dot);

        const text = document.createElement('span');
        text.className = 'orders__step-label';
        text.textContent = label;
        li.appendChild(text);

        ol.appendChild(li);
    });

    return ol;
}

function buildTimeline(order) {
    const panel = document.createElement('section');
    panel.className = 'orders__timeline';
    panel.setAttribute('aria-label', 'Order timeline');

    const heading = document.createElement('h4');
    heading.className = 'orders__timeline-title';
    heading.textContent = 'ORDER TIMELINE';
    panel.appendChild(heading);

    const list = document.createElement('ol');
    list.className = 'orders__timeline-list';
    list.setAttribute('role', 'list');

    // Newest on top.
    const events = [...order.timeline].reverse();
    for (const ev of events) {
        const li = document.createElement('li');
        li.className = `orders__timeline-item orders__timeline-item--${ev.status}`;
        li.innerHTML = `
            <span class="orders__timeline-dot" aria-hidden="true"></span>
            <span class="orders__timeline-text">
                <span class="orders__timeline-event">${escapeHtml(ev.title)}</span>
                <span class="orders__timeline-time">${escapeHtml(ev.time)}</span>
            </span>
        `;
        list.appendChild(li);
    }
    panel.appendChild(list);

    // 3-column meta grid.
    const meta = document.createElement('dl');
    meta.className = 'orders__meta';

    const shippingLabel = Number(order.shipping) === 0 ? 'Free' : formatEGP(order.shipping);

    const metaItems = [
        { label: 'Payment', value: order.payment },
        { label: 'Ship to', value: order.address },
        { label: 'Shipping', value: shippingLabel },
    ];
    for (const m of metaItems) {
        const wrap = document.createElement('div');
        wrap.className = 'orders__meta-cell';
        wrap.innerHTML = `
            <dt class="orders__meta-label">${escapeHtml(m.label.toUpperCase())}</dt>
            <dd class="orders__meta-value">${escapeHtml(m.value)}</dd>
        `;
        meta.appendChild(wrap);
    }
    panel.appendChild(meta);

    return panel;
}

function buildActionButton(label, kind, action, order, opts = {}) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = `orders__btn orders__btn--${kind}`;
    b.textContent = label;
    b.dataset.ordersAction = action;
    b.dataset.ordersActionOrder = order.id;

    if (opts.toggleFor) {
        b.dataset.ordersToggle = opts.toggleFor;
        b.setAttribute('aria-expanded', 'false');
        b.setAttribute('aria-controls', `timeline-${order.id}`);
    }
    if (opts.disabled) {
        b.disabled = true;
        b.setAttribute('aria-disabled', 'true');
        if (opts.tooltip) b.title = opts.tooltip;
    }
    return b;
}

function buildActions(order) {
    const wrap = document.createElement('div');
    wrap.className = 'orders__actions';

    const status = statusOf(order);

    if (status === 'in-progress') {
        wrap.appendChild(buildActionButton(
            'Track order', 'primary', 'track', order, { toggleFor: order.id }
        ));
        wrap.appendChild(buildActionButton('Invoice',    'ghost', 'invoice', order));
        wrap.appendChild(buildActionButton('Need help?', 'ghost', 'help',    order));
    } else if (status === 'delivered') {
        wrap.appendChild(buildActionButton(
            'Details', 'ghost', 'details', order, { toggleFor: order.id }
        ));
        wrap.appendChild(buildActionButton('Invoice', 'ghost', 'invoice', order));
        const ret = buildActionButton('Return item', 'ghost', 'return', order);
        if (!order.returnOpen) {
            ret.disabled = true;
            ret.setAttribute('aria-disabled', 'true');
            ret.title = 'Return window closed';
        }
        wrap.appendChild(ret);
        wrap.appendChild(buildActionButton('Reorder', 'primary', 'reorder', order));
    } else if (status === 'cancelled') {
        wrap.appendChild(buildActionButton('Reorder', 'primary', 'reorder', order));
    }

    return wrap;
}

function buildCard(order) {
    const article = document.createElement('article');
    article.className = 'orders__card';
    article.dataset.ordersId = order.id;
    article.setAttribute('aria-label', `Order ${order.id}`);

    // 1. Header row.
    const header = document.createElement('header');
    header.className = 'orders__card-head';

    const left = document.createElement('div');
    left.className = 'orders__card-head-left';
    left.innerHTML = `
        <span class="orders__id">#${escapeHtml(order.id)}</span>
        <span class="orders__placed">Placed ${escapeHtml(order.dateLabel)}</span>
    `;
    header.appendChild(left);

    const status = statusOf(order);
    const badge = document.createElement('span');
    badge.className = `orders__status orders__status--${status}`;
    badge.textContent = statusBadgeLabel(order);
    header.appendChild(badge);

    article.appendChild(header);

    // 2. Stepper (hidden for cancelled).
    if (status !== 'cancelled') {
        article.appendChild(buildStepper(order));
    }

    // 3. ETA line.
    const eta = document.createElement('p');
    eta.className = 'orders__eta';
    eta.textContent = order.etaText;
    article.appendChild(eta);

    // 4. Items list.
    const items = document.createElement('ul');
    items.className = 'orders__items';
    items.setAttribute('role', 'list');
    items.setAttribute('aria-label', `Items in order ${order.id}`);
    for (const it of order.items) items.appendChild(buildItemRow(it));
    article.appendChild(items);

    // 5. Footer row.
    const footer = document.createElement('footer');
    footer.className = 'orders__card-foot';

    const totalWrap = document.createElement('div');
    totalWrap.className = 'orders__total';
    totalWrap.innerHTML = `
        <span class="orders__total-label">TOTAL</span>
        <span class="orders__total-value">${escapeHtml(formatEGP(lineTotal(order)))}</span>
    `;
    footer.appendChild(totalWrap);

    footer.appendChild(buildActions(order));
    article.appendChild(footer);

    // 6. Expandable timeline.
    const timeline = buildTimeline(order);
    timeline.id = `timeline-${order.id}`;
    timeline.hidden = true;
    article.appendChild(timeline);

    return article;
}

function renderList() {
    const host = $('[data-orders-list]');
    if (!host) return;

    const filtered = applyFilters(state.orders);
    host.replaceChildren();

    if (filtered.length === 0) {
        host.hidden = true;
        $('[data-orders-empty]').hidden = false;
        return;
    }

    host.hidden = false;
    $('[data-orders-empty]').hidden = true;
    for (const o of filtered) host.appendChild(buildCard(o));
}

function renderAll() {
    renderStats();
    renderToolbarCounts();
    renderList();
}

// ─── Loading / Empty / Error states (design-only) ───────────────────

function setState(name) {
    const main = $('[data-orders-state]');
    if (!main) return;
    main.dataset.ordersState = name;
}

function buildSkeleton() {
    const wrap = document.createElement('div');
    wrap.className = 'orders__skeleton';
    wrap.setAttribute('aria-hidden', 'true');
    wrap.innerHTML = `
        <div class="orders__skeleton-head">
            <span class="orders__skeleton-bar orders__skeleton-shimmer"></span>
            <span class="orders__skeleton-bar orders__skeleton-bar--sm orders__skeleton-shimmer"></span>
        </div>
        <span class="orders__skeleton-bar orders__skeleton-bar--lg orders__skeleton-shimmer"></span>
        <span class="orders__skeleton-bar orders__skeleton-shimmer"></span>
        <span class="orders__skeleton-bar orders__skeleton-shimmer"></span>
        <span class="orders__skeleton-bar orders__skeleton-bar--md orders__skeleton-shimmer"></span>
    `;
    return wrap;
}

function renderLoading() {
    const host = $('[data-orders-list]');
    host.replaceChildren();
    host.hidden = false;
    $('[data-orders-empty]').hidden = true;
    for (let i = 0; i < 3; i++) host.appendChild(buildSkeleton());
    // Disable the skeleton shimmer when reduced motion is set.
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        $$('.orders__skeleton-shimmer').forEach((el) => {
            el.style.animation = 'none';
        });
    }
}

function renderError() {
    const host = $('[data-orders-list]');
    host.replaceChildren();
    host.hidden = true;
    const empty = $('[data-orders-empty]');
    empty.hidden = false;
    empty.innerHTML = `
        <h3 class="orders__empty-title">SOMETHING WENT WRONG</h3>
        <p class="orders__empty-line">We couldn’t load your orders. Please try again.</p>
        <button type="button" class="orders__btn orders__btn--ghost" data-orders-retry>
            TRY AGAIN
        </button>
    `;
}

function renderEmpty() {
    const host = $('[data-orders-list]');
    host.replaceChildren();
    host.hidden = true;
    const empty = $('[data-orders-empty]');
    empty.hidden = false;
    empty.innerHTML = `
        <h3 class="orders__empty-title">NO ORDERS FOUND</h3>
        <p class="orders__empty-line">Nothing matches this filter yet.</p>
        <a class="orders__btn orders__btn--primary" href="/shop">
            START SHOPPING →
        </a>
    `;
}

// ─── Toasts ─────────────────────────────────────────────────────────

const TOAST_MS = 2200;

function showToast(message) {
    const host = $('[data-orders-toasts]');
    if (!host) return;
    // Replace any existing toast (one at a time per spec §8).
    host.replaceChildren();
    const el = document.createElement('div');
    el.className = 'orders__toast';
    el.setAttribute('role', 'status');
    el.textContent = message;
    host.appendChild(el);
    requestAnimationFrame(() => el.classList.add('is-shown'));
    setTimeout(() => {
        el.classList.remove('is-shown');
        setTimeout(() => el.remove(), 200);
    }, TOAST_MS);
}

// ─── Event handlers ───────────────────────────────────────────────────

function setExpanded(orderId, open) {
    const card = document.querySelector(`[data-orders-id="${orderId}"]`);
    const timeline = card?.querySelector('.orders__timeline');
    const toggle = card?.querySelector('[data-orders-toggle]');
    if (!timeline || !toggle) return;
    timeline.hidden = !open;
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    // Update the label based on the toggle's original action.
    const wasTrack = toggle.dataset.ordersAction === 'track';
    if (open) {
        toggle.textContent = 'Hide details';
    } else {
        toggle.textContent = wasTrack ? 'Track order' : 'Details';
    }
}

function onCardClick(ev) {
    const actionBtn = ev.target.closest('[data-orders-action]');
    if (!actionBtn) return;
    if (actionBtn.disabled || actionBtn.getAttribute('aria-disabled') === 'true') return;
    const action = actionBtn.dataset.ordersAction;
    const orderId = actionBtn.dataset.ordersActionOrder;

    if (action === 'track' || action === 'details') {
        const card = actionBtn.closest('.orders__card');
        const timeline = card?.querySelector('.orders__timeline');
        const isOpen = timeline && !timeline.hidden;
        setExpanded(orderId, !isOpen);
        return;
    }

    const order = state.orders.find((o) => o.id === orderId);
    if (!order) return;

    if (action === 'invoice') return showToast('Invoice download started');
    if (action === 'return')  return showToast('Return request started');
    if (action === 'help')    return showToast('Support will contact you shortly');
    if (action === 'reorder')  return showToast('Items added to your cart ✓');
}

function onToolbarClick(ev) {
    const tab = ev.target.closest('[data-orders-tab]');
    if (!tab) return;
    state.filter = tab.dataset.ordersTab;
    renderToolbarCounts();
    renderList();
}

function onSearchInput(ev) {
    state.search = ev.target.value || '';
    renderList();
}

// ─── Init ───────────────────────────────────────────────────────────

async function init() {
    const section = $('.orders');
    if (!section) return;

    section.addEventListener('click', onCardClick);
    section.addEventListener('click', onToolbarClick);
    const search = $('[data-orders-search]');
    if (search) search.addEventListener('input', onSearchInput);

    // Allow design-time state overrides via ?state=…
    const params = new URLSearchParams(window.location.search);
    const forced = params.get('state');

    if (forced === 'error') {
        setState('error');
        renderStats();
        renderToolbarCounts();
        renderError();
        section.addEventListener('click', (ev) => {
            if (ev.target.closest('[data-orders-retry]')) {
                setState('loading');
                renderLoading();
                getOrders().then((orders) => {
                    state.orders = orders;
                    setState('ready');
                    renderAll();
                });
            }
        });
        return;
    }

    if (forced === 'empty') {
        setState('empty');
        state.orders = [];
        renderAll();
        renderEmpty();
        return;
    }

    // Default flow: brief loading state (≈600ms) then real data.
    setState('loading');
    renderStats();
    renderToolbarCounts();
    renderLoading();

    const [orders] = await Promise.all([
        getOrders(),
        new Promise((r) => setTimeout(r, 600)),
    ]);
    state.orders = orders;

    setState('ready');
    renderAll();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}

// Public surface (used by the layout or external triggers).
window.ABOOrders = {
    getOrders,
    state,
    showToast,
};