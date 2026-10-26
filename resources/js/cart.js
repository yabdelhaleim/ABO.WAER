// ABO Cart.
//
// State lives in localStorage under the key `abo_cart` AND on the server
// (cart_items table, via CartService). The browser copy is the instant
// UI cache; the server copy is the one that can actually be ordered.
//
// Schema (localStorage):
//   {
//     items: [
//       {
//         line_id: string,         // generated client-side, used as the line key
//         slug:    string,         // must exist in the products payload
//         color:   string|null,    // must be one of the product's real colors, if set
//         size:    string|null,    // null until real sizes exist in the data
//         qty:     int,            // clamped into [1, MAX_QTY_PER_LINE] on every write
//         variant_id: int|null,    // the variants.id the line points at
//         server_id:  int|null,    // the cart_items.id the server assigned
//         name:    string|null     // display fallback for pages with no catalog payload
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
// Every mutation is mirrored to the server so the checkout can place a
// real order: POST /cart/items, PATCH /cart/items/{id}, DELETE
// /cart/items/{id}. The server owns the prices and the stock; a
// rejected call reverts the optimistic local edit.
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

// ─── Server bridge ─────────────────────────────────────────────────
//
// The browser copy of the cart is only a cache. The order itself is
// placed from the rows CartService keeps in `cart_items`, so every
// mutation is mirrored to the API. The server owns the prices and the
// stock; a rejected call means the local edit has to be undone.

const CART_API = {
    summary: '/cart/summary',
    items: '/cart/items',
};

function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

/**
 * One JSON call against the cart API.
 *
 * @returns {Promise<{ok: boolean, status: number, data: any, message: string}>}
 */
async function apiRequest(url, method = 'GET', body = null) {
    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    if (method !== 'GET') {
        headers['Content-Type'] = 'application/json';
        headers['X-CSRF-TOKEN'] = csrfToken();
    }

    let response;
    try {
        response = await fetch(url, {
            method,
            headers,
            credentials: 'same-origin',
            body: body === null ? undefined : JSON.stringify(body),
        });
    } catch (e) {
        return { ok: false, status: 0, data: null, message: 'Network error. Check your connection.' };
    }

    let data = null;
    try {
        data = await response.json();
    } catch (e) {
        data = null;
    }

    let message = '';
    if (data && typeof data.message === 'string') message = data.message;
    if (!message && data && data.errors) {
        const first = Object.values(data.errors)[0];
        if (Array.isArray(first) && first.length) message = String(first[0]);
        else if (typeof first === 'string') message = first;
    }
    if (!message && !response.ok) message = 'Request failed (' + response.status + ')';

    return { ok: response.ok, status: response.status, data, message };
}

/**
 * Add a variant on the server and hand back the assigned cart_items id.
 */
async function serverAdd(variantId, quantity = 1) {
    const result = await apiRequest(CART_API.items, 'POST', {
        variant_id: Number(variantId),
        quantity: Number(quantity) || 1,
    });

    if (!result.ok) {
        return { ok: false, message: result.message || 'Could not add this item to your cart.' };
    }

    return {
        ok: true,
        itemId: result.data && result.data.item_id ? Number(result.data.item_id) : null,
        summary: result.data ? result.data.summary : null,
    };
}

/**
 * Set an exact quantity on the server line.
 */
async function serverSetQty(serverId, quantity) {
    const result = await apiRequest(`${CART_API.items}/${encodeURIComponent(serverId)}`, 'PATCH', {
        quantity: Number(quantity),
    });

    return {
        ok: result.ok,
        message: result.message || 'Could not update the quantity.',
        summary: result.data ? result.data.summary : null,
    };
}

/**
 * Drop a line from the server.
 */
async function serverRemove(serverId) {
    const result = await apiRequest(`${CART_API.items}/${encodeURIComponent(serverId)}`, 'DELETE');

    return { ok: result.ok, message: result.message || 'Could not remove this item.' };
}

// ─── Hydration ─────────────────────────────────────────────────────

/**
 * Returns the current cart, re-validated against the products
 * payload. Lines whose slug is missing or whose color is not a
 * real color of the product are dropped. Qty is clamped.
 *
 * The products payload only ships with the cart view. On every other
 * page there is nothing to validate against, so the stored lines are
 * kept untouched — dropping them there would silently wipe the
 * shopper's cart on every navigation, and the checkout page would
 * always bounce back to /cart with an empty cart.
 */
function hydratedCart() {
    const products = readProducts();
    const productsBySlug = new Map(products.map((p) => [p.slug, p]));
    const raw = readCart();
    const items = [];
    let changed = false;

    for (const line of raw.items) {
        if (!line || typeof line !== 'object') { changed = true; continue; }

        const color = (typeof line.color === 'string' && line.color !== '') ? line.color : null;
        const size = (typeof line.size === 'string' && line.size !== '') ? line.size : null;

        const rawQty = Number(line.qty);
        const qty = Math.max(1, Math.min(MAX_QTY_PER_LINE, Number.isFinite(rawQty) ? Math.floor(rawQty) : 1));
        if (qty !== line.qty) changed = true;

        // Without a payload there is no product to check the line
        // against, so it is kept as stored.
        if (products.length > 0) {
            const product = productsBySlug.get(line.slug);
            if (!product) { changed = true; continue; }

            const productColors = Array.isArray(product.colors) ? product.colors : [];
            if (color !== null && !productColors.includes(color)) { changed = true; continue; }
        }

        items.push({
            line_id:    String(line.line_id),
            slug:       String(line.slug || ''),
            color,
            size,
            qty,
            variant_id: Number.isFinite(Number(line.variant_id)) && line.variant_id !== null ? Number(line.variant_id) : null,
            server_id:  Number.isFinite(Number(line.server_id)) && line.server_id !== null ? Number(line.server_id) : null,
            name:       typeof line.name === 'string' && line.name !== '' ? line.name : null,
        });
    }

    if (changed) writeCart({ items });

    // Decorate with display fields (name, price, image, line_total)
    // without ever trusting the stored values. The name is the only
    // exception: it is a label, not a figure, and it is only used on
    // pages that ship no catalog payload (the product page).
    return {
        items: items.map((line) => {
            const product = productsBySlug.get(line.slug);
            return {
                ...line,
                name:       product ? product.name : (line.name || line.slug),
                price:      product ? product.price : 0,
                image:      product ? product.image : null,
                category:   product ? product.category : null,
                line_total: product ? product.price * line.qty : 0,
            };
        }),
    };
}

/**
 * Push a quantity change to the server and roll it back if the server
 * refuses it (not enough stock, the variant was pulled, the network
 * dropped).
 */
async function syncQuantity(line, nextQty, previousQty) {
    let result;

    if (line.server_id !== null && line.server_id !== undefined) {
        result = await serverSetQty(line.server_id, nextQty);
    } else if (line.variant_id !== null && line.variant_id !== undefined) {
        // The line never reached the server (an add that failed, or a
        // cart restored from an old browser cache) — add it now.
        const added = await serverAdd(line.variant_id, nextQty);
        if (added.ok) {
            const cart = readCart();
            const target = cart.items.find((l) => l.line_id === line.line_id);
            if (target) {
                target.server_id = added.itemId;
                writeCart(cart);
            }
            render();
            return;
        }
        result = { ok: false, message: added.message };
    } else {
        // Nothing to sync — the line has no variant behind it.
        return;
    }

    if (!result.ok) {
        setQty(line.line_id, previousQty);
        render();
        showNotice(result.message || 'Could not update the quantity.');
    }
}

// ─── Public mutation API (used by tests if needed) ────────────────

/**
 * Add a line to the browser copy of the cart.
 *
 * `variant_id` and `server_id` are what tie the line to the server
 * row; without them the line still renders, but it cannot be ordered
 * until it has been re-added through the API.
 */
function addLine({ slug, color = null, size = null, qty = 1, variant_id = null, server_id = null, name = null }) {
    const products = readProducts();
    const product = products.find((p) => p.slug === slug);
    // A catalog payload is published on /cart and /checkout only. On
    // the product page there is none, and the line is still valid —
    // the cart page re-decorates it from its own payload. When a
    // payload IS present it stays the authority on what is orderable.
    if (products.length > 0 && !product) return null;
    if (product && color !== null && !(product.colors || []).includes(color)) return null;
    const cart = readCart();
    const safeQty = Math.max(1, Math.min(MAX_QTY_PER_LINE, qty));
    const existing = cart.items.find((l) => l.slug === slug && l.color === color && l.size === size);
    if (existing) {
        existing.qty = Math.max(1, Math.min(MAX_QTY_PER_LINE, existing.qty + safeQty));
        if (variant_id !== null) existing.variant_id = Number(variant_id);
        if (server_id !== null) existing.server_id = Number(server_id);
    } else {
        cart.items.push({
            line_id: cryptoRandomId(),
            slug, color, size,
            qty: safeQty,
            variant_id: variant_id === null ? null : Number(variant_id),
            server_id: server_id === null ? null : Number(server_id),
            name: name || null,
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

/**
 * Read the server's copy of the cart.
 *
 * @returns {Promise<{ok: boolean, message: string, items: Array<object>, summary: object|null}>}
 */
async function serverSummary() {
    const result = await apiRequest(CART_API.summary);

    if (!result.ok) {
        return { ok: false, message: result.message || 'Could not load your cart.', items: [], summary: null };
    }

    const data = result.data || {};

    return {
        ok: true,
        message: '',
        items: Array.isArray(data.items) ? data.items : [],
        summary: data,
    };
}

/**
 * Reconcile the browser copy of the cart with the server's.
 *
 * Two things can drift:
 *   - a line the shopper removed but whose undo window expired while
 *     the tab was closed — the server still has it;
 *   - a line added in another tab — the server has it, the browser
 *     does not.
 *
 * Lines that never reached the server (an offline add) are left alone;
 * only rows the server knows about are reconciled.
 */
async function reconcileWithServer() {
    const state = await serverSummary();
    if (!state.ok) return { ok: false, message: state.message, changed: false };

    const cart = readCart();
    const serverItems = state.items;
    const byVariant = new Map();
    for (const item of serverItems) {
        if (item && item.variant_id !== undefined && item.variant_id !== null) {
            byVariant.set(Number(item.variant_id), item);
        }
    }

    const knownServerIds = new Set(serverItems.map((i) => Number(i.id)));
    let changed = false;

    // Drop local lines the server no longer has.
    const kept = [];
    for (const line of cart.items) {
        if (line.server_id !== null && !knownServerIds.has(Number(line.server_id))) {
            changed = true;
            continue;
        }
        kept.push(line);
    }

    // Adopt server lines the browser does not have.
    for (const item of serverItems) {
        const local = kept.find((l) => (
            (l.variant_id !== null && Number(l.variant_id) === Number(item.variant_id))
            || (l.slug && l.slug === item.slug && (l.color || '').toLowerCase() === String(item.color || '').toLowerCase())
        ));
        if (!local) {
            kept.push({
                line_id: cryptoRandomId(),
                slug: String(item.slug || ''),
                color: null,
                size: item.size || null,
                qty: Math.max(1, Math.min(MAX_QTY_PER_LINE, Number(item.quantity) || 1)),
                variant_id: item.variant_id === undefined ? null : Number(item.variant_id),
                server_id: Number(item.id),
                name: item.name || null,
            });
            changed = true;
            continue;
        }
        if (local.server_id === null) {
            local.server_id = Number(item.id);
            changed = true;
        }
        if (local.variant_id === null && item.variant_id !== undefined) {
            local.variant_id = Number(item.variant_id);
            changed = true;
        }
    }

    if (changed) writeCart({ items: kept });

    return { ok: true, message: '', changed, summary: state.summary };
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

/**
 * Turn an image path stored in the database into a URL the browser can
 * load. Mirrors App\Services\ImageUrl: an image saved on the `public`
 * disk lives under /storage/, and an absolute URL is used as-is.
 */
function assetUrl(path) {
    if (!path) return path;
    if (path.startsWith('http://') || path.startsWith('https://') || path.startsWith('//')) return path;
    if (path.startsWith('data:')) return path;
    const trimmed = path.replace(/^\/+/, '');
    if (trimmed.startsWith('storage/')) return '/' + trimmed;
    return '/storage/' + trimmed;
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

/**
 * A short-lived message in the toast slot. Used for the "the server
 * said no" cases — out of stock, a rejected quantity, a network drop.
 */
function showNotice(message, tone = 'error') {
    const host = $('[data-cart-toasts]');
    if (!host || !message) return null;
    const toast = document.createElement('div');
    toast.className = 'cart__toast' + (tone === 'error' ? ' cart__toast--error' : '');
    toast.setAttribute('role', tone === 'error' ? 'alert' : 'status');
    const text = document.createElement('span');
    text.className = 'cart__toast-text';
    text.textContent = message;
    toast.appendChild(text);
    host.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('is-shown'));
    setTimeout(() => removeToast(toast), TOAST_TIMEOUT_MS);
    return toast;
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// ─── Pending-removal (UNDO) bookkeeping ────────────────────────────

const pendingRemovals = new Map(); // line_id → { line, timer, toast, rowEl }

// Soft-remove: pulls a line out of the cart immediately and lets
// the user restore it within 5s. The user said "do not delete on
// the server until the 5-second undo window ends" — so the server
// row is only dropped once the window closes. An UNDO puts the line
// back locally and leaves the server row alone.
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
            variant_id: line.variant_id,
            server_id:  line.server_id,
            name:    line.name,
        });
        writeCart(cartNow);
        removeToast(toast);
        render();
    };
    if (undoBtn) undoBtn.addEventListener('click', onUndo, { once: true });

    // Now actually remove from storage so the totals update.
    removeLine(lineId);

    const timer = setTimeout(() => {
        // Window expired — drop the buffer entry and the server row.
        pendingRemovals.delete(lineId);
        removeToast(toast);
        // Line was already removed from storage; just re-render.
        render();
        if (line.server_id !== null && line.server_id !== undefined) {
            serverRemove(line.server_id).then((result) => {
                if (!result.ok) showNotice(result.message || 'Could not remove this item.');
            });
        }
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
        const previousQty = line.qty;
        setQty(lineId, line.qty + delta);
        render();
        // Return focus to the same control (a11y).
        const refreshed = document.querySelector(`.cart__row[data-line-id="${cssEscape(lineId)}"] [data-stepper-action="${action}"]`);
        if (refreshed) refreshed.focus({ preventScroll: true });
        // The server owns the stock, so the change has to be confirmed
        // there. A rejection puts the quantity back.
        syncQuantity(line, line.qty + delta, previousQty);
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
                variant_id: p.line.variant_id,
                server_id:  p.line.server_id,
                name:    p.line.name,
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

    // On the cart page the server holds the authoritative copy of the
    // cart. Reconcile once the first paint is done so a line that was
    // removed in another tab (or whose undo window expired) does not
    // survive into the checkout.
    if (list) {
        reconcileWithServer().then((result) => {
            if (result.ok && result.changed) render();
        });
    }
}

// Public surface (handy for tests / external triggers).
window.ABOCart = {
    hydratedCart,
    addLine,
    setQty,
    removeLine,
    render,
    serverAdd,
    serverSetQty,
    serverRemove,
    serverSummary,
    reconcileWithServer,
    showNotice,
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
