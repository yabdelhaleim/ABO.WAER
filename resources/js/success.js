// ABO Order success — front-end only.
//
// Renders the order payload that checkout.js wrote to
// sessionStorage.abo_last_order. If the storage key is missing
// (direct visit, refresh, or the storage was cleared) the page
// redirects to /shop. We never show a success state without a
// real order backing it.
//
// The success page is read-only on the server. Every value on
// screen comes from the payload. The URL also carries the order
// number as a fallback for the rare case where sessionStorage was
// wiped between submit and arrival.

const ORDER_STORAGE_KEY = 'abo_last_order';
const FALLBACK_SHOP_URL = '/shop';

const $ = (sel, root = document) => root.querySelector(sel);

// ─── Data ──────────────────────────────────────────────────────────

function readOrder() {
    try {
        const raw = sessionStorage.getItem(ORDER_STORAGE_KEY);
        return raw ? JSON.parse(raw) : null;
    } catch (e) {
        return null;
    }
}

function readPaymentMethods() {
    const tag = $('[data-checkout-success-payment-methods]');
    if (!tag) return {};
    try {
        const parsed = JSON.parse(tag.textContent || '{}');
        return parsed && typeof parsed === 'object' ? parsed : {};
    } catch (e) {
        return {};
    }
}

// ─── Formatting ───────────────────────────────────────────────────

function formatEGP(n) {
    return `${Number(n).toLocaleString('en-EG')} EGP`;
}

function formatDate(ts) {
    if (!Number.isFinite(Number(ts))) return '—';
    const d = new Date(Number(ts));
    if (Number.isNaN(d.getTime())) return '—';
    // Example: "Mon, 14 Oct 2026 · 13:42". Same locale the rest of
    // the storefront uses for "en-EG" numerics.
    const date = d.toLocaleDateString('en-GB', {
        weekday: 'short',
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
    const time = d.toLocaleTimeString('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
    return `${date} · ${time}`;
}

function paymentLabel(key) {
    if (!key) return '—';
    const methods = readPaymentMethods();
    return (methods[key] && methods[key].label) || '—';
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
}

function withCacheBust(url) {
    if (!url) return url;
    return url + (url.includes('?') ? '&' : '?') + 'v=' + Date.now();
}

function thumbUrl(image) {
    if (!image) return '';
    if (image.startsWith('http') || image.startsWith('/') || image.startsWith('data:')) {
        return image;
    }
    return '/' + image.replace(/^\/+/, '');
}

// ─── Rendering ─────────────────────────────────────────────────────

function renderItems(items) {
    const list = $('[data-checkout-success-items]');
    if (!list) return;

    list.replaceChildren(...items.map((line) => {
        const li = document.createElement('li');
        li.className = 'checkout-success__item';
        li.setAttribute('aria-label', `${line.name}, quantity ${line.qty}`);

        // Thumbnail.
        const thumb = document.createElement('div');
        thumb.className = 'checkout-success__item-thumb';
        if (line.image) {
            const img = document.createElement('img');
            img.className = 'checkout-success__item-thumb-img';
            img.src = withCacheBust(thumbUrl(line.image));
            img.alt = '';
            img.loading = 'lazy';
            img.width = 84;
            img.height = 105;
            img.addEventListener('error', () => {
                thumb.replaceChildren(makeInitialPanel(line.name));
            }, { once: true });
            thumb.appendChild(img);
        } else {
            thumb.appendChild(makeInitialPanel(line.name));
        }
        li.appendChild(thumb);

        // Body: name + chips + qty.
        const body = document.createElement('div');
        body.className = 'checkout-success__item-body';

        const name = document.createElement('p');
        name.className = 'checkout-success__item-name';
        name.textContent = line.name || '—';
        body.appendChild(name);

        if (line.color || line.size) {
            const chips = document.createElement('p');
            chips.className = 'checkout-success__item-chips';
            const parts = [];
            if (line.color) parts.push(`Color · ${line.color}`);
            if (line.size) parts.push(`Size · ${line.size}`);
            chips.textContent = parts.join(' · ');
            body.appendChild(chips);
        }

        const qty = document.createElement('p');
        qty.className = 'checkout-success__item-qty';
        qty.textContent = `Qty ${line.qty}`;
        body.appendChild(qty);

        li.appendChild(body);

        // Line total.
        const total = document.createElement('div');
        total.className = 'checkout-success__item-total';
        total.textContent = formatEGP(line.line_total);
        li.appendChild(total);

        return li;
    }));
}

function makeInitialPanel(name) {
    const panel = document.createElement('span');
    panel.className = 'checkout-success__item-thumb-initial';
    panel.textContent = (name || '?').trim().charAt(0).toUpperCase();
    return panel;
}

function renderOrder(order) {
    // Order number.
    const orderEl = $('[data-checkout-success-order]');
    if (orderEl) orderEl.textContent = order.number || '—';

    // Date.
    const dateEl = $('[data-checkout-success-date]');
    if (dateEl) dateEl.textContent = formatDate(order.placedAt);

    // Payment method.
    const payEl = $('[data-checkout-success-payment]');
    if (payEl) payEl.textContent = paymentLabel(order.paymentMethod);

    // Order total. The stored order carries the final figure
    // (subtotal + shipping − discount); older payloads only have the
    // subtotal.
    const totalEl = $('[data-checkout-success-total]');
    if (totalEl) {
        const figure = Number.isFinite(Number(order.total)) ? Number(order.total) : Number(order.subtotal);
        totalEl.textContent = formatEGP(figure);
    }

    // Items.
    if (Array.isArray(order.items) && order.items.length > 0) {
        renderItems(order.items);
    } else {
        // No items — hide the items section rather than render an
        // empty list. The user still has the order number + total
        // to confirm the order went through.
        const section = $('[data-checkout-success-items-section]');
        if (section) section.hidden = true;
    }
}

// ─── Page init ─────────────────────────────────────────────────────

function init() {
    // Guard: only run on the success page itself. Without this, the
    // sessionStorage fallback below would redirect every page
    // (including /account, /orders, etc.) to /shop when no order is
    // found — the same JS bundle is loaded on every Blade page.
    if (!document.querySelector('[data-checkout-success]')) return;

    // The page is rendered from the stored order, so every value on
    // screen is already correct. The payload in sessionStorage is the
    // copy the browser kept from the submission; when it is there we
    // refresh the page from it (same figures, no second request).
    const order = readOrder();

    if (order) {
        renderOrder(order);
    } else if (!window.__checkoutSuccessOrderNumber) {
        // No payload and no order number in the page: this is not a
        // real success page. Redirect to /shop — we must not show a
        // fabricated success state.
        window.location.replace(FALLBACK_SHOP_URL);
        return;
    }

    const mark = $('[data-checkout-success-mark]');
    if (mark) mark.hidden = false;
}

// Public surface (handy for tests / external triggers).
window.ABOSuccess = { readOrder, formatDate, formatEGP, paymentLabel };

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
