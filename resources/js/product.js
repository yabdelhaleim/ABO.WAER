// ABO Product — color × size variant selector + add to cart.
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
// On add to cart:
//   - Posts the chosen variant to /cart/items (the server owns the
//     stock and the price), then mirrors the line into the browser
//     cart and refreshes the nav badge.

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

// The variant the shopper currently has selected. Null until a size
// with stock is picked.
let selectedVariant = null;

function readVariants(root) {
    const node = $('[data-product-variants]', root);
    if (!node) return null;

    try {
        return JSON.parse(node.textContent || '{}');
    } catch (e) {
        return null;
    }
}

function formatEGP(value) {
    return `${Number(value).toLocaleString('en-EG')} EGP`;
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

function updateAtc(root, colorBlock, sizeEntry) {
    const label = $('[data-product-atc-label]', root);
    const button = $('[data-product-atc]', root);
    if (!label) return;

    const first = sizeEntry || findFirstInStockSize(colorBlock);
    if (!first) {
        label.textContent = 'OUT OF STOCK';
        selectedVariant = null;
        if (button) {
            button.disabled = true;
            button.setAttribute('aria-disabled', 'true');
        }
        return;
    }

    selectedVariant = {
        variant_id: first.variant_id !== undefined && first.variant_id !== null ? Number(first.variant_id) : null,
        size_id: Number(first.id),
        size_name: first.name,
        price: Number(first.price),
        color_id: colorBlock ? Number(colorBlock.id) : null,
        color_name: colorBlock ? colorBlock.name : null,
        color_hex: colorBlock ? colorBlock.hex : null,
    };

    label.textContent = `ADD TO CART — ${colorBlock?.name?.toUpperCase() ?? ''} / ${first.name}`.trim();
    if (button) {
        button.disabled = false;
        button.removeAttribute('aria-disabled');
    }
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
    const chosen = (colorBlock.sizes || []).find((s) => Number(s.id) === Number(targetSizeId)) || null;
    updateAtc(root, colorBlock, chosen);
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
                updateAtc(root, colorBlock, variant);
            }
        });
    });
}

// ─── Add to cart ───────────────────────────────────────────────────

/**
 * A short confirmation under the button. The page has no toast host of
 * its own, so the message lives next to the button it belongs to.
 */
function showAtcMessage(root, message, tone) {
    const hint = $('[data-product-atc-hint]', root);
    if (!hint) return;
    const existing = hint.querySelector('[data-product-atc-message]');
    if (existing) existing.remove();

    const note = document.createElement('span');
    note.dataset.productAtcMessage = '';
    note.setAttribute('role', tone === 'error' ? 'alert' : 'status');
    note.className = tone === 'error' ? 'product-page__atc-message is-error' : 'product-page__atc-message is-ok';
    note.textContent = message;
    hint.prepend(note);

    if (tone !== 'error') {
        setTimeout(() => note.remove(), 4000);
    }
}

async function onAddToCart(root, data) {
    const button = $('[data-product-atc]', root);
    if (!button || !selectedVariant) return;

    if (selectedVariant.variant_id === null) {
        showAtcMessage(root, 'This option cannot be ordered right now.', 'error');
        return;
    }

    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    const label = $('[data-product-atc-label]', root);
    const previousLabel = label ? label.textContent : '';

    const added = await window.ABOCart.serverAdd(selectedVariant.variant_id, 1);

    button.removeAttribute('aria-busy');
    button.disabled = false;

    if (!added.ok) {
        showAtcMessage(root, added.message, 'error');
        return;
    }

    // Mirror the line into the browser cart so the badge and the cart
    // page show it straight away.
    if (window.ABOCart && typeof window.ABOCart.addLine === 'function') {
        window.ABOCart.addLine({
            slug: data.slug,
            color: selectedVariant.color_hex,
            size: selectedVariant.size_name,
            qty: 1,
            variant_id: selectedVariant.variant_id,
            server_id: added.itemId,
            name: data.name || null,
        });
        window.ABOCart.render();
    }

    if (label) label.textContent = 'ADDED ✓';
    showAtcMessage(root, 'Added to your cart.', 'ok');
    setTimeout(() => {
        if (label) label.textContent = previousLabel;
    }, 2000);
}

function bindAddToCart(root, data) {
    const button = $('[data-product-atc]', root);
    if (!button) return;
    button.addEventListener('click', () => {
        onAddToCart(root, data);
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
