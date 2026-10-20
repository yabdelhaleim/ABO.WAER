// ABO Cart — front-end only.
//
// State lives in localStorage under the key `abo_cart`. Schema:
//   {
//     items: [
//       {
//         line_id: string,         // generated client-side, used as the line key
//         slug:    string,         // must exist in the products payload
//         color:   string|null,    // must be one of the product's real colors, if set
//         size:    string|null,    // null until real sizes exist in the data
//         qty:     int             // clamped into [1, MAX_QTY_PER_LINE] on every write
//       },
//       // …
//     ],
//   }
//
// Identity rule: (slug, color, size) is the cart line. The same
// product in a different color is a separate line.
//
// Prices / names / images are NEVER taken from localStorage. They
// are looked up from the products payload (a <script type="application/json">
// tag in the page) on every render. Lines whose slug isn't in the
// payload are dropped silently.
//
// The 10-cap is enforced in ONE place (`MAX_QTY_PER_LINE`). It is
// a placeholder until real per-product stock exists in the data
// file. Defined in JS only for now; if a server-side cap is later
// added it must be the same value.

const STORAGE_KEY = 'abo_cart';
const MAX_QTY_PER_LINE = 10;
const UNDO_WINDOW_MS = 5000;
const TOAST_TIMEOUT_MS = 5000;

// ─── Data ──────────────────────────────────────────────────────────

function readProducts() {
    const tag = document.querySelector('[data-cart-products]');
    if (!tag) return [];
    try {
        const parsed = JSON.parse(tag.textContent || '[]');
        return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
        return [];
    }
}

function readCart() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return { items: [] };
        const parsed = JSON.parse(raw);
        const items = Array.isArray(parsed?.items) ? parsed.items : [];
        return { items };
    } catch (e) {
        return { items: [] };
    }
}

function writeCart(cart) {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(cart));
    } catch (e) {
        // localStorage may be disabled (private mode). Fail silently;
        // the UI still works for the current session.
    }
}

// ─── Hydration ─────────────────────────────────────────────────────

/**
 * Returns the current cart, re-validated against the products
 * payload. Lines whose slug is missing or whose color is not a
 * real color of the product are dropped. Qty is clamped.
 */
function hydratedCart() {
    const products = readProducts();
    const productsBySlug = new Map(products.map((p) => [p.slug, p]));
    const raw = readCart();
    const items = [];
    let changed = false;

    for (const line of raw.items) {
        if (!line || typeof line !== 'object') { changed = true; continue; }
        const product = productsBySlug.get(line.slug);
        if (!product) { changed = true; continue; }

        const color = (typeof line.color === 'string' && line.color !== '') ? line.color : null;
        const productColors = Array.isArray(product.colors) ? product.colors : [];
        if (color !== null && !productColors.includes(color)) { changed = true; continue; }

        const size = (typeof line.size === 'string' && line.size !== '') ? line.size : null;

        const rawQty = Number(line.qty);
        const qty = Math.max(1, Math.min(MAX_QTY_PER_LINE, Number.isFinite(rawQty) ? Math.floor(rawQty) : 1));
        if (qty !== line.qty) changed = true;

        items.push({ line_id: String(line.line_id), slug: product.slug, color, size, qty });
    }

    if (changed) writeCart({ items });

    // Decorate with display fields (name, price, image, line_total)
    // without ever trusting the stored values.
    return {
        items: items.map((line) => {
            const product = productsBySlug.get(line.slug);
            return {
                ...line,
                name:       product.name,
                price:      product.price,
                image:      product.image,
                category:   product.category,
                line_total: product.price * line.qty,
            };
        }),
    };
}

// ─── Public mutation API (used by tests if needed) ────────────────

function addLine({ slug, color = null, size = null, qty = 1 }) {
    const products = readProducts();
    const product = products.find((p) => p.slug === slug);
    if (!product) return null;
    if (color !== null && !product.colors.includes(color)) return null;
    const cart = readCart();
    const safeQty = Math.max(1, Math.min(MAX_QTY_PER_LINE, qty));
    const existing = cart.items.find((l) => l.slug === slug && l.color === color && l.size === size);
    if (existing) {
        existing.qty = Math.max(1, Math.min(MAX_QTY_PER_LINE, existing.qty + safeQty));
    } else {
        cart.items.push({
            line_id: cryptoRandomId(),
            slug, color, size,
            qty: safeQty,
        });
    }
    writeCart(cart);
    return hydratedCart();
}

function setQty(lineId, qty) {
    const cart = readCart();
    const line = cart.items.find((l) => l.line_id === lineId);
    if (!line) return null;
    line.qty = Math.max(1, Math.min(MAX_QTY_PER_LINE, Math.floor(Number(qty) || 1)));
    writeCart(cart);
    return hydratedCart();
}

function removeLine(lineId) {
    const cart = readCart();
    const before = cart.items.length;
    cart.items = cart.items.filter((l) => l.line_id !== lineId);
    if (cart.items.length === before) return false;
    writeCart(cart);
    return true;
}

function cryptoRandomId() {
    // Reasonable id without crypto.randomUUID dependency.
    const r = () => Math.random().toString(36).slice(2, 10);
    return `${Date.now().toString(36)}-${r()}-${r()}`;
}

// ─── Rendering ─────────────────────────────────────────────────────

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

function formatEGP(n) {
    return `${Number(n).toLocaleString('en-EG')} EGP`;
}

function itemCount(cart) {
    return cart.items.reduce((sum, l) => sum + l.qty, 0);
}

function itemCountLabel(n) {
    return `${n} ${n === 1 ? 'item' : 'items'}`;
}

function renderRow(line) {
    const li = document.createElement('li');
    li.className = 'cart__row';
    li.dataset.lineId = line.line_id;
    li.dataset.productSlug = line.slug;
    li.setAttribute('aria-label', `Cart line for ${line.name}`);

    // Thumbnail (real <img> with fallback initial).
    const thumb = document.createElement('div');
    thumb.className = 'cart__thumb';
    if (line.image) {
        const img = document.createElement('img');
        img.className = 'cart__thumb-img';
        img.src = withCacheBust(assetUrl(line.image));
        img.alt = '';
        img.loading = 'lazy';
        img.width = 168;
        img.height = 210;
        img.addEventListener('error', () => {
            // Swap to the initial panel.
            thumb.replaceChildren(makeInitialPanel(line.name));
        }, { once: true });
        thumb.appendChild(img);
    } else {
        thumb.appendChild(makeInitialPanel(line.name));
    }
    li.appendChild(thumb);

    // Body: name + chips + stepper + remove + line total.
    const body = document.createElement('div');
    body.className = 'cart__body';

    const nameLink = document.createElement('a');
    nameLink.className = 'cart__name';
    nameLink.href = productShowUrl(line.slug);
    nameLink.textContent = line.name;
    body.appendChild(nameLink);

    if (line.color || line.size) {
        const chips = document.createElement('div');
        chips.className = 'cart__chips';
        if (line.color) {
            const chip = document.createElement('span');
            chip.className = 'cart__chip';
            const dot = document.createElement('span');
            dot.className = 'cart__chip-dot';
            dot.style.setProperty('--swatch', line.color);
            chip.appendChild(dot);
            chip.appendChild(document.createTextNode(`Color · ${line.color}`));
            chips.appendChild(chip);
        }
        if (line.size) {
            const chip = document.createElement('span');
            chip.className = 'cart__chip';
            chip.appendChild(document.createTextNode(`Size · ${line.size}`));
            chips.appendChild(chip);
        }
        body.appendChild(chips);
    }

    const unitPrice = document.createElement('p');
    unitPrice.className = 'cart__unit';
    unitPrice.textContent = `${formatEGP(line.price)} each`;
    body.appendChild(unitPrice);

    // Stepper.
    const stepper = document.createElement('div');
    stepper.className = 'cart__stepper';
    stepper.setAttribute('role', 'group');
    stepper.setAttribute('aria-label', `Quantity for ${line.name}`);

    const dec = document.createElement('button');
    dec.type = 'button';
    dec.className = 'cart__stepper-btn';
    dec.dataset.stepperAction = 'dec';
    dec.setAttribute('aria-label', 'Decrease quantity');
    dec.disabled = line.qty <= 1;
    dec.textContent = '−';

    const num = document.createElement('span');
    num.className = 'cart__stepper-num';
    num.dataset.stepperValue = '';
    num.setAttribute('aria-live', 'polite');
    num.textContent = String(line.qty);

    const inc = document.createElement('button');
    inc.type = 'button';
    inc.className = 'cart__stepper-btn';
    inc.dataset.stepperAction = 'inc';
    inc.setAttribute('aria-label', 'Increase quantity');
    inc.disabled = line.qty >= MAX_QTY_PER_LINE;
    inc.textContent = '+';

    stepper.appendChild(dec);
    stepper.appendChild(num);
    stepper.appendChild(inc);
    body.appendChild(stepper);

    // Remove.
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'cart__remove';
    remove.dataset.removeLine = '';
    remove.setAttribute('aria-label', `Remove ${line.name} from cart`);
    remove.textContent = 'REMOVE';
    body.appendChild(remove);

    li.appendChild(body);

    // Line total.
    const total = document.createElement('div');
    total.className = 'cart__line-total';
    total.dataset.lineTotal = '';
    total.setAttribute('aria-live', 'polite');
    total.innerHTML = `<span class="cart__line-total-label">Item total</span><span class="cart__line-total-value">${formatEGP(line.line_total)}</span>`;
    li.appendChild(total);

    return li;
}

function makeInitialPanel(name) {
    const panel = document.createElement('div');
    panel.className = 'cart__thumb-initial';
    panel.textContent = (name || '?').trim().charAt(0).toUpperCase();
    return panel;
}

function withCacheBust(url) {
    if (!url) return url;
    return url + (url.includes('?') ? '&' : '?') + 'v=' + Date.now();
}

function assetUrl(path) {
    if (!path) return path;
    if (path.startsWith('http') || path.startsWith('/') || path.startsWith('data:')) return path;
    return '/' + path.replace(/^\/+/, '');
}

function productShowUrl(slug) {
    return '/shop/' + encodeURIComponent(slug);
}

// ─── Page render ───────────────────────────────────────────────────

function render() {
    const cart = hydratedCart();
    const items = cart.items;
    const isEmpty = items.length === 0;
    const count = itemCount(cart);

    // Items list.
    const list = $('[data-cart-items]');
    if (list) {
        list.replaceChildren(...items.map(renderRow));
    }

    // Empty / populated shell.
    const empty = $('[data-cart-empty]');
    if (empty) empty.hidden = !isEmpty;
    const grid = $('[data-cart-grid]');
    if (grid) grid.classList.toggle('cart__grid--empty', isEmpty);
    // Hide the order summary when the cart is empty — the spec
    // says the empty state is a full-width panel; the summary only
    // appears once there is something to summarize.
    const summary = $('[data-cart-summary]');
    if (summary) summary.hidden = isEmpty;

    // Counters and totals.
    const countEl = $('[data-cart-count]');
    if (countEl) countEl.textContent = itemCountLabel(count);

    const sumCount = $('[data-cart-summary-count]');
    if (sumCount) sumCount.textContent = String(count);

    const sumSubtotal = $('[data-cart-summary-subtotal]');
    const sumTotal = $('[data-cart-summary-total]');
    const sumMobileTotal = $('[data-cart-mobilebar-total]');
    const subtotal = items.reduce((s, l) => s + l.line_total, 0);
    const subtotalText = formatEGP(subtotal);
    if (sumSubtotal) sumSubtotal.textContent = subtotalText;
    if (sumTotal) sumTotal.textContent = subtotalText;
    if (sumMobileTotal) sumMobileTotal.textContent = subtotalText;

    // CTA state. The checkout CTAs are <a> elements (real links
    // that navigate to /checkout). Anchors have no native `disabled`,
    // so we mirror the disabled state with aria-disabled +
    // data-disabled + tabindex, and CSS uses [data-disabled="true"]
    // to dim the button and [aria-disabled="true"] to set
    // pointer-events: none so the link can't be followed.
    const cta = $('[data-cart-checkout]');
    if (cta) {
        if (isEmpty) {
            cta.setAttribute('aria-disabled', 'true');
            cta.setAttribute('data-disabled', 'true');
            cta.setAttribute('tabindex', '-1');
        } else {
            cta.setAttribute('aria-disabled', 'false');
            cta.removeAttribute('data-disabled');
            cta.removeAttribute('tabindex');
        }
    }
    const mobileCta = $('[data-cart-mobilebar-checkout]');
    if (mobileCta) {
        if (isEmpty) {
            mobileCta.setAttribute('aria-disabled', 'true');
            mobileCta.setAttribute('data-disabled', 'true');
            mobileCta.setAttribute('tabindex', '-1');
        } else {
            mobileCta.setAttribute('aria-disabled', 'false');
            mobileCta.removeAttribute('data-disabled');
            mobileCta.removeAttribute('tabindex');
        }
    }

    // Mobile bar visibility: only shown when there are items AND the
    // viewport is narrow (CSS handles the breakpoint).
    const mobileBar = $('[data-cart-mobilebar]');
    if (mobileBar) mobileBar.hidden = isEmpty;

    // Nav count badge (live, computed client-side from localStorage).
    syncNavCount(count);
}

function syncNavCount(n) {
    const triggers = $$('[data-nav-cart-count]');
    for (const el of triggers) {
        if (n > 0) {
            el.textContent = String(n);
            el.hidden = false;
        } else {
            el.hidden = true;
        }
    }
    // Also update the aria-label of the cart link.
    const link = $('[data-nav-cart]');
    if (link) {
        link.setAttribute('aria-label', n > 0 ? `Cart (${n} ${n === 1 ? 'item' : 'items'})` : 'Cart');
    }
}

// ─── UNDO toast ────────────────────────────────────────────────────

function showRemovedToast(name) {
    const host = $('[data-cart-toasts]');
    if (!host) return null;
    const toast = document.createElement('div');
    toast.className = 'cart__toast';
    toast.setAttribute('role', 'status');
    toast.innerHTML = `
        <span class="cart__toast-text"><strong>${escapeHtml(name)}</strong> removed</span>
        <button type="button" class="cart__toast-undo">UNDO</button>
    `;
    host.appendChild(toast);
    // Animate in next frame.
    requestAnimationFrame(() => toast.classList.add('is-shown'));
    return toast;
}

function removeToast(toast) {
    if (!toast) return;
    toast.classList.remove('is-shown');
    setTimeout(() => toast.remove(), 200);
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// ─── Pending-removal (UNDO) bookkeeping ────────────────────────────

const pendingRemovals = new Map(); // line_id → { line, timer, toast, rowEl }

// Soft-remove: pulls a line out of the cart immediately and lets
// the user restore it within 5s. The user said "do not delete on
// the server until the 5-second undo window ends". Since there is
// no server, we keep the removed line in a buffer and either
// restore it (UNDO) or drop it (timer / pagehide).
function softRemove(lineId) {
    const cart = hydratedCart();
    const line = cart.items.find((l) => l.line_id === lineId);
    if (!line) return;
    const row = document.querySelector(`.cart__row[data-line-id="${cssEscape(lineId)}"]`);
    if (row) row.classList.add('is-pending-removal');
    const toast = showRemovedToast(line.name);

    const undoBtn = toast?.querySelector('.cart__toast-undo');
    const onUndo = () => {
        const p = pendingRemovals.get(lineId);
        if (!p) return;
        clearTimeout(p.timer);
        pendingRemovals.delete(lineId);
        // Restore the line by re-adding (qty preserved).
        const cartNow = readCart();
        cartNow.items.push({
            line_id: line.line_id,
            slug:    line.slug,
            color:   line.color,
            size:    line.size,
            qty:     line.qty,
        });
        writeCart(cartNow);
        removeToast(toast);
        render();
    };
    if (undoBtn) undoBtn.addEventListener('click', onUndo, { once: true });

    // Now actually remove from storage so the totals update.
    removeLine(lineId);

    const timer = setTimeout(() => {
        // Window expired — drop the buffer entry permanently.
        pendingRemovals.delete(lineId);
        removeToast(toast);
        // Line was already removed from storage; just re-render.
        render();
    }, UNDO_WINDOW_MS);

    pendingRemovals.set(lineId, { line, timer, toast, rowEl: row });
    render();
}

function cssEscape(s) {
    if (window.CSS && window.CSS.escape) return window.CSS.escape(s);
    return String(s).replace(/[^a-zA-Z0-9_-]/g, (c) => '\\' + c);
}

// ─── Event wiring ──────────────────────────────────────────────────

function onListClick(ev) {
    const stepper = ev.target.closest('.cart__stepper-btn');
    if (stepper) {
        const row = stepper.closest('.cart__row');
        if (!row) return;
        const lineId = row.dataset.lineId;
        const action = stepper.dataset.stepperAction;
        const cart = hydratedCart();
        const line = cart.items.find((l) => l.line_id === lineId);
        if (!line) return;
        const delta = action === 'inc' ? 1 : -1;
        setQty(lineId, line.qty + delta);
        render();
        // Return focus to the same control (a11y).
        const refreshed = document.querySelector(`.cart__row[data-line-id="${cssEscape(lineId)}"] [data-stepper-action="${action}"]`);
        if (refreshed) refreshed.focus({ preventScroll: true });
        return;
    }
    const removeBtn = ev.target.closest('[data-remove-line]');
    if (removeBtn) {
        const row = removeBtn.closest('.cart__row');
        if (!row) return;
        softRemove(row.dataset.lineId);
        return;
    }
    // Block the checkout link when the cart is empty. The link
    // already has aria-disabled + data-disabled + tabindex="-1",
    // and CSS uses those to disable pointer events, but we still
    // preventDefault here as a belt-and-braces guard for keyboard
    // activation (Enter on a focused link).
    const cta = ev.target.closest('[data-cart-checkout], [data-cart-mobilebar-checkout]');
    if (cta && cta.getAttribute('aria-disabled') === 'true') {
        ev.preventDefault();
        return;
    }
}

function onKeydown(ev) {
    if (ev.key === 'Escape') {
        // Cancel all pending removals (no global "cancel" UI; keeps
        // semantics close to the toast UNDO button).
        for (const [lineId, p] of pendingRemovals) {
            clearTimeout(p.timer);
            removeToast(p.toast);
            const cartNow = readCart();
            cartNow.items.push({
                line_id: p.line.line_id,
                slug:    p.line.slug,
                color:   p.line.color,
                size:    p.line.size,
                qty:     p.line.qty,
            });
            writeCart(cartNow);
        }
        pendingRemovals.clear();
        render();
    }
}

// If the user leaves the page while removals are pending, drop
// the buffers permanently (the line was already removed from
// storage; this is just to free memory).
function flushOnLeave() {
    for (const [, p] of pendingRemovals) {
        clearTimeout(p.timer);
        removeToast(p.toast);
    }
    pendingRemovals.clear();
}

// Listen for cart state changes from other tabs / windows.
function onStorage(ev) {
    if (ev.key === STORAGE_KEY) render();
}

// ─── Init ──────────────────────────────────────────────────────────

function init() {
    const list = $('[data-cart-items]');
    if (list) list.addEventListener('click', onListClick);
    // Checkout CTAs are outside the items list, so listen at the
    // section level instead. This handles both the desktop
    // [data-cart-checkout] and the mobile [data-cart-mobilebar-checkout]
    // anchors, and we already guard against following the link when
    // the cart is empty.
    const section = $('.cart');
    if (section) section.addEventListener('click', onListClick);
    document.addEventListener('keydown', onKeydown);
    window.addEventListener('pagehide', flushOnLeave);
    window.addEventListener('storage', onStorage);

    // Always run render() on every page (it powers the nav badge
    // even when we're not on /cart). It's cheap.
    render();
}

// Public surface (handy for tests / external triggers).
window.ABOCart = {
    hydratedCart,
    addLine,
    setQty,
    removeLine,
    render,
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
