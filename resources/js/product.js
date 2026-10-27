// ABO Product — color × size variant selector and add to cart.
//
// Reads the JSON blob that product.blade.php emits (data-product-variants).
// On color change:
//   - Highlights the chosen swatch.
//   - Updates the gallery hero image to one belonging to that color
//     (falls back gracefully when the color has no images).
//   - Filters the size list to only show sizes in stock for that color.
//   - Updates the price to the cheapest in-stock variant for that color.
// On size click:
//   - Highlights the chosen size; size is sticky on color change when still
//     in stock; otherwise we auto-select the first remaining in-stock size.
//
// The ADD TO CART button posts the chosen variant to the cart API, which is
// where the cart lives. The variant id travels with the payload, so the
// server knows exactly which colour × size row the shopper picked.

import { apiRequest, formatEGP, showToast } from './cart-client.js';

const CART_ITEMS_URL = '/cart/items';

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

function readVariants(root) {
    const node = $('[data-product-variants]', root);
    if (!node) return null;

    try {
        return JSON.parse(node.textContent || '{}');
    } catch (e) {
        return null;
    }
}

function findFirstInStockSize(color) {
    if (!color || !Array.isArray(color.sizes)) return null;
    return color.sizes.find((s) => Number(s.stock) > 0) || null;
}

function setActiveColor(root, colorId) {
    $$('[data-product-colors] [data-color-id]', root).forEach((btn) => {
        const id = Number(btn.dataset.colorId);
        const isActive = id === colorId;
        btn.classList.toggle('is-active', isActive);
        btn.setAttribute('aria-pressed', String(isActive));
    });
}

function setActiveSize(root, colorId, sizeId) {
    $$('[data-product-sizes] [data-color-id]', root).forEach((el) => {
        el.hidden = Number(el.dataset.colorId) !== colorId;
    });

    let chosen = null;
    $$('[data-product-sizes] [data-color-id="' + colorId + '"]', root).forEach((el) => {
        const id = Number(el.dataset.sizeId);
        const isActive = id === sizeId;
        el.classList.toggle('is-active', isActive);
        const btn = el.querySelector('button');
        if (btn) btn.setAttribute('aria-pressed', String(isActive));
        if (isActive) chosen = el;
    });

    return chosen;
}

function updateGallery(root, colorBlock) {
    const main = $('[data-product-gallery-main]', root);
    if (!main) return;

    const image = colorBlock?.image;
    if (!image) return;

    main.src = image;
    main.alt = colorBlock?.name ? `${root.dataset.productName} in ${colorBlock.name}` : main.alt;
}

function updatePrice(root, colorBlock) {
    const out = $('[data-product-price]', root);
    if (!out) return;

    const first = findFirstInStockSize(colorBlock);
    if (first) {
        out.textContent = formatEGP(first.price);
        return;
    }
    // Fallback: cheapest variant price overall.
    const fallback = (colorBlock?.sizes || [])
        .map((s) => Number(s.price))
        .sort((a, b) => a - b)[0];
    if (fallback !== undefined) {
        out.textContent = formatEGP(fallback);
    }
}

function updateSizeEmptyState(root, colorBlock) {
    const empty = $('[data-product-size-empty]', root);
    if (!empty) return;
    const hasAny = (colorBlock?.sizes || []).some((s) => Number(s.stock) > 0);
    empty.hidden = hasAny;
}

function updateAtc(root, colorBlock) {
    const label = $('[data-product-atc-label]', root);
    const button = $('[data-product-atc]', root);
    if (!label && !button) return;

    // Prefer the size the shopper actually picked; fall back to the first
    // one that is still in stock for this colour.
    const active = $('[data-product-sizes] [data-color-id="' + colorBlock?.id + '"].is-active', root);
    const activeSize = active
        ? (colorBlock?.sizes || []).find((s) => Number(s.id) === Number(active.dataset.sizeId))
        : null;

    const chosen = activeSize && Number(activeSize.stock) > 0
        ? activeSize
        : findFirstInStockSize(colorBlock);

    if (!chosen) {
        if (label) label.textContent = 'OUT OF STOCK';
        if (button) {
            button.disabled = true;
            button.setAttribute('aria-disabled', 'true');
        }

        return;
    }

    if (label) label.textContent = `ADD — ${colorBlock?.name?.toUpperCase() ?? ''} / ${chosen.name}`.trim();

    // The cart is live, so the button is live too — as soon as there is a
    // colour and a size with stock behind them.
    if (button) {
        button.disabled = false;
        button.removeAttribute('aria-disabled');
    }
}

/**
 * The variant the shopper has settled on: the active colour together with
 * the active size. Null when the choice is incomplete.
 */
function selectedVariant(root, data) {
    const activeColor = $('[data-product-colors] [data-color-id].is-active', root);
    if (!activeColor) return null;

    const colorId = Number(activeColor.dataset.colorId);
    const colorBlock = (data.colors || []).find((c) => Number(c.id) === colorId);
    if (!colorBlock) return null;

    const activeSize = $('[data-product-sizes] [data-color-id="' + colorId + '"].is-active', root);
    if (!activeSize) return null;

    const sizeId = Number(activeSize.dataset.sizeId);
    const variant = (colorBlock.sizes || []).find((s) => Number(s.id) === sizeId);
    if (!variant) return null;

    return { colorBlock, variant };
}

/**
 * Add the chosen variant to the cart.
 *
 * The button reports what happened either way: a refusal from the server
 * (out of stock, over the cap) is shown with its reason, and only a real
 * success is announced as one.
 */
function bindAddToCart(root, data) {
    const button = $('[data-product-atc]', root);
    if (!button) return;

    button.addEventListener('click', async () => {
        const picked = selectedVariant(root, data);

        if (!picked) {
            showToast({ text: 'Choose a colour and a size first.', tone: 'error' });

            return;
        }

        if (Number(picked.variant.stock) < 1) {
            showToast({ text: 'That size is out of stock.', tone: 'error' });

            return;
        }

        const label = $('[data-product-atc-label]', root);
        const original = label ? label.textContent : '';

        button.disabled = true;
        if (label) label.textContent = 'ADDING…';

        const result = await apiRequest('POST', CART_ITEMS_URL, {
            variant_id: picked.variant.variant_id,
            quantity: 1,
        });

        button.disabled = false;
        if (label) label.textContent = original;

        if (!result.ok) {
            showToast({ text: result.message || 'We could not add that to your cart.', tone: 'error' });

            return;
        }

        // The storefront's own arrival pulse.
        button.classList.add('is-arrived');
        setTimeout(() => button.classList.remove('is-arrived'), 1000);

        showToast({ strong: picked.colorBlock?.name || 'Item', text: 'added to your cart.' });

        // Let the cart refresh the header badge.
        window.dispatchEvent(new CustomEvent('abo:cart-updated'));
    });
}

function pickColor(root, data, colorId, preferredSizeId = null) {
    const colorBlock = (data.colors || []).find((c) => Number(c.id) === Number(colorId));
    if (!colorBlock) return;

    setActiveColor(root, colorBlock.id);
    updateGallery(root, colorBlock);
    updatePrice(root, colorBlock);
    updateSizeEmptyState(root, colorBlock);

    // Preferred size if still in stock; otherwise the first in-stock size.
    let targetSizeId = preferredSizeId;
    const has = (colorBlock.sizes || []).some((s) => Number(s.id) === Number(preferredSizeId) && Number(s.stock) > 0);
    if (!has) {
        const fallback = findFirstInStockSize(colorBlock);
        targetSizeId = fallback?.id ?? null;
    }

    setActiveSize(root, colorBlock.id, targetSizeId);
    updateAtc(root, colorBlock);
}

function bindColorButtons(root, data) {
    $$('[data-product-colors] [data-color-id]', root).forEach((btn) => {
        btn.addEventListener('click', () => {
            const colorId = Number(btn.dataset.colorId);
            const currentSize = $('[data-product-sizes] [data-color-id="' + colorId + '"].is-active', root);
            const preferredSizeId = currentSize ? Number(currentSize.dataset.sizeId) : null;
            pickColor(root, data, colorId, preferredSizeId);
        });
    });

    $$('[data-product-sizes] button', root).forEach((btn) => {
        btn.addEventListener('click', () => {
            const li = btn.closest('[data-color-id]');
            if (!li || li.hidden) return;
            const sizeId = Number(li.dataset.sizeId);
            $$('[data-product-sizes] button', root).forEach((b) => b.setAttribute('aria-pressed', 'false'));
            btn.setAttribute('aria-pressed', 'true');
            li.classList.add('is-active');
            const colorId = Number(li.dataset.colorId);
            const colorBlock = (data.colors || []).find((c) => Number(c.id) === colorId);
            const variant = colorBlock?.sizes?.find((s) => Number(s.id) === sizeId);
            if (variant) {
                const out = $('[data-product-price]', root);
                if (out) out.textContent = formatEGP(variant.price);
            }
            // The label names the size that will be added.
            updateAtc(root, colorBlock);
        });
    });
}

function init() {
    const root = document.querySelector('[data-product-page]');
    if (!root) return;

    const data = readVariants(root);
    if (!data || !Array.isArray(data.colors) || data.colors.length === 0) return;

    bindColorButtons(root, data);
    bindAddToCart(root, data);

    // Auto-pick the first color that has a size in stock, falling back
    // to the first color block.
    const firstInStock = data.colors.find((c) => Array.isArray(c.sizes) && c.sizes.some((s) => Number(s.stock) > 0))
        || data.colors[0];
    if (firstInStock) {
        pickColor(root, data, firstInStock.id, null);
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
