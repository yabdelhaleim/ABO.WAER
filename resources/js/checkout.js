// ABO Checkout.
//
// The order itself is placed by the server (POST /checkout →
// OrderService::placeOrder). The browser's job is to validate, to
// send the form, and to follow the redirect the server hands back.
//
// On place order, the JS:
//   1. Re-validates every input client-side (mirrors the server
//      rules enforced by CheckoutRequest).
//   2. Re-hydrates the cart from localStorage + the products JSON
//      tag in the page; drops nothing silently — any invalid line
//      rejects the whole submission and returns the user to /cart.
//   3. Posts the whole form (FormData, so every named field travels
//      with it) to /checkout and asks for a JSON answer.
//   4. On success: writes a short payload to sessionStorage under
//      `abo_last_order`, clears the abo_cart localStorage key and
//      follows the server's `redirect_url`.
//   5. On a 422: maps the server's field errors back onto the form.
//
// The success page reads the same sessionStorage key. If it is
// missing (direct visit, refresh, etc.) it falls back to the order
// number in the URL.
// Prices and totals are never trusted from the client: every total
// shown is recomputed from the products JSON at render time, and the
// server prices the order from the database.

const STORAGE_KEY = 'abo_cart';
const ORDER_STORAGE_KEY = 'abo_last_order';
const MAX_QTY_PER_LINE = 10;
const MAX_CART_LINES = 20;
const PHONE_REGEX = /^01[0125][0-9]{8}$/;
const PHONE_NORMALIZE_REGEX = /[\s\-+]/g;
const EGYPT_MOBILE_REGEX = /^(\+?20)?0?1[0125][0-9]{8}$/;

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

function readJSON(sel) {
    const tag = $(sel);
    if (!tag) return null;
    try { return JSON.parse(tag.textContent || 'null'); } catch (e) { return null; }
}

function formatEGP(n) {
    return `${Number(n).toLocaleString('en-EG')} EGP`;
}

function readCart() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return { items: [] };
        const parsed = JSON.parse(raw);
        return { items: Array.isArray(parsed?.items) ? parsed.items : [] };
    } catch (e) {
        return { items: [] };
    }
}

function readProducts() {
    return readJSON('[data-checkout-products]') || [];
}

function readGovernorates() {
    return readJSON('[data-checkout-governorates]') || [];
}

function readPaymentMethods() {
    return readJSON('[data-checkout-payment-methods]') || [];
}

function writeOrder(order) {
    try { sessionStorage.setItem(ORDER_STORAGE_KEY, JSON.stringify(order)); } catch (e) { /* private mode */ }
}

function readOrder() {
    try {
        const raw = sessionStorage.getItem(ORDER_STORAGE_KEY);
        return raw ? JSON.parse(raw) : null;
    } catch (e) { return null; }
}

function clearCart() {
    try { localStorage.removeItem(STORAGE_KEY); } catch (e) { /* private mode */ }
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

function generateOrderNumber() {
    // Fallback only. The order number is minted by the server inside
    // the order transaction; this is here for the rare case where the
    // server answers without one, so the page still has something to
    // show. Six zero-padded digits, prefix matches the spec.
    const n = Math.floor(Math.random() * 1_000_000);
    return 'ABO-' + n.toString().padStart(6, '0');
}

// ─── Server bridge ─────────────────────────────────────────────────

function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

/**
 * Post the checkout form and ask for a JSON answer.
 *
 * The body is a FormData built from the form itself, so every field
 * the server knows about travels with the request without this module
 * having to name them.
 */
async function submitOrder(form) {
    let response;
    try {
        response = await fetch(form.getAttribute('action') || '/checkout', {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
        });
    } catch (e) {
        return { ok: false, status: 0, data: null, message: 'Network error. Check your connection and try again.' };
    }

    let data = null;
    try {
        data = await response.json();
    } catch (e) {
        data = null;
    }

    return { ok: response.ok, status: response.status, data, message: '' };
}

/**
 * Paint the server's validation errors onto the matching fields.
 */
function applyServerErrors(errors) {
    if (!errors || typeof errors !== 'object') return false;
    let painted = false;
    for (const [field, messages] of Object.entries(errors)) {
        const message = Array.isArray(messages) ? messages[0] : messages;
        if (!message) continue;
        if (showFieldError(field, String(message))) painted = true;
    }
    return painted;
}

/**
 * A message that belongs to the form as a whole (an empty cart, a
 * stock problem, a server error).
 */
function showFormError(message) {
    const form = $('[data-checkout-form]');
    if (!form || !message) return;
    let errEl = form.querySelector('.checkout__form-error');
    if (!errEl) {
        errEl = document.createElement('p');
        errEl.className = 'checkout__form-error';
        errEl.setAttribute('role', 'alert');
        form.prepend(errEl);
    }
    errEl.textContent = message;
}

function clearFormError() {
    const errEl = $('[data-checkout-form] .checkout__form-error');
    if (errEl) errEl.remove();
}

// ─── Hydration ─────────────────────────────────────────────────────

/**
 * Same shape as cart.js's hydratedCart, but stricter: any unknown
 * slug, any color not in the product, any qty out of range, or any
 * line that exceeds the count cap returns null and surfaces a
 * reason. We never silently drop a line.
 */
function validateCartStrict(rawItems, productsBySlug) {
    if (!Array.isArray(rawItems) || rawItems.length === 0) {
        return { ok: false, reason: 'empty' };
    }
    if (rawItems.length > MAX_CART_LINES) {
        return { ok: false, reason: 'too_many_lines' };
    }
    const out = [];
    for (let i = 0; i < rawItems.length; i++) {
        const line = rawItems[i];
        if (!line || typeof line !== 'object' || !line.slug) {
            return { ok: false, reason: `Line ${i + 1} is missing a product.` };
        }
        const product = productsBySlug.get(line.slug);
        if (!product) {
            return { ok: false, reason: `Line ${i + 1} references a product that no longer exists.` };
        }
        const color = (typeof line.color === 'string' && line.color !== '') ? line.color : null;
        if (color !== null && !product.colors.includes(color)) {
            return { ok: false, reason: `Line ${i + 1} has a color that is no longer offered.` };
        }
        const size = (typeof line.size === 'string' && line.size !== '') ? line.size : null;
        const qty = Math.floor(Number(line.qty));
        if (!Number.isFinite(qty) || qty < 1 || qty > MAX_QTY_PER_LINE) {
            return { ok: false, reason: `Line ${i + 1} has an invalid quantity.` };
        }
        out.push({
            line_id: String(line.line_id || `pre-${i}`),
            slug: product.slug,
            name: product.name,
            price: product.price,
            image: product.image,
            category: product.category,
            color,
            size,
            qty,
            line_total: product.price * qty,
        });
    }
    return { ok: true, items: out };
}

function hydrate() {
    const products = readProducts();
    const productsBySlug = new Map(products.map((p) => [p.slug, p]));
    const result = validateCartStrict(readCart().items, productsBySlug);
    if (!result.ok) {
        // Send the user back to /cart with a reason. localStorage is
        // not cleared here — the cart page can show the same reason
        // and the user can fix the bad line themselves.
        const reason = encodeURIComponent(result.reason);
        window.location.assign(`/cart?reason=${reason}`);
        return null;
    }
    const subtotal = result.items.reduce((s, l) => s + l.line_total, 0);
    return { items: result.items, subtotal };
}

// ─── Rendering ─────────────────────────────────────────────────────

/**
 * Build the markup for one line of the order summary.
 *
 * Shared by every copy of the summary on the page — the mobile panel
 * and the desktop rail show the same lines.
 */
function buildSummaryItem(line) {
    const li = document.createElement('li');
    li.className = 'checkout__summary-item';

    const thumb = document.createElement('div');
    thumb.className = 'checkout__summary-thumb';
    if (line.image) {
        const img = document.createElement('img');
        img.className = 'checkout__summary-thumb-img';
        img.src = assetUrl(line.image);
        img.alt = '';
        img.loading = 'lazy';
        img.width = 56;
        img.height = 70;
        thumb.appendChild(img);
    } else {
        const init = document.createElement('span');
        init.className = 'checkout__summary-thumb-initial';
        init.textContent = (line.name || '?').charAt(0).toUpperCase();
        thumb.appendChild(init);
    }
    const qtyBadge = document.createElement('span');
    qtyBadge.className = 'checkout__summary-qty';
    qtyBadge.textContent = String(line.qty);
    thumb.appendChild(qtyBadge);
    li.appendChild(thumb);

    const body = document.createElement('div');
    body.className = 'checkout__summary-body';
    const name = document.createElement('p');
    name.className = 'checkout__summary-name';
    name.textContent = line.name;
    body.appendChild(name);

    if (line.color || line.size) {
        const chips = document.createElement('p');
        chips.className = 'checkout__summary-chips';
        const parts = [];
        if (line.color) parts.push(`Color · ${line.color}`);
        if (line.size)  parts.push(`Size · ${line.size}`);
        chips.textContent = parts.join(' · ');
        body.appendChild(chips);
    }

    const lineTotal = document.createElement('p');
    lineTotal.className = 'checkout__summary-line-total';
    lineTotal.textContent = formatEGP(line.line_total);
    body.appendChild(lineTotal);

    li.appendChild(body);
    return li;
}

function renderSummary(cart) {
    const count = cart.items.reduce((s, l) => s + l.qty, 0);
    const subtotalText = formatEGP(cart.subtotal);

    // The order summary is rendered twice — a mobile copy inside the
    // collapsible panel and the desktop rail. Every copy has to be
    // filled in, otherwise whichever one the visitor is looking at
    // keeps showing "0 items / 0 EGP".
    $$('[data-checkout-summary]').forEach((summary) => {
        const list = $('[data-checkout-summary-items]', summary);
        if (list) {
            list.replaceChildren(...cart.items.map((line) => buildSummaryItem(line)));
        }

        const summaryCount = $('[data-checkout-summary-count]', summary);
        if (summaryCount) summaryCount.textContent = String(count);

        const summarySubtotal = $('[data-checkout-summary-subtotal]', summary);
        if (summarySubtotal) summarySubtotal.textContent = subtotalText;

        const summaryTotal = $('[data-checkout-summary-total]', summary);
        if (summaryTotal) summaryTotal.textContent = subtotalText;
    });

    const mobileCount = $('[data-checkout-mobile-summary-count]');
    if (mobileCount) mobileCount.textContent = String(count);
    const mobileTotal = $('[data-checkout-mobile-summary-total]');
    if (mobileTotal) mobileTotal.textContent = subtotalText;
    const mobileBarTotal = $('[data-checkout-mobilebar-total]');
    if (mobileBarTotal) mobileBarTotal.textContent = subtotalText;
}

function renderEmpty() {
    // The JS that called us already redirected to /cart. As a
    // safety net, render a faint "no items" state so the page
    // isn't visually broken if the redirect is blocked.
    $$('[data-checkout-summary-items]').forEach((list) => list.replaceChildren());
}

// ─── Field validation (mirrors the server-side rules) ──────────────

function showFieldError(name, message) {
    const wrap = document.querySelector(`[data-checkout-field="${name}"]`);
    if (!wrap) return false;
    wrap.dataset.error = 'true';
    wrap.classList.add('is-error');
    let errEl = wrap.querySelector('.checkout__error');
    if (!errEl) {
        errEl = document.createElement('p');
        errEl.className = 'checkout__error';
        errEl.id = 'checkout-field-' + name + '-error';
        errEl.setAttribute('role', 'alert');
        errEl.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" class="checkout__error-icon"><path d="M12 2 1 21h22L12 2zm0 6 7.5 13h-15L12 8zm-1 4v4h2v-4h-2zm0 5v2h2v-2h-2z" fill="currentColor"/></svg><span></span>';
        wrap.appendChild(errEl);
    }
    errEl.querySelector('span').textContent = message;
    const input = wrap.querySelector('.checkout__input');
    if (input) {
        input.setAttribute('aria-invalid', 'true');
        input.setAttribute('aria-describedby', errEl.id);
    }

    return true;
}

function clearFieldError(name) {
    const wrap = document.querySelector(`[data-checkout-field="${name}"]`);
    if (!wrap) return;
    delete wrap.dataset.error;
    wrap.classList.remove('is-error');
    const input = wrap.querySelector('.checkout__input');
    if (input) {
        input.removeAttribute('aria-invalid');
        input.removeAttribute('aria-describedby');
    }
    const errEl = wrap.querySelector('.checkout__error');
    if (errEl) errEl.remove();
}

function normalizePhone(raw) {
    // Strip spaces, dashes, plus signs, and an optional +20 / 20
    // country code. Must end up 11 digits starting with 01.
    const cleaned = String(raw || '').replace(/[\s\-]/g, '');
    if (EGYPT_MOBILE_REGEX.test(cleaned)) {
        // Take the last 10 digits, prefix 0.
        const last10 = cleaned.replace(/^(\+?20)?0?/, '').slice(-10);
        return '0' + last10;
    }
    return cleaned;
}

function validateForm() {
    const fields = {
        name:        { required: true,  max: 100 },
        phone:       { required: true,  validator: (v) => PHONE_REGEX.test(normalizePhone(v)) || 'Enter a valid Egyptian mobile number (01XXXXXXXXX).' },
        governorate: { required: true,  validator: (v) => readGovernorates().includes(v) || 'Choose a governorate from the list.' },
        area:        { required: true,  max: 100 },
        address:     { required: true,  max: 500 },
        notes:       { required: false, max: 1000 },
    };

    const values = {};
    let firstInvalid = null;
    for (const [name, opts] of Object.entries(fields)) {
        const input = document.querySelector(`[name="${name}"]`);
        if (!input) continue;
        let v = (input.value || '').trim();
        if (name === 'phone') v = normalizePhone(v);
        values[name] = v;
        clearFieldError(name);

        if (opts.required && !v) {
            showFieldError(name, 'This field is required.');
            if (!firstInvalid) firstInvalid = input;
            continue;
        }
        if (opts.max && v && v.length > opts.max) {
            showFieldError(name, `Must be ${opts.max} characters or fewer.`);
            if (!firstInvalid) firstInvalid = input;
            continue;
        }
        if (opts.validator && v) {
            const result = opts.validator(v);
            if (result !== true) {
                showFieldError(name, result);
                if (!firstInvalid) firstInvalid = input;
            }
        }
    }

    // Payment method must be set.
    const payment = document.querySelector('[name="payment_method"]:checked');
    if (!payment) {
        showFieldError('payment_method', 'Choose a payment method.');
        if (!firstInvalid) firstInvalid = document.querySelector('[name="payment_method"]');
        values.payment_method = '';
    } else {
        const allowed = readPaymentMethods();
        if (!Object.prototype.hasOwnProperty.call(allowed, payment.value)) {
            showFieldError('payment_method', 'Selected payment method is not available.');
            if (!firstInvalid) firstInvalid = payment;
        }
        values.payment_method = payment.value;
    }

    return { ok: !firstInvalid, values, firstInvalid };
}

// ─── Submit flow ───────────────────────────────────────────────────

function setBusy(form, busy) {
    const submit = form.querySelector('[data-checkout-submit]');
    if (submit) {
        submit.disabled = busy;
        submit.setAttribute('aria-busy', busy ? 'true' : 'false');
        submit.classList.toggle('is-busy', busy);
    }
    const mobileSubmit = $('[data-checkout-mobilebar-submit]');
    if (mobileSubmit) {
        mobileSubmit.disabled = busy;
        mobileSubmit.setAttribute('aria-busy', busy ? 'true' : 'false');
    }
    form.setAttribute('aria-busy', busy ? 'true' : 'false');
}

function onSubmit(ev) {
    ev.preventDefault();
    const form = ev.currentTarget;
    const result = validateForm();
    if (!result.ok) {
        if (result.firstInvalid && typeof result.firstInvalid.focus === 'function') {
            result.firstInvalid.focus({ preventScroll: false });
        }
        return;
    }

    // Re-hydrate the cart one more time and reject on any drift.
    const cart = hydrate();
    if (!cart) return; // hydrate() already redirected.

    setBusy(form, true);
    clearFormError();

    // Populate hidden fields. The server prices the order from the
    // database; cart_json is the fallback for a cart that only ever
    // lived in the browser.
    const cartJson = $('[data-checkout-cart-json]');
    if (cartJson) cartJson.value = JSON.stringify({
        items: cart.items.map((l) => ({
            slug: l.slug,
            color: l.color,
            size: l.size,
            qty: l.qty,
            variant_id: l.variant_id,
        })),
    });
    const expectedTotal = $('[data-checkout-expected-total]');
    if (expectedTotal) expectedTotal.value = String(cart.subtotal);

    submitOrder(form).then((response) => {
        if (!response.ok) {
            setBusy(form, false);

            if (response.status === 422 && response.data && response.data.errors) {
                if (!applyServerErrors(response.data.errors)) {
                    showFormError('Please check the highlighted fields.');
                }
                const firstKey = Object.keys(response.data.errors)[0];
                const firstInput = firstKey ? document.querySelector(`[name="${firstKey}"]`) : null;
                if (firstInput && typeof firstInput.focus === 'function') {
                    firstInput.focus({ preventScroll: false });
                }
                return;
            }

            showFormError(response.message || 'We could not place the order. Please try again.');
            return;
        }

        const payload = response.data || {};
        const orderNumber = payload.order_number || generateOrderNumber();

        // Keep a short-lived copy of the order for the success page.
        // The server sends the stored order back with the response, so
        // the copy carries the same figures the database does.
        const order = payload.order && typeof payload.order === 'object'
            ? payload.order
            : {
                number: orderNumber,
                subtotal: cart.subtotal,
                items: cart.items,
                paymentMethod: result.values.payment_method || 'cod',
                placedAt: Date.now(),
            };
        writeOrder(order);
        clearCart();

        // Follow the server to the order it actually created.
        const target = payload.redirect_url || `/checkout/success/${orderNumber}`;
        window.location.assign(target);
    });
}

function onMobileBarSubmit() {
    const form = $('[data-checkout-form]');
    if (form) {
        const submit = form.querySelector('[data-checkout-submit]');
        if (submit) submit.click();
    }
}

// ─── Page init ─────────────────────────────────────────────────────

function init() {
    const form = $('[data-checkout-form]');
    if (!form) return; // probably on a different page

    // Hydrate the summary; redirect to /cart on empty.
    const cart = hydrate();
    if (!cart) return;
    if (cart.items.length === 0) {
        window.location.assign('/cart?reason=empty');
        return;
    }
    renderSummary(cart);

    form.addEventListener('submit', onSubmit);
    const mobileSubmit = $('[data-checkout-mobilebar-submit]');
    if (mobileSubmit) mobileSubmit.addEventListener('click', onMobileBarSubmit);

    // Clear errors as the user types.
    form.addEventListener('input', (ev) => {
        const t = ev.target;
        if (t && t.name) clearFieldError(t.name);
    });
    form.addEventListener('change', (ev) => {
        const t = ev.target;
        if (t && t.name) clearFieldError(t.name);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
