// ABO Checkout.
//
// The server owns the cart and the order. This module:
//   1. Reads the cart back from GET /cart/summary — the same rows
//      OrderService::resolveCartItems() turns into an order — so the
//      summary a customer reviews is the summary they are charged for.
//   2. Validates the form in the browser for immediate feedback.
//   3. Posts the fields to Store\CheckoutController::process. The
//      server creates the order, takes the stock off, applies the
//      coupon and answers 201 with the order number and the success
//      URL; a 422 carries per-field messages we paint inline.
//
// The client never invents an order number and never writes an order
// to storage: if the request fails, no order exists, and the page says
// so instead of showing a fabricated confirmation.

import { apiRequest, assetUrl } from './cart-client.js';

const SUMMARY_URL = '/cart/summary';
const PHONE_REGEX = /^01[0125][0-9]{8}$/;
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

function readGovernorates() {
    return readJSON('[data-checkout-governorates]') || [];
}

function readPaymentMethods() {
    return readJSON('[data-checkout-payment-methods]') || [];
}

// ─── Hydration ─────────────────────────────────────────────────────

/**
 * The cart as the server sees it.
 *
 * `GET /cart/summary` returns the persisted lines with their variant id,
 * their effective price and their stock. The shape is normalised here to
 * the one the summary renderer works with, so the renderer stays a pure
 * function of one array.
 */
function presentItem(item) {
    return {
        line_id: String(item.id),
        variant_id: item.variant_id,
        slug: item.slug,
        name: item.name || '',
        price: Number(item.price) || 0,
        image: item.image || null,
        color: item.color || null,
        size: item.size || null,
        qty: Number(item.quantity) || 0,
        line_total: Number(item.line_total) || 0,
        available_stock: Number(item.available_stock) || 0,
    };
}

async function hydrate() {
    const result = await apiRequest('GET', SUMMARY_URL);

    if (!result.ok) {
        showAlert(result.message || 'We could not load your cart. Please try again.');

        return null;
    }

    const summary = result.data || {};
    const items = Array.isArray(summary.items) ? summary.items.map(presentItem) : [];

    if (items.length === 0) {
        window.location.assign('/cart?reason=empty');

        return null;
    }

    return {
        items,
        subtotal: Number(summary.subtotal) || 0,
    };
}

// ─── Rendering ─────────────────────────────────────────────────────

function renderSummary(cart) {
    const list = $('[data-checkout-summary-items]');
    if (list) {
        list.replaceChildren(...cart.items.map((line) => {
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
        }));
    }

    const count = cart.items.reduce((s, l) => s + l.qty, 0);
    const subtotalText = formatEGP(cart.subtotal);

    const summaryCount = $('[data-checkout-summary-count]');
    if (summaryCount) summaryCount.textContent = String(count);
    const summarySubtotal = $('[data-checkout-summary-subtotal]');
    if (summarySubtotal) summarySubtotal.textContent = subtotalText;
    const summaryTotal = $('[data-checkout-summary-total]');
    if (summaryTotal) summaryTotal.textContent = subtotalText;
    const mobileCount = $('[data-checkout-mobile-summary-count]');
    if (mobileCount) mobileCount.textContent = String(count);
    const mobileTotal = $('[data-checkout-mobile-summary-total]');
    if (mobileTotal) mobileTotal.textContent = subtotalText;
    const mobileBarTotal = $('[data-checkout-mobilebar-total]');
    if (mobileBarTotal) mobileBarTotal.textContent = subtotalText;
}

// ─── Alerts ────────────────────────────────────────────────────────

/**
 * The page-level error banner.
 *
 * The Blade renders one only when the server bounced a submission back
 * with validation errors; this creates the same markup on demand for
 * the failures that happen in the browser (a failed request, an empty
 * cart).
 */
function showAlert(message) {
    if (!message) return;

    let alert = $('[data-checkout-alert]');

    if (!alert) {
        const form = $('[data-checkout-form]');
        if (!form) return;

        alert = document.createElement('div');
        alert.className = 'checkout__alert';
        alert.setAttribute('role', 'alert');
        alert.dataset.checkoutAlert = '';

        const body = document.createElement('div');
        const title = document.createElement('p');
        title.className = 'checkout__alert-title';
        title.textContent = 'We could not place your order.';
        body.appendChild(title);

        const list = document.createElement('ul');
        list.className = 'checkout__alert-list';
        body.appendChild(list);

        alert.appendChild(body);
        form.prepend(alert);
    }

    const list = alert.querySelector('.checkout__alert-list');
    if (list) {
        const item = document.createElement('li');
        item.textContent = message;
        list.replaceChildren(item);
    }

    alert.hidden = false;
}

function clearAlert() {
    const alert = $('[data-checkout-alert]');
    if (alert) alert.hidden = true;
}

// ─── Field validation (mirrors the server-side rules) ──────────────

function showFieldError(name, message) {
    const wrap = document.querySelector(`[data-checkout-field="${name}"]`);
    if (!wrap) return;
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

/**
 * Paint the server's 422 body onto the form.
 *
 * Laravel returns `{errors: {field: [message, …]}}`. Every entry that
 * matches a form field becomes an inline error; the ones that do not
 * (a stock problem, an empty cart) become the page-level banner.
 */
function applyServerErrors(errors) {
    let bannerMessage = null;

    for (const [field, messages] of Object.entries(errors || {})) {
        const message = Array.isArray(messages) ? messages[0] : String(messages);
        const wrap = document.querySelector(`[data-checkout-field="${field}"]`);

        if (wrap) {
            showFieldError(field, message);
            continue;
        }

        bannerMessage = bannerMessage || message;
    }

    return bannerMessage;
}

async function onSubmit(ev) {
    ev.preventDefault();
    const form = ev.currentTarget;

    clearAlert();

    const result = validateForm();
    if (!result.ok) {
        if (result.firstInvalid && typeof result.firstInvalid.focus === 'function') {
            result.firstInvalid.focus({ preventScroll: false });
        }
        return;
    }

    const formData = new FormData(form);

    setBusy(form, true);

    const response = await apiRequest('POST', form.action, {
        name: result.values.name,
        phone: result.values.phone,
        email: formData.get('email') || null,
        governorate: result.values.governorate,
        area: result.values.area,
        address: result.values.address,
        notes: result.values.notes || null,
        payment_method: result.values.payment_method,
        cart_json: formData.get('cart_json') || null,
        coupon_code: formData.get('coupon_code') || null,
    });

    if (response.ok && response.data && response.data.redirect_url) {
        // The order exists. The server clears the persisted cart inside
        // the same transaction; the browser copy is dropped here so the
        // next visit starts from an empty cart.
        try { localStorage.removeItem('abo_cart'); } catch (e) { /* private mode */ }

        window.location.assign(response.data.redirect_url);

        return;
    }

    setBusy(form, false);

    if (response.status === 422 && response.data && response.data.errors) {
        const banner = applyServerErrors(response.data.errors);

        showAlert(banner || response.message || 'Please fix the highlighted fields.');

        return;
    }

    showAlert(response.message || 'We could not place your order. Please try again.');
}

function onMobileBarSubmit() {
    const form = $('[data-checkout-form]');
    if (form) {
        const submit = form.querySelector('[data-checkout-submit]');
        if (submit) submit.click();
    }
}

// ─── Page init ─────────────────────────────────────────────────────

async function init() {
    const form = $('[data-checkout-form]');
    if (!form) return; // probably on a different page

    // Hydrate the summary from the server cart; redirect to /cart when
    // it is empty.
    const cart = await hydrate();
    if (!cart) return;

    renderSummary(cart);

    // The hidden fields the server reads.
    const cartJson = $('[data-checkout-cart-json]');
    if (cartJson) {
        cartJson.value = JSON.stringify({
            items: cart.items.map((l) => ({
                variant_id: l.variant_id,
                slug: l.slug,
                qty: l.qty,
            })),
        });
    }
    const expectedTotal = $('[data-checkout-expected-total]');
    if (expectedTotal) expectedTotal.value = String(cart.subtotal);

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
