/*
 * ABO Admin · Products — page-local script.
 *
 * Self-gating: each init function checks for the section element
 * and exits silently if not present. This lets the same file
 * serve both the list page and the form page (and stay loaded
 * without errors on other admin pages that import it).
 *
 * NO network calls. NO localStorage. NO IndexedDB. Every piece of
 * data is either hard-coded in PRODUCTS / COLORS / SIZES below or
 * read from the DOM. Each section that would need real data is
 * marked TODO(backend).
 *
 * Sections:
 *   initTodayDate        — set the header date (matches dashboard)
 *   initList             — list page (filters, table, bulk, pagination,
 *                          modal, toast, stat cards)
 *   initForm             — form page (basic, images, variants, pricing,
 *                          organise, preview, modal, toast)
 */

/* ──────────────────────────────────────────────────────────────
   Static data
   TODO(backend): replace with real data fetched from the API.
   The shape is final; only the source changes.
   ────────────────────────────────────────────────────────────── */

const COLORS = {
    black: { label: 'Black', hex: '#0D0F11' },
    grey:  { label: 'Grey',  hex: '#8D928A' },
    olive: { label: 'Olive', hex: '#5C6A48' },
    lime:  { label: 'Lime',  hex: '#C6FF00' },
    coral: { label: 'Coral', hex: '#FF7A5C' },
    sand:  { label: 'Sand',  hex: '#C9A227' },
    bone:  { label: 'Bone',  hex: '#E8E2D2' },
};

const SIZES = ['S', 'M', 'L', 'XL', 'XXL'];

const CATEGORIES = {
    hoodies:  'Hoodies',
    't-shirts': 'T-Shirts',
    pants:    'Pants',
    jackets:  'Jackets',
};

// 14 sample products — at least 12 per spec. Mix of statuses and
// stock levels (in / low / out) so every UI state has coverage.
const PRODUCTS = [
    {
        id: 'p-001', name: 'Oversize hoodie', sku: 'ABO-HD-001',
        category: 'hoodies', price: 1890, compare: 2200, cost: 760,
        status: 'active', stock: 42, low: 10,
        colors: ['black', 'grey', 'olive', 'lime'],
        sizes: ['S', 'M', 'L', 'XL', 'XXL'],
    },
    {
        id: 'p-002', name: 'Cargo pants', sku: 'ABO-PT-002',
        category: 'pants', price: 1450, compare: null, cost: 580,
        status: 'active', stock: 7, low: 10,
        colors: ['olive', 'black', 'sand'],
        sizes: ['S', 'M', 'L', 'XL'],
    },
    {
        id: 'p-003', name: 'Heavy tee', sku: 'ABO-TS-003',
        category: 't-shirts', price: 690, compare: 850, cost: 220,
        status: 'active', stock: 4, low: 10,
        colors: ['black', 'bone', 'lime'],
        sizes: ['S', 'M', 'L', 'XL', 'XXL'],
    },
    {
        id: 'p-004', name: 'Zip jacket', sku: 'ABO-JK-004',
        category: 'jackets', price: 2490, compare: null, cost: 940,
        status: 'active', stock: 18, low: 5,
        colors: ['black', 'olive'],
        sizes: ['M', 'L', 'XL'],
    },
    {
        id: 'p-005', name: 'Basic tee — Black', sku: 'ABO-TS-005',
        category: 't-shirts', price: 590, compare: null, cost: 180,
        status: 'active', stock: 0, low: 10,
        colors: ['black', 'bone'],
        sizes: ['S', 'M', 'L', 'XL', 'XXL'],
    },
    {
        id: 'p-006', name: 'Drop 02 hoodie', sku: 'ABO-HD-006',
        category: 'hoodies', price: 1990, compare: null, cost: 800,
        status: 'draft', stock: 0, low: 10,
        colors: ['black', 'lime'],
        sizes: ['S', 'M', 'L', 'XL'],
    },
    {
        id: 'p-007', name: 'Urban tee — Olive', sku: 'ABO-TS-007',
        category: 't-shirts', price: 690, compare: 850, cost: 220,
        status: 'active', stock: 22, low: 10,
        colors: ['olive', 'black'],
        sizes: ['S', 'M', 'L', 'XL', 'XXL'],
    },
    {
        id: 'p-008', name: 'Limo bomber — Lime', sku: 'ABO-JK-008',
        category: 'jackets', price: 2790, compare: null, cost: 1080,
        status: 'active', stock: 3, low: 5,
        colors: ['lime', 'black'],
        sizes: ['M', 'L', 'XL'],
    },
    {
        id: 'p-009', name: 'Linen overshirt', sku: 'ABO-JK-009',
        category: 'jackets', price: 1690, compare: 1990, cost: 670,
        status: 'active', stock: 12, low: 5,
        colors: ['sand', 'bone'],
        sizes: ['S', 'M', 'L', 'XL'],
    },
    {
        id: 'p-010', name: 'Wide-leg pants', sku: 'ABO-PT-010',
        category: 'pants', price: 1390, compare: null, cost: 540,
        status: 'active', stock: 16, low: 8,
        colors: ['black', 'grey', 'sand'],
        sizes: ['S', 'M', 'L', 'XL'],
    },
    {
        id: 'p-011', name: 'Crop hoodie', sku: 'ABO-HD-011',
        category: 'hoodies', price: 1490, compare: null, cost: 600,
        status: 'draft', stock: 0, low: 5,
        colors: ['black', 'coral'],
        sizes: ['S', 'M', 'L'],
    },
    {
        id: 'p-012', name: 'Track jacket', sku: 'ABO-JK-012',
        category: 'jackets', price: 1890, compare: 2290, cost: 720,
        status: 'active', stock: 9, low: 6,
        colors: ['olive', 'black'],
        sizes: ['S', 'M', 'L', 'XL'],
    },
    {
        id: 'p-013', name: 'Pocket tee', sku: 'ABO-TS-013',
        category: 't-shirts', price: 590, compare: null, cost: 190,
        status: 'active', stock: 31, low: 10,
        colors: ['bone', 'black', 'olive'],
        sizes: ['S', 'M', 'L', 'XL', 'XXL'],
    },
    {
        id: 'p-014', name: 'Sweat shorts', sku: 'ABO-PT-014',
        category: 'pants', price: 790, compare: null, cost: 280,
        status: 'active', stock: 6, low: 8,
        colors: ['grey', 'black', 'olive'],
        sizes: ['S', 'M', 'L', 'XL'],
    },
];

/* ──────────────────────────────────────────────────────────────
   Tiny helpers
   ────────────────────────────────────────────────────────────── */

var $  = window.$  || ((sel, root = document) => root.querySelector(sel));
var $$ = window.$$ || ((sel, root = document) => Array.from(root.querySelectorAll(sel)));

function el(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
        if (v == null || v === false) continue;
        if (k === 'class') node.className = v;
        else if (k === 'text') { node.textContent = v; continue; }
        else if (k === 'html') { node.innerHTML = v; continue; }
        else if (k === 'dataset' && typeof v === 'object') {
            for (const [dk, dv] of Object.entries(v)) {
                if (dv != null) node.dataset[dk] = String(dv);
            }
            continue;
        }
        else if (k.startsWith('aria-') || k === 'role' || k === 'title' ||
                 k === 'hidden' || k === 'required' || k === 'disabled' ||
                 k === 'checked' || k === 'selected' || k === 'placeholder' ||
                 k === 'min' || k === 'max' || k === 'step' || k === 'rows' ||
                 k === 'type' || k === 'name' || k === 'value' || k === 'for' ||
                 k === 'autocomplete' || k === 'inputmode') {
            node.setAttribute(k, v === true ? '' : String(v));
        }
        else if (k in node) {
            try { node[k] = v; } catch { node.setAttribute(k, String(v)); }
        }
        else node.setAttribute(k, String(v));
    }
    for (const c of [].concat(children)) {
        if (c == null) continue;
        node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    }
    return node;
}

function fmtEGP(n) {
    if (n == null || n === '' || isNaN(+n)) return '—';
    return Number(n).toLocaleString('en-EG') + ' EGP';
}

function fmtNumber(n) {
    if (n == null || n === '' || isNaN(+n)) return '—';
    return Number(n).toLocaleString('en-EG');
}

function debounce(fn, ms = 200) {
    let t;
    return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
}

function escapeHTML(s) {
    return String(s ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/* ──────────────────────────────────────────────────────────────
   Today date (matches the dashboard pattern in admin.js)
   ────────────────────────────────────────────────────────────── */

function initTodayDate() {
    const node = document.getElementById('abo-admin-today-date');
    if (!node) return;
    const now = new Date();
    const opts = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
    node.textContent = now.toLocaleDateString('en-GB', opts);
}

/* ──────────────────────────────────────────────────────────────
   Toast
   ────────────────────────────────────────────────────────────── */

let toastTimer = null;
function showToast(message, root) {
    const toast = $('[data-abo-admin-products-toast]', root || document);
    if (!toast) return;
    const text = $('[data-abo-admin-products-toast-text]', toast);
    if (text) text.textContent = message;
    toast.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toast.hidden = true; }, 2200);
}

/* ──────────────────────────────────────────────────────────────
   Modal
   ────────────────────────────────────────────────────────────── */

let modalConfirmHandler = null;

function openModal({ title, body, confirmLabel = 'Confirm', danger = true, onConfirm }, root) {
    const modal = $('[data-abo-admin-products-modal]', root || document);
    if (!modal) return;
    const titleNode = $('#abo-admin-products-modal-title', modal);
    const bodyNode  = $('[data-abo-admin-products-modal-body]', modal);
    const confirm   = $('[data-abo-admin-products-modal-confirm]', modal);
    if (titleNode) titleNode.textContent = title;
    if (bodyNode)  bodyNode.textContent  = body;
    if (confirm) {
        confirm.textContent = confirmLabel;
        confirm.classList.toggle('abo-admin-products__modal-confirm--safe', !danger);
    }
    modal.hidden = false;
    modalConfirmHandler = onConfirm || null;
    // Focus the cancel button by default — safer than focusing the destructive action.
    setTimeout(() => {
        const cancel = $('[data-abo-admin-products-modal-close]', modal);
        if (cancel) cancel.focus();
    }, 0);
}

function closeModal(root) {
    const modal = $('[data-abo-admin-products-modal]', root || document);
    if (!modal) return;
    modal.hidden = true;
    modalConfirmHandler = null;
}

function initModal(root) {
    const modal = $('[data-abo-admin-products-modal]', root || document);
    if (!modal || modal.dataset.bound === '1') return;
    modal.dataset.bound = '1';

    $$('[data-abo-admin-products-modal-close]', modal).forEach(btn => {
        btn.addEventListener('click', () => closeModal(root));
    });
    $('[data-abo-admin-products-modal-confirm]', modal)
        ?.addEventListener('click', () => {
            const handler = modalConfirmHandler;
            closeModal(root);
            if (handler) handler();
        });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !modal.hidden) closeModal(root);
    });
}

/* ══════════════════════════════════════════════════════════════
   LIST PAGE
   ══════════════════════════════════════════════════════════════ */

const STATE = {
    products: [...PRODUCTS],
    filter: { q: '', category: '', status: '', stock: '' },
    sort: 'newest',
    selection: new Set(),
    page: 1,
    perPage: 10,
    stat: 'all',
};

function stockState(p) {
    if (p.status === 'draft') return 'draft';
    if (p.stock === 0) return 'out';
    if (p.stock < p.low) return 'low';
    return 'in';
}

function stockPill(p) {
    const state = stockState(p);
    if (state === 'draft') {
        return `<span class="abo-admin-products__stock-pill abo-admin-products__stock-pill--draft">Draft</span>`;
    }
    if (state === 'out') {
        return `<span class="abo-admin-products__stock-pill abo-admin-products__stock-pill--out">Out</span>`;
    }
    if (state === 'low') {
        return `<span class="abo-admin-products__stock-pill abo-admin-products__stock-pill--low">Low · ${p.stock}</span>`;
    }
    return `<span class="abo-admin-products__stock-pill abo-admin-products__stock-pill--in">In stock · ${p.stock}</span>`;
}

function matchesFilters(p) {
    const { q, category, status, stock } = STATE.filter;
    if (q) {
        const needle = q.toLowerCase();
        if (!p.name.toLowerCase().includes(needle) &&
            !p.sku.toLowerCase().includes(needle)) return false;
    }
    if (category && p.category !== category) return false;
    if (status && p.status !== status) return false;
    if (stock) {
        const s = stockState(p);
        if (stock === 'in'  && s !== 'in')  return false;
        if (stock === 'low' && s !== 'low') return false;
        if (stock === 'out' && s !== 'out') return false;
    }
    return true;
}

function sortProducts(arr) {
    const out = arr.slice();
    switch (STATE.sort) {
        case 'price-asc':  out.sort((a, b) => a.price - b.price); break;
        case 'price-desc': out.sort((a, b) => b.price - a.price); break;
        case 'stock-asc':  out.sort((a, b) => a.stock - b.stock); break;
        case 'newest':
        default:           out.sort((a, b) => (a.id < b.id ? 1 : -1));
    }
    return out;
}

function filteredSorted() {
    let arr = STATE.products.filter(matchesFilters);
    if (STATE.stat === 'active') arr = arr.filter(p => p.status === 'active');
    if (STATE.stat === 'draft')  arr = arr.filter(p => p.status === 'draft');
    if (STATE.stat === 'low')    arr = arr.filter(p => {
        const s = stockState(p);
        return s === 'low' || s === 'out';
    });
    return sortProducts(arr);
}

function updateStatCards() {
    const root = document;
    const total     = STATE.products.length;
    const active    = STATE.products.filter(p => p.status === 'active').length;
    const draft     = STATE.products.filter(p => p.status === 'draft').length;
    const lowOut    = STATE.products.filter(p => {
        const s = stockState(p);
        return s === 'low' || s === 'out';
    }).length;

    const set = (key, val) => {
        const node = $(`[data-abo-admin-products-stat-count="${key}"]`, root);
        if (node) node.textContent = val;
    };
    set('all', total);
    set('active', active);
    set('draft', draft);
    set('low', lowOut);

    // Visual pressed state on the active filter card.
    $$('[data-abo-admin-products-stat]', root).forEach(btn => {
        btn.setAttribute('aria-pressed', btn.dataset.aboAdminProductsStat === STATE.stat ? 'true' : 'false');
    });
}

function productRow(p) {
    const tr = el('tr', { dataset: { id: p.id } });

    const checkboxTd = el('td', { class: 'abo-admin-products__check-cell' }, [
        el('input', {
            type: 'checkbox', 'aria-label': `Select ${p.name}`,
            dataset: { aboAdminProductsCheck: p.id },
            checked: STATE.selection.has(p.id),
        })
    ]);

    const productTd = el('td');
    productTd.appendChild(el('div', { class: 'abo-admin-products__product' }, [
        el('span', { class: 'abo-admin-products__product-thumb', 'aria-hidden': 'true' }, 'IMG'),
        el('div', { class: 'abo-admin-products__product-text' }, [
            el('p', { class: 'abo-admin-products__product-name', text: p.name }),
            el('p', { class: 'abo-admin-products__product-sku',  text: p.sku }),
        ]),
    ]));

    const categoryTd = el('td', { text: CATEGORIES[p.category] || p.category });

    const priceTd = el('td');
    priceTd.appendChild(el('span', { class: 'abo-admin-products__price-now', text: fmtEGP(p.price) }));
    if (p.compare && p.compare > p.price) {
        priceTd.appendChild(el('span', { class: 'abo-admin-products__price-was', text: fmtEGP(p.compare) }));
    }

    const variantsTd = el('td');
    const variantsInner = el('div', { class: 'abo-admin-products__variants-cell' });
    p.colors.slice(0, 4).forEach(c => {
        variantsInner.appendChild(el('span', {
            class: 'abo-admin-products__variant-dot',
            style: `--swatch: ${COLORS[c]?.hex || '#8D928A'}`,
            title: COLORS[c]?.label || c,
        }));
    });
    if (p.colors.length > 4) {
        variantsInner.appendChild(el('span', { class: 'abo-admin-products__variant-count', text: `+${p.colors.length - 4}` }));
    }
    variantsTd.appendChild(variantsInner);

    const stockTd = el('td');
    stockTd.innerHTML = stockPill(p);

    const activeTd = el('td', { class: 'abo-admin-products__th-active' });
    const toggle = el('label', { class: 'abo-admin-products__toggle' });
    const input = el('input', {
        type: 'checkbox',
        checked: p.status === 'active',
        'aria-label': `Toggle ${p.name} active`,
        dataset: { aboAdminProductsToggle: p.id },
    });
    toggle.appendChild(input);
    toggle.appendChild(el('span', { class: 'abo-admin-products__toggle-track' }));
    toggle.appendChild(el('span', { class: 'abo-admin-products__toggle-thumb' }));
    activeTd.appendChild(toggle);

    const actionsTd = el('td', { class: 'abo-admin-products__row-actions' });
    const menuBtn = el('button', {
        type: 'button',
        class: 'abo-admin-products__row-menu-btn',
        'aria-label': `Row actions for ${p.name}`,
        'aria-haspopup': 'true',
        'aria-expanded': 'false',
        dataset: { aboAdminProductsRowMenu: p.id },
    }, '⋯');
    actionsTd.appendChild(menuBtn);

    tr.append(checkboxTd, productTd, categoryTd, priceTd, variantsTd, stockTd, activeTd, actionsTd);
    return tr;
}

function renderTable() {
    const tbody = $('[data-abo-admin-products-tbody]');
    if (!tbody) return;

    const rows = filteredSorted();
    const total = rows.length;

    // Pagination slice.
    const start = (STATE.page - 1) * STATE.perPage;
    const slice = rows.slice(start, start + STATE.perPage);

    tbody.replaceChildren(...slice.map(productRow));

    // States: empty / no-results / loading / data.
    const empty      = $('[data-abo-admin-products-state="empty"]');
    const noresults  = $('[data-abo-admin-products-state="noresults"]');
    const tableCard  = tbody.closest('.abo-admin-card');

    if (empty)     empty.hidden = true;
    if (noresults) noresults.hidden = true;

    if (STATE.products.length === 0) {
        if (empty)     empty.hidden = false;
        if (tableCard) tableCard.style.display = 'none';
    } else if (total === 0) {
        if (noresults) noresults.hidden = false;
        if (tableCard) tableCard.style.display = '';
    } else {
        if (tableCard) tableCard.style.display = '';
    }

    renderPagination(total);
    updateBulkBar();
    syncHeaderCheckbox();
}

function renderPagination(total) {
    const list = $('.abo-admin-products__pagination-list');
    const info = $('[data-abo-admin-products-pagination-info]');
    if (!list || !info) return;

    const pages = Math.max(1, Math.ceil(total / STATE.perPage));
    if (STATE.page > pages) STATE.page = pages;

    const start = total === 0 ? 0 : (STATE.page - 1) * STATE.perPage + 1;
    const end   = total === 0 ? 0 : Math.min(STATE.page * STATE.perPage, total);
    info.textContent = `${start} to ${end} of ${total}`;

    list.replaceChildren();

    const make = (label, page, opts = {}) => {
        const b = el('button', {
            type: 'button',
            class: 'abo-admin-products__page-btn' + (opts.active ? ' is-active' : ''),
            text: label,
            'aria-label': opts.ariaLabel || label,
            'aria-current': opts.active ? 'page' : 'false',
        });
        if (opts.disabled) b.disabled = true;
        b.addEventListener('click', () => {
            STATE.page = page;
            renderTable();
        });
        return el('li', {}, b);
    };

    list.appendChild(make('‹', Math.max(1, STATE.page - 1),
        { disabled: STATE.page === 1, ariaLabel: 'Previous page' }));

    // Compact page list: 1, …, current-1, current, current+1, …, last
    const pages2 = [];
    const add = (p) => pages2.push(p);
    add(1);
    if (STATE.page > 3) add('…');
    for (let p = STATE.page - 1; p <= STATE.page + 1; p++) {
        if (p > 1 && p < pages) add(p);
    }
    if (STATE.page < pages - 2) add('…');
    if (pages > 1) add(pages);

    const seen = new Set();
    pages2.forEach(p => {
        if (typeof p === 'number') {
            if (seen.has(p)) return;
            seen.add(p);
            list.appendChild(make(String(p), p, { active: p === STATE.page }));
        } else {
            list.appendChild(el('li', { class: 'abo-admin-products__pagination-ellipsis' }, p));
        }
    });

    list.appendChild(make('›', Math.min(pages, STATE.page + 1),
        { disabled: STATE.page === pages, ariaLabel: 'Next page' }));
}

function updateBulkBar() {
    const bar = $('[data-abo-admin-products-bulk]');
    if (!bar) return;
    const count = STATE.selection.size;
    const counter = $('[data-abo-admin-products-bulk-count]');
    if (counter) counter.textContent = count;
    bar.hidden = count === 0;
}

function syncHeaderCheckbox() {
    const head = $('[data-abo-admin-products-check-all]');
    if (!head) return;
    const visible = filteredSorted().slice(
        (STATE.page - 1) * STATE.perPage,
        STATE.page * STATE.perPage
    );
    const visibleIds = visible.map(p => p.id);
    const selectedVisible = visibleIds.filter(id => STATE.selection.has(id));
    head.checked = visibleIds.length > 0 && selectedVisible.length === visibleIds.length;
    head.indeterminate = selectedVisible.length > 0 && selectedVisible.length < visibleIds.length;
}

function openRowMenu(id, anchor) {
    // Close any existing menu first.
    $$('.abo-admin-products__row-menu').forEach(m => m.remove());
    $$('.abo-admin-products__row-menu-btn[aria-expanded="true"]').forEach(b => b.setAttribute('aria-expanded', 'false'));

    const menu = el('div', { class: 'abo-admin-products__row-menu', role: 'menu' });
    const items = [
        { label: 'Edit',      action: 'edit' },
        { label: 'Duplicate', action: 'duplicate' },
        { label: 'Delete',    action: 'delete', danger: true },
    ];
    items.forEach(({ label, action, danger }) => {
        const b = el('button', { type: 'button', role: 'menuitem', text: label });
        if (danger) b.classList.add('abo-admin-products__row-menu-danger');
        b.addEventListener('click', (e) => {
            e.stopPropagation();
            menu.remove();
            anchor.setAttribute('aria-expanded', 'false');
            handleRowAction(action, id);
        });
        menu.appendChild(b);
    });
    anchor.setAttribute('aria-expanded', 'true');
    anchor.parentElement.appendChild(menu);

    const onDoc = (ev) => {
        if (!menu.contains(ev.target) && ev.target !== anchor) {
            menu.remove();
            anchor.setAttribute('aria-expanded', 'false');
            document.removeEventListener('click', onDoc);
            document.removeEventListener('keydown', onKey);
        }
    };
    const onKey = (ev) => {
        if (ev.key === 'Escape') {
            menu.remove();
            anchor.setAttribute('aria-expanded', 'false');
            document.removeEventListener('click', onDoc);
            document.removeEventListener('keydown', onKey);
        }
    };
    setTimeout(() => {
        document.addEventListener('click', onDoc);
        document.addEventListener('keydown', onKey);
    }, 0);
}

function handleRowAction(action, id) {
    if (action === 'edit') {
        window.location.href = `/admin/products/${id}/edit`;
        return;
    }
    if (action === 'duplicate') {
        const src = STATE.products.find(p => p.id === id);
        if (!src) return;
        const copy = {
            ...src,
            id: 'p-' + (Math.random().toString(36).slice(2, 7)),
            sku: src.sku + '-C',
            name: src.name + ' (copy)',
            status: 'draft',
            stock: 0,
        };
        STATE.products.unshift(copy);
        renderAll();
        showToast('Product duplicated (demo)');
        return;
    }
    if (action === 'delete') {
        openModal({
            title: 'DELETE PRODUCT',
            body: 'Are you sure you want to delete this product? This action cannot be undone.',
            confirmLabel: 'DELETE',
            onConfirm: () => {
                STATE.products = STATE.products.filter(p => p.id !== id);
                STATE.selection.delete(id);
                renderAll();
                showToast('Product deleted (demo)');
            },
        });
    }
}

function renderAll() {
    updateStatCards();
    renderTable();
}

function initList() {
    const tbody = $('[data-abo-admin-products-tbody]');
    if (!tbody) return;

    initModal();

    // Filter inputs.
    const search = $('[data-abo-admin-products-search]');
    if (search) {
        search.addEventListener('input', debounce((e) => {
            STATE.filter.q = e.target.value.trim();
            STATE.page = 1;
            renderTable();
        }, 150));
    }
    $$('[data-abo-admin-products-filter]').forEach(sel => {
        sel.addEventListener('change', (e) => {
            STATE.filter[sel.dataset.aboAdminProductsFilter] = e.target.value;
            STATE.page = 1;
            renderTable();
        });
    });
    const sortSel = $('[data-abo-admin-products-sort]');
    if (sortSel) {
        sortSel.addEventListener('change', (e) => {
            STATE.sort = e.target.value;
            renderTable();
        });
    }

    // Stat cards → filter.
    $$('[data-abo-admin-products-stat]').forEach(btn => {
        btn.addEventListener('click', () => {
            STATE.stat = btn.dataset.aboAdminProductsStat;
            STATE.page = 1;
            renderAll();
        });
    });

    // Header checkbox.
    const head = $('[data-abo-admin-products-check-all]');
    if (head) {
        head.addEventListener('change', (e) => {
            const visible = filteredSorted().slice(
                (STATE.page - 1) * STATE.perPage,
                STATE.page * STATE.perPage
            );
            if (e.target.checked) visible.forEach(p => STATE.selection.add(p.id));
            else visible.forEach(p => STATE.selection.delete(p.id));
            renderTable();
        });
    }

    // Delegated row events.
    tbody.addEventListener('change', (e) => {
        const t = e.target;
        if (t.dataset.aboAdminProductsCheck) {
            const id = t.dataset.aboAdminProductsCheck;
            if (t.checked) STATE.selection.add(id);
            else            STATE.selection.delete(id);
            updateBulkBar();
            syncHeaderCheckbox();
        }
        if (t.dataset.aboAdminProductsToggle) {
            const id = t.dataset.aboAdminProductsToggle;
            const p  = STATE.products.find(x => x.id === id);
            if (p) {
                p.status = t.checked ? 'active' : 'draft';
                renderAll();
                showToast(t.checked ? 'Product activated (demo)' : 'Product set to draft (demo)');
            }
        }
    });
    tbody.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-abo-admin-products-row-menu]');
        if (btn) {
            e.stopPropagation();
            openRowMenu(btn.dataset.aboAdminProductsRowMenu, btn);
            return;
        }
        // Row click → edit (but not when clicking on inputs/buttons).
        if (e.target.closest('input, button, label, a, select')) return;
        const tr = e.target.closest('tr[data-id]');
        if (tr) {
            window.location.href = `/admin/products/${tr.dataset.id}/edit`;
        }
    });

    // Bulk bar actions.
    $$('[data-abo-admin-products-bulk-action]').forEach(btn => {
        btn.addEventListener('click', () => {
            const action = btn.dataset.aboAdminProductsBulkAction;
            const ids = [...STATE.selection];
            if (ids.length === 0) return;
            if (action === 'activate') {
                ids.forEach(id => {
                    const p = STATE.products.find(x => x.id === id);
                    if (p) p.status = 'active';
                });
                showToast(`${ids.length} product(s) activated (demo)`);
            }
            if (action === 'draft') {
                ids.forEach(id => {
                    const p = STATE.products.find(x => x.id === id);
                    if (p) p.status = 'draft';
                });
                showToast(`${ids.length} product(s) set to draft (demo)`);
            }
            if (action === 'category') {
                // Demo: rotate the category so the user sees the change.
                const keys = Object.keys(CATEGORIES);
                ids.forEach((id, i) => {
                    const p = STATE.products.find(x => x.id === id);
                    if (p) p.category = keys[(keys.indexOf(p.category) + 1) % keys.length];
                });
                showToast(`${ids.length} product(s) category changed (demo)`);
            }
            if (action === 'delete') {
                openModal({
                    title: 'DELETE PRODUCTS',
                    body: `Delete ${ids.length} product(s)? This action cannot be undone.`,
                    confirmLabel: 'DELETE',
                    onConfirm: () => {
                        STATE.products = STATE.products.filter(p => !STATE.selection.has(p.id));
                        STATE.selection.clear();
                        renderAll();
                        showToast(`${ids.length} product(s) deleted (demo)`);
                    },
                });
                return;
            }
            STATE.selection.clear();
            renderAll();
        });
    });

    // Import / Export demo buttons.
    $$('[data-abo-admin-products-action]').forEach(btn => {
        btn.addEventListener('click', () => {
            const a = btn.dataset.aboAdminProductsAction;
            showToast(`${a === 'import' ? 'Import' : 'Export'} (demo)`);
        });
    });

    renderAll();
}

/* ══════════════════════════════════════════════════════════════
   FORM PAGE
   ══════════════════════════════════════════════════════════════ */

const FORM = {
    images: [],
    selectedColors: new Set(['black', 'grey', 'olive', 'lime', 'coral']),
    selectedSizes:  new Set(['S', 'M', 'L', 'XL', 'XXL']),
    variants: [], // [{ color, size, sku, stock, price }]
    nextImageColor: '',
    tags: [],
    status: 'active',
};

function makeImageSlot(index, image) {
    const slot = el('div', {
        class: 'abo-admin-products__image-slot' +
               (image?.src ? ' filled' : ' abo-admin-products__image-slot--empty') +
               (image?.src ? '' : ''),
        role: 'listitem',
        draggable: !!image?.src,
        dataset: { aboAdminProductsImageIndex: index },
    });
    if (image?.src) slot.style.backgroundImage = `url("${image.src}")`;
    if (index === 0 && image?.src) {
        slot.appendChild(el('span', { class: 'abo-admin-products__image-cover', text: 'COVER' }));
    }
    if (image?.color) {
        const wrap = el('span', { class: 'abo-admin-products__image-colour' }, [
            el('span', { class: 'abo-admin-products__image-colour-dot', style: `--swatch: ${COLORS[image.color]?.hex}` }),
            COLORS[image.color]?.label || image.color,
        ]);
        slot.appendChild(wrap);
    }
    if (image?.src) {
        const remove = el('button', {
            type: 'button', class: 'abo-admin-products__image-remove',
            'aria-label': 'Remove image', text: '×',
        });
        remove.addEventListener('click', (e) => {
            e.stopPropagation();
            FORM.images = FORM.images.filter((_, i) => i !== index);
            renderImages();
        });
        slot.appendChild(remove);
    }
    // Drag-and-drop handlers.
    slot.addEventListener('dragstart', (e) => {
        if (!image?.src) { e.preventDefault(); return; }
        slot.classList.add('is-dragging');
        e.dataTransfer.setData('text/plain', String(index));
        e.dataTransfer.effectAllowed = 'move';
    });
    slot.addEventListener('dragend', () => slot.classList.remove('is-dragging'));
    slot.addEventListener('dragover', (e) => {
        e.preventDefault();
        slot.classList.add('is-drop-target');
    });
    slot.addEventListener('dragleave', () => slot.classList.remove('is-drop-target'));
    slot.addEventListener('drop', (e) => {
        e.preventDefault();
        slot.classList.remove('is-drop-target');
        const from = parseInt(e.dataTransfer.getData('text/plain'), 10);
        const to   = index;
        if (Number.isNaN(from) || from === to) return;
        const [moved] = FORM.images.splice(from, 1);
        FORM.images.splice(to, 0, moved);
        renderImages();
    });
    return slot;
}

function renderImages() {
    const wrap = $('[data-abo-admin-products-images]');
    if (!wrap) return;
    wrap.replaceChildren();
    const filled = FORM.images.length;
    for (let i = 0; i < filled; i++) {
        wrap.appendChild(makeImageSlot(i, FORM.images[i]));
    }
    if (filled < 8) {
        const add = el('div', {
            class: 'abo-admin-products__image-slot abo-admin-products__image-slot--add',
            role: 'listitem',
            tabindex: '0',
            'aria-label': 'Add image',
        }, '+');
        add.addEventListener('click', () => triggerFilePicker());
        add.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); triggerFilePicker(); }
        });
        wrap.appendChild(add);
    }
}

function triggerFilePicker() {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.style.display = 'none';
    document.body.appendChild(input);
    input.addEventListener('change', () => {
        const file = input.files?.[0];
        if (!file) { input.remove(); return; }
        const url = URL.createObjectURL(file);
        FORM.images.push({ src: url, color: FORM.nextImageColor || '' });
        renderImages();
        input.remove();
    });
    input.click();
}

function renderVariantChips() {
    // Colours
    $$('[data-abo-admin-products-variant-color]').forEach(btn => {
        const v = btn.dataset.aboAdminProductsVariantColor;
        const active = FORM.selectedColors.has(v);
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    // Sizes
    $$('[data-abo-admin-products-variant-size]').forEach(btn => {
        const v = btn.dataset.aboAdminProductsVariantSize;
        const active = FORM.selectedSizes.has(v);
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
}

function recomputeVariants() {
    // Keep existing entries by (color, size) where still selected;
    // remove entries whose (color, size) is no longer selected;
    // add new entries for newly selected pairs with defaults.
    const sel = new Set();
    FORM.selectedColors.forEach(c => {
        FORM.selectedSizes.forEach(s => sel.add(`${c}::${s}`));
    });
    // Remove obsolete
    FORM.variants = FORM.variants.filter(v => sel.has(`${v.color}::${v.size}`));
    // Add new
    FORM.selectedColors.forEach(c => {
        FORM.selectedSizes.forEach(s => {
            if (!FORM.variants.find(v => v.color === c && v.size === s)) {
                FORM.variants.push({
                    color: c, size: s,
                    sku: `ABO-${c.toUpperCase().slice(0, 2)}-${s}`,
                    stock: 0,
                    price: '',
                });
            }
        });
    });
    // Sort: colour then size.
    const sizeOrder = Object.fromEntries(SIZES.map((s, i) => [s, i]));
    FORM.variants.sort((a, b) => {
        if (a.color !== b.color) return a.color.localeCompare(b.color);
        return sizeOrder[a.size] - sizeOrder[b.size];
    });
    renderVariantsTable();
}

function renderVariantsTable() {
    const tbody = $('[data-abo-admin-products-variant-rows]');
    if (!tbody) return;
    tbody.replaceChildren();
    FORM.variants.forEach((v, i) => {
        const tr = el('tr');
        const variantTd = el('td', { class: 'abo-admin-products__variant-cell' }, [
            el('span', { class: 'abo-admin-products__variant-cell-dot', style: `--swatch: ${COLORS[v.color]?.hex}` }),
            `${COLORS[v.color]?.label || v.color} / ${v.size}`,
        ]);
        const skuTd = el('td', { class: 'abo-admin-products__variant-cell' }, [
            el('input', { type: 'text', value: v.sku, dataset: { aboAdminProductsVariantField: 'sku', index: i } }),
        ]);
        const low = v.stock < 5 && v.stock !== '';
        const stockTd = el('td', { class: 'abo-admin-products__variant-cell' + (low ? ' abo-admin-products__variant-cell--low' : '') }, [
            el('input', { type: 'number', min: '0', step: '1', value: v.stock === '' ? '' : v.stock, dataset: { aboAdminProductsVariantField: 'stock', index: i } }),
        ]);
        const priceTd = el('td', { class: 'abo-admin-products__variant-cell' }, [
            el('input', { type: 'number', min: '0', step: '1', placeholder: 'Same', value: v.price === '' ? '' : v.price, dataset: { aboAdminProductsVariantField: 'price', index: i } }),
        ]);
        tr.append(variantTd, skuTd, stockTd, priceTd);
        tbody.appendChild(tr);
    });
    // Field change handler (delegation).
    tbody.onchange = (e) => {
        const t = e.target;
        if (!t.dataset.aboAdminProductsVariantField) return;
        const i  = +t.dataset.index;
        const f  = t.dataset.aboAdminProductsVariantField;
        FORM.variants[i][f] = t.value;
        // Re-render to apply coral border on low stock.
        if (f === 'stock') renderVariantsTable();
    };
}

function recalcMargin() {
    const priceNode = $('[data-abo-admin-products-field="price"]');
    const costNode  = $('[data-abo-admin-products-field="cost"]');
    const pctNode   = $('[data-abo-admin-products-margin="pct"]');
    const profitNode= $('[data-abo-admin-products-margin="profit"]');
    if (!priceNode || !costNode || !pctNode || !profitNode) return;

    const price = parseFloat(priceNode.value);
    const cost  = parseFloat(costNode.value);
    if (isNaN(price) || price <= 0 || isNaN(cost)) {
        pctNode.textContent = '—';
        profitNode.textContent = '—';
        return;
    }
    const profit = price - cost;
    const pct = (profit / price) * 100;
    pctNode.textContent = `${pct.toFixed(1)}%`;
    profitNode.textContent = fmtEGP(profit);
}

function recalcPreview() {
    const nameNode = $('[data-abo-admin-products-field="name"]');
    const priceNode = $('[data-abo-admin-products-field="price"]');
    const compareNode = $('[data-abo-admin-products-field="compare"]');
    if (!nameNode || !priceNode) return;
    const nameNode2 = $('[data-abo-admin-products-preview="name"]');
    const priceNode2 = $('[data-abo-admin-products-preview="price"]');
    const compareNode2 = $('[data-abo-admin-products-preview="compare"]');
    if (nameNode2) nameNode2.textContent = nameNode.value || 'Product name';
    if (priceNode2) priceNode2.textContent = fmtEGP(priceNode.value);
    if (compareNode2) {
        const c = parseFloat(compareNode?.value);
        if (!isNaN(c) && c > parseFloat(priceNode.value)) {
            compareNode2.hidden = false;
            compareNode2.textContent = fmtEGP(c);
        } else {
            compareNode2.hidden = true;
        }
    }
}

function renderTags() {
    const list = $('[data-abo-admin-products-taglist]');
    if (!list) return;
    list.replaceChildren();
    FORM.tags.forEach((t, i) => {
        const li = el('li', { class: 'abo-admin-products__tag' }, [
            document.createTextNode(t),
            el('button', {
                type: 'button', class: 'abo-admin-products__tag-remove',
                'aria-label': `Remove tag ${t}`, text: '×',
            }),
        ]);
        li.querySelector('button').addEventListener('click', () => {
            FORM.tags.splice(i, 1);
            renderTags();
        });
        list.appendChild(li);
    });
}

function initForm() {
    const form = $('[data-abo-admin-products-form]');
    if (!form) return;

    initModal();

    // Hydrate from data-product-id (edit mode) by looking up the
    // static PRODUCTS array.
    const isEdit = form.dataset.mode === 'edit';
    const id = form.dataset.productId;
    let sample = null;
    if (isEdit && id) sample = PRODUCTS.find(p => p.id === id) || null;

    if (sample) {
        $('[data-abo-admin-products-field="name"]').value        = sample.name;
        $('[data-abo-admin-products-field="sku"]').value         = sample.sku;
        $('[data-abo-admin-products-field="description"]').value = sample.description || '';
        $('[data-abo-admin-products-field="price"]').value       = sample.price;
        $('[data-abo-admin-products-field="compare"]').value     = sample.compare || '';
        $('[data-abo-admin-products-field="cost"]').value        = sample.cost;
        $('[data-abo-admin-products-field="category"]').value    = sample.category;
        FORM.status = sample.status;
        $$('[data-abo-admin-products-status] button').forEach(b => {
            const on = b.dataset.status === sample.status;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        FORM.selectedColors = new Set(sample.colors);
        FORM.selectedSizes  = new Set(sample.sizes);
        renderVariantChips();
        recomputeVariants();
        FORM.variants.forEach((v, i) => {
            v.sku = `${sample.sku}-${v.color.slice(0, 2).toUpperCase()}-${v.size}`;
            v.stock = sample.stock > 0 ? Math.floor(sample.stock / Math.max(sample.colors.length * sample.sizes.length, 1)) : 0;
        });
        renderVariantsTable();
        FORM.images = [
            { src: 'images/streetwear-hero.jpg', color: sample.colors[0] || '' },
        ];
        renderImages();
    } else {
        // Create: empty start. Pre-populate one empty image slot.
        FORM.images = [{}];
        renderImages();
        renderVariantChips();
        recomputeVariants();
    }

    // Language switch.
    $$('[data-abo-admin-products-lang]').forEach(btn => {
        btn.addEventListener('click', () => {
            const group = btn.parentElement;
            $$('button', group).forEach(b => {
                b.classList.toggle('is-active', b === btn);
                b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
            });
        });
    });

    // Status segmented.
    $$('[data-abo-admin-products-status] button').forEach(btn => {
        btn.addEventListener('click', () => {
            const group = btn.parentElement;
            $$('button', group).forEach(b => {
                b.classList.toggle('is-active', b === btn);
                b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
            });
            FORM.status = btn.dataset.status;
        });
    });

    // Colour picker for next image.
    $$('[data-abo-admin-products-colour]').forEach(btn => {
        btn.addEventListener('click', () => {
            const group = btn.parentElement;
            $$('button', group).forEach(b => {
                b.classList.toggle('is-active', b === btn);
                b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
            });
            FORM.nextImageColor = btn.dataset.aboAdminProductsColour;
        });
    });

    // Variant colour / size chips.
    $$('[data-abo-admin-products-variant-color]').forEach(btn => {
        btn.addEventListener('click', () => {
            const v = btn.dataset.aboAdminProductsVariantColor;
            if (FORM.selectedColors.has(v)) FORM.selectedColors.delete(v);
            else FORM.selectedColors.add(v);
            renderVariantChips();
            recomputeVariants();
        });
    });
    $$('[data-abo-admin-products-variant-size]').forEach(btn => {
        btn.addEventListener('click', () => {
            const v = btn.dataset.aboAdminProductsVariantSize;
            if (FORM.selectedSizes.has(v)) FORM.selectedSizes.delete(v);
            else FORM.selectedSizes.add(v);
            renderVariantChips();
            recomputeVariants();
        });
    });

    // "Set stock for all" input.
    const setAll = $('[data-abo-admin-products-set-all]');
    if (setAll) {
        setAll.addEventListener('input', () => {
            const v = parseInt(setAll.value, 10);
            if (isNaN(v) || v < 0) return;
            FORM.variants.forEach(r => r.stock = v);
            renderVariantsTable();
        });
    }

    // Pricing → live margin / preview.
    ['price', 'compare', 'cost'].forEach(k => {
        const n = $(`[data-abo-admin-products-field="${k}"]`);
        if (n) n.addEventListener('input', () => { recalcMargin(); recalcPreview(); });
    });
    const nameField = $('[data-abo-admin-products-field="name"]');
    if (nameField) nameField.addEventListener('input', recalcPreview);
    recalcMargin();
    recalcPreview();

    // Tags input.
    const tagInput = $('[data-abo-admin-products-taginput]');
    if (tagInput) {
        tagInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                const v = tagInput.value.trim().replace(/,$/, '');
                if (v && !FORM.tags.includes(v)) {
                    FORM.tags.push(v);
                    renderTags();
                }
                tagInput.value = '';
            } else if (e.key === 'Backspace' && tagInput.value === '' && FORM.tags.length) {
                FORM.tags.pop();
                renderTags();
            }
        });
    }

    // Action buttons (top bar).
    $$('[data-abo-admin-products-form-action]').forEach(btn => {
        btn.addEventListener('click', () => {
            const a = btn.dataset.aboAdminProductsFormAction;
            if (a === 'discard') {
                openModal({
                    title: 'DISCARD CHANGES',
                    body: 'Are you sure you want to discard the changes you made?',
                    confirmLabel: 'DISCARD',
                    onConfirm: () => {
                        window.location.href = '/admin/products';
                    },
                });
            } else if (a === 'draft') {
                showToast('Saved as draft (demo)');
            } else if (a === 'publish') {
                showToast('Saved (demo)');
            }
        });
    });
}

/* ──────────────────────────────────────────────────────────────
   Init
   ────────────────────────────────────────────────────────────── */

document.addEventListener('DOMContentLoaded', () => {
    initTodayDate();
    initList();
    initForm();
});
