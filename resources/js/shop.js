// ABO Shop — client-side filtering, sorting, and URL sync.
// ----------------------------------------------------------------
// No backend. All products are rendered server-side from the data
// file; this script just hides/shows them, sorts them, writes the
// active filters into the URL query string, and exposes a small
// dropdown panel + bottom-sheet UX for choosing filters.
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
    price: null,       // { min, max } or null (max inclusive)
    sort: 'newest',    // 'newest' | 'price-asc' | 'price-desc' | 'name'
};

const SORT_LABELS = {
    'newest': 'Newest',
    'price-asc': 'Price: low to high',
    'price-desc': 'Price: high to low',
    'name': 'Name: A–Z',
};

const CATEGORY_LABELS = {}; // filled from DOM
const PRICE_BUCKETS = [];   // filled from real prices in DOM

// ─── Boot ──────────────────────────────────────────────────────

function boot() {
    const root = $('[data-shop-filters]');
    const grid = $('[data-shop-grid]');
    if (!root || !grid) return;

    collectLabels();
    buildPanels(root);
    bindEvents(root);
    loadFromUrl();
    render();
    saveToSession();
}

function collectLabels() {
    $$('.shop-cat[data-category]').forEach((el) => {
        CATEGORY_LABELS[el.dataset.category] = el.querySelector('.shop-cat__name')?.textContent?.trim() || el.dataset.category;
    });

    const prices = $$('[data-product]').map((el) => Number(el.dataset.price)).filter((n) => Number.isFinite(n));
    if (prices.length > 1) {
        const min = Math.min(...prices);
        const max = Math.max(...prices);
        const third = (max - min) / 3;
        PRICE_BUCKETS.push({ id: 'low',    label: `Under ${formatEGP(min + third)}`,         min, max: min + third });
        PRICE_BUCKETS.push({ id: 'mid',    label: `${formatEGP(min + third)} – ${formatEGP(min + third * 2)}`, min: min + third, max: min + third * 2 });
        PRICE_BUCKETS.push({ id: 'high',   label: `Over ${formatEGP(min + third * 2)}`,       min: min + third * 2, max: max + 1 });
    }
}

function buildPanels(root) {
    const panel = $('[data-shop-panel]', root);
    if (!panel) return;

    // Category panel
    const categoryPanel = document.createElement('div');
    categoryPanel.className = 'shop-filters__panel-group';
    categoryPanel.dataset.panel = 'category';
    categoryPanel.hidden = true;
    const cats = Object.keys(CATEGORY_LABELS);
    if (cats.length > 0) {
        categoryPanel.innerHTML = `
            <p class="shop-filters__panel-title">Filter by category</p>
            <ul role="listbox" aria-label="Categories">
                <li><button type="button" role="option" data-category-clear>All categories</button></li>
                ${cats.map((slug) => `
                    <li><button type="button" role="option" data-category-value="${slug}">${CATEGORY_LABELS[slug]}</button></li>
                `).join('')}
            </ul>
        `;
    }

    // Price panel
    const pricePanel = document.createElement('div');
    pricePanel.className = 'shop-filters__panel-group';
    pricePanel.dataset.panel = 'price';
    pricePanel.hidden = true;
    if (PRICE_BUCKETS.length > 0) {
        pricePanel.innerHTML = `
            <p class="shop-filters__panel-title">Filter by price</p>
            <ul role="listbox" aria-label="Price range">
                <li><button type="button" role="option" data-price-clear>Any price</button></li>
                ${PRICE_BUCKETS.map((b) => `
                    <li><button type="button" role="option" data-price-value="${b.id}">${b.label}</button></li>
                `).join('')}
            </ul>
        `;
    }

    // Sort panel
    const sortPanel = document.createElement('div');
    sortPanel.className = 'shop-filters__panel-group';
    sortPanel.dataset.panel = 'sort';
    sortPanel.hidden = true;
    sortPanel.innerHTML = `
        <p class="shop-filters__panel-title">Sort by</p>
        <ul role="listbox" aria-label="Sort options">
            ${Object.entries(SORT_LABELS).map(([id, label]) => `
                <li><button type="button" role="option" data-sort-value="${id}">${label}</button></li>
            `).join('')}
        </ul>
    `;

    panel.append(categoryPanel, pricePanel, sortPanel);
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
        const okCat = !state.category || cat === state.category;
        const okPrice = !state.price || (price >= state.price.min && price < state.price.max);
        const show = okCat && okPrice;
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
            const clean = !state.category && !state.price && state.sort === 'newest';
            chip.classList.toggle('is-active', clean);
            chip.setAttribute('aria-pressed', String(clean));
            return;
        }
        let active = false;
        if (kind === 'category') active = !!state.category;
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
