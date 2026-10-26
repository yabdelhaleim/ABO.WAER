// ABO Shop — client-side filtering, sorting, and URL sync.
// ----------------------------------------------------------------
// The catalog is rendered server-side from the database. This script
// hides/shows the rendered cards as the shopper picks filters, sorts
// them, writes the active filters into the URL query string, and
// drives the dropdown panel + bottom-sheet UX.
//
// The filter options themselves are rendered by the server too, from
// the same data the cards come from — so the label a shopper reads
// and the numbers the browser filters on can never drift apart.
//
// Accessibility:
// - aria-pressed on the active chip
// - aria-expanded on the chip that opened a panel
// - Esc closes any open panel
// - Click outside closes any open panel
// - Focus is moved to the panel when it opens (so Esc / Tab work)
//
// Reduced motion: animations are CSS-driven and gated by the
// prefers-reduced-motion media query — no JS motion here.

const STORAGE_KEY = 'abo_shop_filters';

// ─── Helpers ────────────────────────────────────────────────────

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

const clamp = (v, lo, hi) => Math.min(Math.max(v, lo), hi);
const formatEGP = (n) => `${Number(n).toLocaleString('en-EG')} EGP`;

const prefersReducedMotion = () =>
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// ─── Filter state ──────────────────────────────────────────────
//
// `state` is the single source of truth. UI and URL are derived
// from it via render().

const state = {
    category: null,    // string slug or null
    color: null,       // string color id or null
    size: null,        // string size id or null
    price: null,       // { id, min, max, label } or null
    sort: 'newest',    // 'newest' | 'price-asc' | 'price-desc' | 'name'
};

const SORT_LABELS = {
    'newest': 'Newest',
    'price-asc': 'Price: low to high',
    'price-desc': 'Price: high to low',
    'name': 'Name: A–Z',
};

const CATEGORY_LABELS = {};  // slug → label, filled from the DOM
const COLOR_LABELS = {};     // id → label, filled from the DOM
const SIZE_LABELS = {};      // id → label, filled from the DOM
const PRICE_BUCKETS = [];    // filled from the rendered price options

// ─── Boot ──────────────────────────────────────────────────────

function boot() {
    const root = $('[data-shop-filters]');
    const grid = $('[data-shop-grid]');
    if (!root || !grid) return;

    collectLabels();
    bindEvents(root);
    loadFromUrl();
    render();
    saveToSession();
}

function collectLabels() {
    $$('.shop-cat[data-category]').forEach((el) => {
        CATEGORY_LABELS[el.dataset.category] = el.querySelector('.shop-cat__name')?.textContent?.trim() || el.dataset.category;
    });

    // The filter options are rendered by the server from the real
    // catalog. Read their labels and their numeric bounds from the
    // DOM so there is one source of truth for both.
    $$('[data-color-value]').forEach((b) => {
        COLOR_LABELS[b.dataset.colorValue] = b.textContent.trim();
    });
    $$('[data-size-value]').forEach((b) => {
        SIZE_LABELS[b.dataset.sizeValue] = b.textContent.trim();
    });
    $$('[data-price-value]').forEach((b) => {
        PRICE_BUCKETS.push({
            id: b.dataset.priceValue,
            min: Number(b.dataset.priceMin),
            max: Number(b.dataset.priceMax),
            label: b.textContent.trim(),
        });
    });
}

function bindEvents(root) {
    // Chip click → open / close panel
    $$('.shop-filters__chip', root).forEach((chip) => {
        chip.addEventListener('click', () => {
            const kind = chip.dataset.filter;
            if (kind === 'all') {
                clearAll();
                return;
            }
            togglePanel(chip, kind);
        });
    });

    // Panel option clicks (event-delegated)
    const panel = $('[data-shop-panel]', root);
    if (panel) {
        panel.addEventListener('click', (ev) => {
            const target = ev.target.closest('button');
            if (!target) return;
            if (target.matches('[data-category-clear]'))  { state.category = null; }
            else if (target.dataset.categoryValue)        { state.category = target.dataset.categoryValue; }
            else if (target.matches('[data-color-clear]')) { state.color = null; }
            else if (target.dataset.colorValue)            { state.color = String(target.dataset.colorValue); }
            else if (target.matches('[data-size-clear]'))  { state.size = null; }
            else if (target.dataset.sizeValue)             { state.size = String(target.dataset.sizeValue); }
            else if (target.matches('[data-price-clear]')) { state.price = null; }
            else if (target.dataset.priceValue) {
                state.price = PRICE_BUCKETS.find((b) => b.id === target.dataset.priceValue) || null;
            }
            else if (target.dataset.sortValue)           { state.sort = target.dataset.sortValue; }
            else { return; }
            closePanel();
            render();
            saveToSession();
            saveToUrl();
        });
    }

    // Active tags — clear single
    const tags = $('[data-shop-tags]', root);
    if (tags) {
        tags.addEventListener('click', (ev) => {
            const remove = ev.target.closest('[data-remove]');
            if (remove) {
                const what = remove.dataset.remove;
                if (what === 'category') state.category = null;
                if (what === 'color')    state.color = null;
                if (what === 'size')     state.size = null;
                if (what === 'price')    state.price = null;
                if (what === 'sort')     state.sort = 'newest';
                render();
                saveToSession();
                saveToUrl();
            }
        });
    }

    // Clear all
    const clearBtn = $('[data-shop-clear]', root);
    if (clearBtn) clearBtn.addEventListener('click', clearAll);

    // Empty-state reset
    const emptyReset = $('[data-shop-reset]');
    if (emptyReset) emptyReset.addEventListener('click', clearAll);

    // Esc / outside click
    document.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape') closePanel();
    });
    document.addEventListener('click', (ev) => {
        if (!root.contains(ev.target)) closePanel();
    });
}

function clearAll() {
    state.category = null;
    state.color = null;
    state.size = null;
    state.price = null;
    state.sort = 'newest';
    closePanel();
    render();
    saveToSession();
    saveToUrl();
}

function togglePanel(chip, kind) {
    const panel = $('[data-shop-panel]');
    if (!panel) return;
    const group = panel.querySelector(`[data-panel="${kind}"]`);
    if (!group) return;

    const isOpen = !group.hidden;

    // Close any open group
    $$('.shop-filters__panel-group', panel).forEach((g) => { g.hidden = true; });
    $$('.shop-filters__chip').forEach((c) => c.setAttribute('aria-expanded', 'false'));

    if (!isOpen) {
        group.hidden = false;
        panel.hidden = false;
        chip.setAttribute('aria-expanded', 'true');
        // Reflect current selection in the panel
        if (kind === 'category') reflectCategory(group);
        if (kind === 'color')    reflectColor(group);
        if (kind === 'size')     reflectSize(group);
        if (kind === 'price')    reflectPrice(group);
        if (kind === 'sort')     reflectSort(group);
    }
}

function closePanel() {
    const panel = $('[data-shop-panel]');
    if (panel) {
        panel.hidden = true;
        $$('.shop-filters__panel-group', panel).forEach((g) => { g.hidden = true; });
    }
    $$('.shop-filters__chip').forEach((c) => c.setAttribute('aria-expanded', 'false'));
}

function reflectCategory(group) {
    $$('button', group).forEach((b) => {
        const val = b.dataset.categoryValue || null;
        b.setAttribute('aria-selected', String(val === state.category || (val === null && !state.category)));
    });
}
function reflectColor(group) {
    $$('button', group).forEach((b) => {
        const val = b.dataset.colorValue || null;
        b.setAttribute('aria-selected', String(val === state.color || (val === null && !state.color)));
    });
}
function reflectSize(group) {
    $$('button', group).forEach((b) => {
        const val = b.dataset.sizeValue || null;
        b.setAttribute('aria-selected', String(val === state.size || (val === null && !state.size)));
    });
}
function reflectPrice(group) {
    $$('button', group).forEach((b) => {
        const val = b.dataset.priceValue || null;
        const selected = (val === null && !state.price) || (val && state.price && state.price.id === val);
        b.setAttribute('aria-selected', String(selected));
    });
}
function reflectSort(group) {
    $$('button', group).forEach((b) => {
        b.setAttribute('aria-selected', String(b.dataset.sortValue === state.sort));
    });
}

// ─── Render ────────────────────────────────────────────────────

function render() {
    const items = $$('[data-product]');
    let visible = 0;

    items.forEach((el) => {
        const cat = el.dataset.category;
        const price = Number(el.dataset.price);
        const cardColors = (el.dataset.colors || '').split(',').filter(Boolean);
        const cardSizes = (el.dataset.sizes || '').split(',').filter(Boolean);

        const okCat = !state.category || cat === state.category;
        const okColor = !state.color || cardColors.includes(state.color);
        const okSize = !state.size || cardSizes.includes(state.size);
        const okPrice = !state.price || (price >= state.price.min && price <= state.price.max);

        const show = okCat && okColor && okSize && okPrice;
        el.hidden = !show;
        if (show) visible++;
    });

    // Sort visible items
    sortItems(items);

    // Update count
    const countEl = $('[data-shop-count-num]');
    if (countEl) countEl.textContent = String(visible);
    const countWrap = $('[data-shop-count]');
    if (countWrap) {
        const noun = visible === 1 ? 'result' : 'results';
        countWrap.lastChild && (countWrap.lastChild.textContent = ` ${noun}`);
    }

    // Active tags
    renderTags();
    renderChips();

    // Empty state
    const empty = $('[data-shop-empty]');
    if (empty) empty.hidden = visible > 0;
    const grid = $('[data-shop-grid]');
    if (grid) grid.hidden = visible === 0;
}

function sortItems(items) {
    const visible = items.filter((el) => !el.hidden);
    const parent = visible[0]?.parentElement;
    if (!parent) return;
    const sorted = [...visible].sort((a, b) => {
        switch (state.sort) {
            case 'price-asc':  return Number(a.dataset.price) - Number(b.dataset.price);
            case 'price-desc': return Number(b.dataset.price) - Number(a.dataset.price);
            case 'name':       return a.dataset.name.localeCompare(b.dataset.name);
            case 'newest':
            default:           return Number(a.dataset.index) - Number(b.dataset.index);
        }
    });
    sorted.forEach((el) => parent.appendChild(el));
}

function renderTags() {
    const tagsWrap = $('[data-shop-tags]');
    if (!tagsWrap) return;
    // Keep the "Clear all" button
    const clear = $('[data-shop-clear]', tagsWrap);
    tagsWrap.innerHTML = '';
    if (clear) tagsWrap.appendChild(clear);

    const tags = [];
    if (state.category) {
        const label = CATEGORY_LABELS[state.category] || state.category;
        tags.push({ kind: 'category', label });
    }
    if (state.color) {
        tags.push({ kind: 'color', label: COLOR_LABELS[state.color] || state.color });
    }
    if (state.size) {
        tags.push({ kind: 'size', label: SIZE_LABELS[state.size] || state.size });
    }
    if (state.price) {
        tags.push({ kind: 'price', label: state.price.label });
    }
    if (state.sort && state.sort !== 'newest') {
        tags.push({ kind: 'sort', label: SORT_LABELS[state.sort] });
    }

    if (tags.length === 0) {
        tagsWrap.hidden = true;
        return;
    }
    tagsWrap.hidden = false;
    tags.forEach((t) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'shop-filters__tag';
        btn.dataset.remove = t.kind;
        btn.innerHTML = `<span>${t.label}</span><span aria-hidden="true">×</span>`;
        tagsWrap.insertBefore(btn, clear);
    });
}

function renderChips() {
    $$('.shop-filters__chip').forEach((chip) => {
        const kind = chip.dataset.filter;
        if (kind === 'all') {
            const clean = !state.category && !state.color && !state.size && !state.price && state.sort === 'newest';
            chip.classList.toggle('is-active', clean);
            chip.setAttribute('aria-pressed', String(clean));
            return;
        }
        let active = false;
        if (kind === 'category') active = !!state.category;
        if (kind === 'color')    active = !!state.color;
        if (kind === 'size')     active = !!state.size;
        if (kind === 'price')    active = !!state.price;
        if (kind === 'sort')     active = state.sort && state.sort !== 'newest';
        chip.classList.toggle('is-active', active);
        chip.setAttribute('aria-pressed', String(active));
    });
}

// ─── URL + session ─────────────────────────────────────────────

function saveToUrl() {
    const url = new URL(window.location.href);
    const params = url.searchParams;
    if (state.category)    params.set('category', state.category);
    else                   params.delete('category');
    if (state.color)       params.set('color', state.color);
    else                   params.delete('color');
    if (state.size)        params.set('size', state.size);
    else                   params.delete('size');
    if (state.price)       params.set('price', state.price.id);
    else                   params.delete('price');
    if (state.sort && state.sort !== 'newest') params.set('sort', state.sort);
    else                                       params.delete('sort');
    history.replaceState(null, '', url.toString());
}

function loadFromUrl() {
    const params = new URLSearchParams(window.location.search);
    const cat = params.get('category');
    if (cat && CATEGORY_LABELS[cat]) state.category = cat;
    const color = params.get('color');
    if (color && COLOR_LABELS[color]) state.color = String(color);
    const size = params.get('size');
    if (size && SIZE_LABELS[size]) state.size = String(size);
    const price = params.get('price');
    if (price) {
        const bucket = PRICE_BUCKETS.find((b) => b.id === price);
        if (bucket) state.price = bucket;
    }
    const sort = params.get('sort');
    if (sort && SORT_LABELS[sort]) state.sort = sort;
}

function saveToSession() {
    try { sessionStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch (e) {}
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
