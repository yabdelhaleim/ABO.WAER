// ABO Cart — the server owns the cart.
//
// Every line, every price, every stock figure and the cart total come from
// the cart API. localStorage is only a cache: it lets the page paint a cart
// immediately instead of waiting for the network round trip, and it keeps the
// shape checkout.js reads. It is never the source of truth for a price, a
// stock count or the total.
//
// The endpoints (see routes/web.php):
//   GET    /cart/summary            → the whole cart plus its totals
//   POST   /cart/items              → add a variant
//   PATCH  /cart/items/{id}         → set an exact quantity
//   DELETE /cart/items/{id}         → drop a line
//
// Identity rule: one variant is one line. The same product in a different
// colour or a different size is a separate line, because that is the row the
// server stores.
//
// A guest and a signed-in customer use the same endpoints. The server keys a
// visitor's cart by the session id and a customer's by their account, so the
// client never has to know which one it is.
//
// The 10-unit cap is enforced in ONE place (MAX_QTY_PER_LINE). It mirrors
// CartService::MAX_QUANTITY_PER_LINE on the server, which is the figure that
// actually decides — this one only keeps the stepper honest.

import {
    STORAGE_KEY,
    apiRequest,
    assetUrl,
    findVariant,
    findVariantById,
    formatEGP,
    readCatalog,
    showToast,
} from './cart-client.js';

const MAX_QTY_PER_LINE = 10;
const UNDO_WINDOW_MS = 5000;

const SUMMARY_URL = '/cart/summary';
const ITEMS_URL = '/cart/items';

// ─── State ──────────────────────────────────────────────────────────

const state = {
    // The cart as it should be drawn right now. Once the server has
    // answered, this is exactly what the server said.
    lines: [],
    // The last subtotal the server reported. While a mutation is in flight
    // it carries an optimistic figure that the response replaces.
    subtotal: 0,
    // Mutations still in flight — used to keep the checkout link honest.
    pending: 0,
    // Has the server answered yet? Until it has, the drawing comes from
    // the cache and is treated as provisional.
    ready: false,
    // The shop's minimum order. The checkout refuses an order below it,
    // so the cart has to say how far the shopper is from it. Zero means
    // there is no minimum and no notice is shown.
    minimumOrder: 0,
};

// ─── Cache ──────────────────────────────────────────────────────────

/**
 * The browser's copy of the cart.
 *
 * It keeps the shape checkout.js reads — slug, colour, size, quantity — and
 * adds the variant id, because that is the identity the server uses.
 */
function readCache() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return [];

        const parsed = JSON.parse(raw);
        const items = Array.isArray(parsed?.items) ? parsed.items : [];

        return items.filter((line) => line && typeof line === 'object');
    } catch (e) {
        return [];
    }
}

function writeCache(lines) {
    const payload = JSON.stringify({
        items: lines.map((line) => ({
            line_id: line.line_id,
            slug: line.slug,
            color: line.color,
            size: line.size,
            qty: line.qty,
            variant_id: line.variant_id,
            id: line.id,
        })),
    });

    try {
        // Writing an identical value would wake every other tab for
        // nothing, and make them ask the server the same question again.
        if (localStorage.getItem(STORAGE_KEY) === payload) return;

        localStorage.setItem(STORAGE_KEY, payload);
    } catch (e) {
        // localStorage may be disabled (private mode). The server still
        // holds the cart, so the page keeps working.
    }
}

// ─── Hydration ─────────────────────────────────────────────────────

/**
 * A line drawn from the server's summary.
 *
 * The price and the total come from the server. The colour hex is taken
 * from the server too, so the swatch is right even for a product that is no
 * longer in the storefront catalog.
 */
function lineFromServer(item) {
    return {
        id: item.id ?? null,
        variant_id: item.variant_id ?? null,
        line_id: `v${item.variant_id}`,
        slug: item.slug ?? null,
        name: item.name ?? null,
        image: item.image ?? null,
        category: null,
        color: item.color_hex ?? null,
        color_name: item.color ?? null,
        size: item.size ?? null,
        price: Number(item.price) || 0,
        qty: Number(item.quantity) || 0,
        line_total: Number(item.line_total) || 0,
        available_stock: Number(item.available_stock) || 0,
        is_available: item.is_available !== false,
    };
}

/**
 * The cart as the cache remembers it, resolved against the catalog.
 *
 * A line is only kept when it still names a variant that exists. The lookup
 * runs against the product's variant list rather than product.colors,
 * because a product can carry variants whose colour has no row in the
 * product_colors pivot — dropping those would throw away a choice the
 * shopper can actually buy.
 */
function linesFromCache() {
    const catalog = readCatalog();
    const lines = [];

    for (const entry of readCache()) {
        const qty = Math.max(1, Math.min(MAX_QTY_PER_LINE, Math.floor(Number(entry.qty)) || 1));

        let product = null;
        let variant = null;

        if (entry.variant_id) {
            const found = findVariantById(catalog, entry.variant_id);
            if (found) {
                product = found.product;
                variant = found.variant;
            }
        }

        if (!variant) {
            const hit = findVariant(catalog, {
                slug: entry.slug,
                color: entry.color,
                size: entry.size,
            });

            if (hit) {
                product = findVariantById(catalog, hit.id)?.product || null;
                variant = hit;
            }
        }

        if (!variant) continue;

        const price = Number(variant.price) || 0;

        lines.push({
            id: entry.id ?? null,
            variant_id: variant.id,
            line_id: entry.line_id || `v${variant.id}`,
            slug: product ? product.slug : entry.slug ?? null,
            name: product ? product.name : null,
            image: product ? product.image : null,
            category: product ? product.category : null,
            color: variant.color ?? null,
            color_name: variant.color_name ?? null,
            size: variant.size ?? null,
            price,
            qty,
            line_total: price * qty,
            available_stock: Number(variant.stock) || 0,
            is_available: true,
        });
    }

    return lines;
}

/**
 * The cart as it stands.
 *
 * The server's answer wins once it has arrived; until then the cache is
 * painted so the page is never blank while the network catches up.
 */
function hydratedCart() {
    return {
        items: state.lines,
        subtotal: state.subtotal,
        ready: state.ready,
    };
}

/**
 * Take the server's summary as the truth.
 */
function applySummary(summary) {
    state.lines = (summary?.items || []).map(lineFromServer);
    state.subtotal = Number(summary?.subtotal) || 0;
    state.ready = true;

    // The minimum order travels with the summary. A summary that does
    // not carry it (an older endpoint, a cached response) leaves the
    // figure the page published in place rather than dropping it.
    if (summary && summary.minimum_order !== undefined) {
        state.minimumOrder = Number(summary.minimum_order) || 0;
    }

    writeCache(state.lines);
}

/**
 * The shop's rules the page published.
 *
 * The summary endpoint is the authority — it answers with the same
 * figures — but the page carries them too so the first paint can show
 * the notice before the first round trip lands.
 */
function readRules() {
    const el = document.querySelector('[data-cart-rules]');

    if (!el) return;

    try {
        const rules = JSON.parse(el.textContent || '{}');

        if (rules && rules.minimum_order !== undefined) {
            state.minimumOrder = Number(rules.minimum_order) || 0;
        }
    } catch (err) {
        console.warn('Cart: failed to read the shop rules', err);
    }
}

/**
 * Pull the cart from the server.
 *
 * A failure leaves whatever is on screen alone and says so — the page never
 * pretends the cart is empty because a request did not land.
 */
async function loadSummary({ quiet = false } = {}) {
    const result = await apiRequest('GET', SUMMARY_URL);

    if (!result.ok) {
        if (!quiet) {
            showToast({ text: result.message || 'We could not load your cart.', tone: 'error' });
        }

        return false;
    }

    applySummary(result.data);
    render();

    return true;
}

// ─── Public mutation API ───────────────────────────────────────────

/**
 * Add a variant to the cart.
 *
 * The line is drawn straight away and the server is told afterwards. A
 * refusal rolls the line back and surfaces the reason — out of stock, over
 * the per-line cap, a variant that is no longer for sale.
 *
 * @param {{variant_id?: number, slug?: string, color?: string, size?: string, qty?: number}} options
 */
async function addLine({ variant_id = null, slug = null, color = null, size = null, qty = 1 } = {}) {
    const catalog = readCatalog();
    let variantId = variant_id ? Number(variant_id) : null;

    if (!variantId) {
        const variant = findVariant(catalog, { slug, color, size });
        if (!variant) {
            showToast({ text: 'That option is no longer available.', tone: 'error' });

            return null;
        }
        variantId = variant.id;
    }

    const quantity = Math.max(1, Math.min(MAX_QTY_PER_LINE, Math.floor(Number(qty)) || 1));

    state.pending += 1;
    render();

    const result = await apiRequest('POST', ITEMS_URL, {
        variant_id: variantId,
        quantity,
    });

    state.pending -= 1;

    if (!result.ok) {
        showToast({ text: result.message || 'We could not add that to your cart.', tone: 'error' });
        render();

        return null;
    }

    applySummary(result.data?.summary);
    render();

    return result.data?.item_id ?? null;
}

/**
 * Set an exact quantity on a line.
 */
async function setQty(lineId, qty) {
    const line = state.lines.find((entry) => entry.line_id === lineId);
    if (!line) return null;

    const quantity = Math.max(1, Math.min(MAX_QTY_PER_LINE, Math.floor(Number(qty)) || 1));
    const previous = line.qty;

    if (quantity === previous) return line;

    if (!line.id) {
        // The line has no server row yet, so there is nothing to patch.
        await loadSummary({ quiet: true });

        return null;
    }

    // Draw the new quantity now; the server's answer replaces it.
    line.qty = quantity;
    line.line_total = line.price * quantity;
    state.subtotal = state.lines.reduce((sum, entry) => sum + entry.line_total, 0);
    writeCache(state.lines);
    render();

    state.pending += 1;

    const result = await apiRequest('PATCH', `${ITEMS_URL}/${line.id}`, {
        quantity,
    });

    state.pending -= 1;

    if (!result.ok) {
        showToast({ text: result.message || 'We could not change that quantity.', tone: 'error' });

        // Put the line back the way the server still has it.
        line.qty = previous;
        line.line_total = line.price * previous;
        state.subtotal = state.lines.reduce((sum, entry) => sum + entry.line_total, 0);
        writeCache(state.lines);
        render();

        return null;
    }

    applySummary(result.data?.summary);
    render();

    return line;
}

/**
 * Drop a line from the cart on the server.
 *
 * `knownLine` lets the caller hand over the line it already holds. The
 * undo window needs it: by the time the window closes the line is no
 * longer in `state.lines`, so looking it up there would find nothing
 * and the delete would never be sent.
 */
async function removeLine(lineId, knownLine = null) {
    const line = knownLine || state.lines.find((entry) => entry.line_id === lineId);
    if (!line) return false;

    if (!line.id) {
        await loadSummary({ quiet: true });

        return false;
    }

    const result = await apiRequest('DELETE', `${ITEMS_URL}/${line.id}`);

    if (!result.ok) {
        showToast({ text: result.message || 'We could not remove that item.', tone: 'error' });

        // The server refused, so it still holds the line — put it back
        // on screen before asking the server what is really there, so
        // the page and the server never disagree.
        restoreLine(line);
        await loadSummary({ quiet: true });

        return false;
    }

    // The delete endpoint answers 204 with no body, so there is no
    // summary in the answer to apply. The server is asked again
    // instead of guessing that the cart is now empty.
    await loadSummary({ quiet: true });

    return true;
}

/**
 * Put a line back on screen.
 *
 * Used when a delete is refused: the server still holds the line, so
 * the page has to show it again. A line that vanished from the screen
 * while the server kept it would leave the two out of step.
 */
function restoreLine(line) {
    if (!line) return false;

    if (state.lines.some((entry) => entry.line_id === line.line_id)) {
        return false;
    }

    state.lines.push(line);
    state.subtotal = state.lines.reduce((sum, entry) => sum + entry.line_total, 0);
    writeCache(state.lines);
    render();

    return true;
}

// ─── Rendering ─────────────────────────────────────────────────────

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

function itemCount(cart) {
    return cart.items.reduce((sum, line) => sum + line.qty, 0);
}

function itemCountLabel(n) {
    return `${n} ${n === 1 ? 'item' : 'items'}`;
}

function renderRow(line) {
    const li = document.createElement('li');
    li.className = 'cart__row';
    li.dataset.lineId = line.line_id;
    li.dataset.productSlug = line.slug || '';
    li.setAttribute('aria-label', `Cart line for ${line.name || 'this item'}`);

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
    nameLink.textContent = line.name || 'This item';
    body.appendChild(nameLink);

    if (line.color_name || line.color || line.size) {
        const chips = document.createElement('div');
        chips.className = 'cart__chips';

        if (line.color || line.color_name) {
            const chip = document.createElement('span');
            chip.className = 'cart__chip';

            // The swatch needs a real colour value, so it takes the hex.
            if (line.color) {
                const dot = document.createElement('span');
                dot.className = 'cart__chip-dot';
                dot.style.setProperty('--swatch', line.color);
                chip.appendChild(dot);
            }

            chip.appendChild(document.createTextNode(`Color · ${line.color_name || line.color}`));
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
    stepper.setAttribute('aria-label', `Quantity for ${line.name || 'this item'}`);

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
    remove.setAttribute('aria-label', `Remove ${line.name || 'this item'} from cart`);
    remove.textContent = 'REMOVE';
    body.appendChild(remove);

    li.appendChild(body);

    // Line total.
    const total = document.createElement('div');
    total.className = 'cart__line-total';
    total.dataset.lineTotal = '';
    total.setAttribute('aria-live', 'polite');

    const totalLabel = document.createElement('span');
    totalLabel.className = 'cart__line-total-label';
    totalLabel.textContent = 'Item total';

    const totalValue = document.createElement('span');
    totalValue.className = 'cart__line-total-value';
    totalValue.textContent = formatEGP(line.line_total);

    total.appendChild(totalLabel);
    total.appendChild(totalValue);
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

function productShowUrl(slug) {
    if (!slug) return '/shop';

    return '/shop/' + encodeURIComponent(slug);
}

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

    // Hide the order summary when the cart is empty — the spec says the
    // empty state is a full-width panel; the summary only appears once
    // there is something to summarize.
    const summary = $('[data-cart-summary]');
    if (summary) summary.hidden = isEmpty;

    // Counters and totals. The subtotal is the server's figure.
    const countEl = $('[data-cart-count]');
    if (countEl) countEl.textContent = itemCountLabel(count);

    const sumCount = $('[data-cart-summary-count]');
    if (sumCount) sumCount.textContent = String(count);

    const sumSubtotal = $('[data-cart-summary-subtotal]');
    const sumTotal = $('[data-cart-summary-total]');
    const sumMobileTotal = $('[data-cart-mobilebar-total]');
    const subtotalText = formatEGP(cart.subtotal);
    if (sumSubtotal) sumSubtotal.textContent = subtotalText;
    if (sumTotal) sumTotal.textContent = subtotalText;
    if (sumMobileTotal) sumMobileTotal.textContent = subtotalText;

    // CTA state. The checkout CTAs are <a> elements (real links that
    // navigate to /checkout). Anchors have no native `disabled`, so we
    // mirror the disabled state with aria-disabled + data-disabled +
    // tabindex, and CSS uses [data-disabled="true"] to dim the button and
    // [aria-disabled="true"] to set pointer-events: none so the link can't
    // be followed.
    //
    // A cart below the shop's minimum order is disabled too: the
    // checkout refuses that order, so letting the shopper walk into it
    // only to be turned away at the last step is worse than saying so
    // here, while they can still add something.
    const belowMinimum = !isEmpty && state.minimumOrder > 0 && cart.subtotal < state.minimumOrder;
    const ctaBlocked = isEmpty || belowMinimum;

    const cta = $('[data-cart-checkout]');
    if (cta) {
        if (ctaBlocked) {
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
        if (ctaBlocked) {
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

    // The minimum order notice. It is shown while the cart is short of
    // the shop's minimum, and hidden once the cart clears it — the
    // checkout refuses such an order, so the shopper is told here,
    // where they can still add something.
    renderMinimumNotice(isEmpty);

    // Nav count badge (live, computed from the cart as it stands).
    syncNavCount(count);
}

/**
 * The minimum-order notice under the cart summary.
 *
 * A store with no minimum order has no notice at all: the element
 * stays hidden and nothing is written into it.
 */
function renderMinimumNotice(isEmpty) {
    const note = $('[data-cart-minimum-note]');

    if (!note) return;

    const subtotal = state.subtotal;

    if (isEmpty || state.minimumOrder <= 0 || subtotal >= state.minimumOrder) {
        note.hidden = true;
        note.textContent = '';

        return;
    }

    const remaining = Math.max(0, state.minimumOrder - subtotal);

    note.hidden = false;
    note.textContent = `Add ${formatEGP(remaining)} more to reach the ${formatEGP(state.minimumOrder)} minimum order.`;
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

// ─── Pending-removal (UNDO) bookkeeping ────────────────────────────

const pendingRemovals = new Map(); // line_id → { line, timer, toast }

// Pull a line out of the cart and let the shopper undo it.
//
// The server is not told until the undo window closes, so a line that is
// restored never had to be re-created. Until the window ends the line is
// gone from the screen and from the cache.
function softRemove(lineId) {
    const line = state.lines.find((entry) => entry.line_id === lineId);
    if (!line) return;

    const row = document.querySelector(`.cart__row[data-line-id="${cssEscape(lineId)}"]`);
    if (row) row.classList.add('is-pending-removal');

    state.lines = state.lines.filter((entry) => entry.line_id !== lineId);
    state.subtotal = state.lines.reduce((sum, entry) => sum + entry.line_total, 0);
    writeCache(state.lines);
    render();

    const toast = showToast({
        strong: line.name || 'This item',
        text: 'removed',
        actionLabel: 'UNDO',
        onAction: () => restorePendingRemoval(lineId),
    });

    const timer = setTimeout(() => {
        pendingRemovals.delete(lineId);
        toast?.dismiss();

        // The undo window closed — the server is told for good. The
        // line has been off `state.lines` since the click, so it is
        // handed over here: looking it up by id would find nothing and
        // the delete would never reach the server.
        removeLine(lineId, line);
    }, UNDO_WINDOW_MS);

    pendingRemovals.set(lineId, { line, timer, toast });
}

/**
 * Put a line back after the shopper hit UNDO.
 *
 * The server never heard about the removal, so nothing has to be sent.
 */
function restorePendingRemoval(lineId) {
    const pending = pendingRemovals.get(lineId);
    if (!pending) return;

    clearTimeout(pending.timer);
    pendingRemovals.delete(lineId);

    // The server never heard about the removal, so nothing has to be
    // sent — the line simply goes back where it was.
    restoreLine(pending.line);

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
        const line = state.lines.find((entry) => entry.line_id === lineId);
        if (!line) return;

        const delta = action === 'inc' ? 1 : -1;
        setQty(lineId, line.qty + delta);

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

    // Block the checkout link when the cart is empty. The link already has
    // aria-disabled + data-disabled + tabindex="-1", and CSS uses those to
    // disable pointer events, but we still preventDefault here as a
    // belt-and-braces guard for keyboard activation.
    const cta = ev.target.closest('[data-cart-checkout], [data-cart-mobilebar-checkout]');
    if (cta && cta.getAttribute('aria-disabled') === 'true') {
        ev.preventDefault();
    }
}

function onKeydown(ev) {
    if (ev.key === 'Escape') {
        // Cancel every pending removal — the lines were never deleted on
        // the server, so restoring them is enough.
        for (const lineId of Array.from(pendingRemovals.keys())) {
            restorePendingRemoval(lineId);
        }
    }
}

/**
 * The shopper left the page while a removal was still inside its undo
 * window. The line is gone from the screen, so the server has to hear about
 * it — `keepalive` lets the request outlive the page.
 */
function flushOnLeave() {
    for (const [lineId, pending] of pendingRemovals) {
        clearTimeout(pending.timer);
        pending.toast?.dismiss();

        if (pending.line.id) {
            apiRequest('DELETE', `${ITEMS_URL}/${pending.line.id}`, null, { keepalive: true });
        }

        pendingRemovals.delete(lineId);
    }
}

/**
 * Another tab changed the cart. The server is the truth, so ask it.
 */
function onStorage(ev) {
    if (ev.key === STORAGE_KEY) loadSummary({ quiet: true });
}

// ─── Init ──────────────────────────────────────────────────────────

function init() {
    const list = $('[data-cart-items]');
    if (list) list.addEventListener('click', onListClick);

    // Checkout CTAs are outside the items list, so listen at the section
    // level instead. This handles both the desktop [data-cart-checkout] and
    // the mobile [data-cart-mobilebar-checkout] anchors.
    const section = $('.cart');
    if (section) section.addEventListener('click', onListClick);

    document.addEventListener('keydown', onKeydown);
    window.addEventListener('pagehide', flushOnLeave);
    window.addEventListener('storage', onStorage);

    // The product page tells the cart it changed something.
    window.addEventListener('abo:cart-updated', () => loadSummary({ quiet: true }));

    // Paint what the cache remembers straight away, then let the server
    // have the last word.
    state.lines = linesFromCache();
    state.subtotal = state.lines.reduce((sum, line) => sum + line.line_total, 0);
    readRules();
    writeCache(state.lines);
    render();

    // On the cart page a failed load is worth saying out loud — the lines
    // on screen are only the cache. Elsewhere the badge can stay as it is.
    loadSummary({ quiet: !list });
}

// Public surface (handy for tests / external triggers).
window.ABOCart = {
    hydratedCart,
    addLine,
    setQty,
    removeLine,
    render,
    loadSummary,
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
