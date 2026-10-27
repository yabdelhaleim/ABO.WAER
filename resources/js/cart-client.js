// ABO Cart — the shared client for the server-backed cart.
//
// The server owns the cart. This module is the thin layer both the cart page
// and the product page talk to: it carries the CSRF token, unwraps Laravel's
// JSON envelopes, reads the catalog payload the pages publish so a shopper's
// colour × size choice resolves to a real variant id, and renders the one
// toast style the storefront already has.
//
// The endpoints it speaks to (see routes/web.php):
//   GET    /cart/summary            → the whole cart plus its totals
//   POST   /cart/items              → add a variant
//   PATCH  /cart/items/{id}         → set an exact quantity
//   DELETE /cart/items/{id}         → drop a line
//
// Guests are welcome on all of them: CartService keys a visitor's cart by
// the session id and a signed-in customer's by their account. The client
// never needs to know which one it is talking to.

export const STORAGE_KEY = 'abo_cart';

export function formatEGP(value) {
    return `${Number(value).toLocaleString('en-EG')} EGP`;
}

export function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');

    return meta ? meta.getAttribute('content') : '';
}

/**
 * Turn a stored image path into a URL the browser can load.
 *
 * The catalog and the cart summary publish absolute URLs already (the
 * server builds them with `asset()`), but a path relative to the
 * `storage/app/public` root is still accepted so an older payload does
 * not render a broken image.
 */
export function assetUrl(path) {
    if (!path) return path;
    if (path.startsWith('http') || path.startsWith('/') || path.startsWith('data:')) return path;

    return '/storage/' + path.replace(/^\/+/, '');
}

/**
 * The catalog payload the cart page publishes.
 *
 * Returns [] on a page that does not carry one — the nav badge still works
 * there, because it only needs the count the summary endpoint returns.
 */
export function readCatalog() {
    const tag = document.querySelector('[data-cart-products]');
    if (!tag) return [];

    try {
        const parsed = JSON.parse(tag.textContent || '[]');

        return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
        return [];
    }
}

/**
 * Find a product in the catalog by slug.
 */
export function findProduct(catalog, slug) {
    if (!Array.isArray(catalog) || !slug) return null;

    return catalog.find((product) => product && product.slug === slug) || null;
}

/**
 * The variant of a product matching a colour hex and a size name.
 *
 * Colours and sizes are matched against the product's variant list — never
 * against product.colors alone. A product can carry variants whose colour
 * has no row in the product_colors pivot, and such a product is still
 * perfectly buyable; matching on the pivot would throw the shopper's choice
 * away.
 */
export function findVariant(catalog, { slug, color = null, size = null } = {}) {
    const product = findProduct(catalog, slug);
    if (!product || !Array.isArray(product.variants)) return null;

    const wantedColor = color ? String(color).trim().toUpperCase() : null;
    const wantedSize = size ? String(size).trim().toUpperCase() : null;

    const matches = (variant) => {
        if (wantedColor && String(variant.color || '').trim().toUpperCase() !== wantedColor) {
            return false;
        }
        if (wantedSize && String(variant.size || '').trim().toUpperCase() !== wantedSize) {
            return false;
        }

        return true;
    };

    return product.variants.find(matches) || null;
}

/**
 * Find a variant by its id anywhere in the catalog.
 *
 * @returns {{product: object, variant: object}|null}
 */
export function findVariantById(catalog, variantId) {
    const wanted = Number(variantId);
    if (!Array.isArray(catalog) || !Number.isFinite(wanted)) return null;

    for (const product of catalog) {
        if (!product || !Array.isArray(product.variants)) continue;

        const variant = product.variants.find((v) => Number(v.id) === wanted);
        if (variant) return { product, variant };
    }

    return null;
}

/**
 * Pull the human-readable message out of a Laravel error response.
 *
 * A 422 carries `errors: {quantity: ["Only 3 units left in stock."]}` under
 * a generic `message`, so the field-level text is the one worth showing.
 */
export function extractErrorMessage(data, fallback) {
    if (data && data.errors && typeof data.errors === 'object') {
        for (const value of Object.values(data.errors)) {
            if (Array.isArray(value) && value.length) return String(value[0]);
            if (typeof value === 'string' && value) return value;
        }
    }

    if (data && typeof data.message === 'string' && data.message) return data.message;

    return fallback;
}

/**
 * Talk to the cart API.
 *
 * Always resolves — never throws — so a caller can report the failure
 * instead of pretending the call worked.
 *
 * @returns {Promise<{ok: boolean, status: number, data: object|null, message: string|null}>}
 */
export async function apiRequest(method, url, payload = null, { keepalive = false } = {}) {
    const headers = {
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrfToken(),
    };

    const options = {
        method,
        headers,
        credentials: 'same-origin',
        keepalive,
    };

    if (payload !== null && payload !== undefined) {
        headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(payload);
    }

    let response;

    try {
        response = await fetch(url, options);
    } catch (e) {
        return {
            ok: false,
            status: 0,
            data: null,
            message: 'We could not reach the store. Check your connection and try again.',
        };
    }

    let data = null;

    try {
        data = await response.json();
    } catch (e) {
        data = null;
    }

    if (!response.ok) {
        return {
            ok: false,
            status: response.status,
            data,
            message: extractErrorMessage(data, 'Something went wrong. Please try again.'),
        };
    }

    return { ok: true, status: response.status, data, message: null };
}

/**
 * Show a message in the storefront's one toast style.
 *
 * The host is the page's `[data-cart-toasts]` slot; a page without one
 * simply shows nothing, so this never throws on a page that has no slot.
 *
 * @returns {{dismiss: function, element: HTMLElement}|null}
 */
export function showToast({
    text = '',
    strong = null,
    actionLabel = null,
    onAction = null,
    tone = null,
    timeout = 5000,
} = {}) {
    const host = document.querySelector('[data-cart-toasts]');
    if (!host) return null;

    const toast = document.createElement('div');
    toast.className = 'cart__toast';
    toast.setAttribute('role', tone === 'error' ? 'alert' : 'status');

    const label = document.createElement('span');
    label.className = 'cart__toast-text';

    if (strong) {
        const bold = document.createElement('strong');
        bold.textContent = strong;
        label.appendChild(bold);
        label.appendChild(document.createTextNode(` ${text}`));
    } else {
        label.textContent = text;
    }

    toast.appendChild(label);

    let timer = null;

    const dismiss = () => {
        if (timer) clearTimeout(timer);
        toast.classList.remove('is-shown');
        setTimeout(() => toast.remove(), 200);
    };

    if (actionLabel && typeof onAction === 'function') {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'cart__toast-undo';
        button.textContent = actionLabel;
        button.addEventListener('click', () => {
            onAction();
            dismiss();
        }, { once: true });
        toast.appendChild(button);
    }

    host.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('is-shown'));

    timer = setTimeout(dismiss, timeout);

    return { dismiss, element: toast };
}
