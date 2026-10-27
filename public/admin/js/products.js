/*
 * ABO Admin · Products — page-local script.
 *
 * Self-gating: each init function checks for the section element
 * and exits silently if not present. This lets the same file
 * serve both the list page and the form page (and stay loaded
 * without errors on other admin pages that import it).
 *
 * The catalog is read from the database: Admin\ProductController
 * serialises the products table into window.__PRODUCTS__ and the list
 * hydrates from it. The list used to answer from a hard-coded array of
 * fourteen sample products, so the table showed products the store
 * could not sell and every action on them was a no-op.
 *
 * The writes go back to the same admin endpoints the rest of the
 * catalog uses: the toggle, the row menu and the bulk bar all talk
 * to the server.
 *
 * Sections:
 *   initTodayDate        — set the header date (matches dashboard)
 *   initList             — list page (filters, table, bulk, pagination,
 *                          modal, toast, stat cards)
 *   initForm             — form page (basic, images, variants, pricing,
 *                          organise, preview, modal, toast)
 */

/* ──────────────────────────────────────────────────────────────
   Data
   ────────────────────────────────────────────────────────────── */

/*
 * The catalog, straight from the products table. The controller sends
 * one row per product with every field the list renders: the price
 * and the stock come from the variant rows, the colours from the
 * product_colors pivot, the cover from the first product image.
 */
const PRODUCTS = (typeof window !== 'undefined' && Array.isArray(window.__PRODUCTS__))
    ? window.__PRODUCTS__
    : [];

/* The categories the bulk "change category" picker offers. */
const CATEGORIES = (typeof window !== 'undefined' && Array.isArray(window.__PRODUCT_CATEGORIES__))
    ? window.__PRODUCT_CATEGORIES__
    : [];

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
    // The body may carry markup (the bulk "change category" picker puts
    // a <select> in it), so it is set as HTML. Every value that reaches
    // it is escaped by the caller.
    if (bodyNode)  bodyNode.innerHTML   = body;
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
    if (p.status === 'archived') return 'archived';
    if (p.stock === 0) return 'out';
    if (p.stock < p.low) return 'low';
    return 'in';
}

function stockPill(p) {
    const state = stockState(p);
    if (state === 'archived') {
        return `<span class="abo-admin-products__stock-pill abo-admin-products__stock-pill--draft">Archived</span>`;
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
        const haystack = [p.name, p.nameAr, p.sku, p.slug].filter(Boolean).join(' ').toLowerCase();
        if (!haystack.includes(needle)) return false;
    }
    // The category filter carries the category id, so it is compared
    // against the product's category id and not against its label.
    if (category && p.categoryId !== category) return false;
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
        // The ids travel as strings, so they are compared as numbers:
        // a lexicographic order would put "99" after "100".
        default:           out.sort((a, b) => Number(b.id) - Number(a.id));
    }
    return out;
}

function filteredSorted() {
    let arr = STATE.products.filter(matchesFilters);
    if (STATE.stat === 'active')   arr = arr.filter(p => p.status === 'active');
    if (STATE.stat === 'archived') arr = arr.filter(p => p.status === 'archived');
    if (STATE.stat === 'low')      arr = arr.filter(p => {
        const s = stockState(p);
        return s === 'low' || s === 'out';
    });
    return sortProducts(arr);
}

function updateStatCards() {
    const root = document;
    const total     = STATE.products.length;
    const active    = STATE.products.filter(p => p.status === 'active').length;
    const archived  = STATE.products.filter(p => p.status === 'archived').length;
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
    set('archived', archived);
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
    const thumb = el('span', { class: 'abo-admin-products__product-thumb', 'aria-hidden': 'true' });
    if (p.cover) {
        const img = el('img', { src: p.cover, alt: '', loading: 'lazy', decoding: 'async' });
        thumb.appendChild(img);
    } else {
        thumb.textContent = 'IMG';
    }
    productTd.appendChild(el('div', { class: 'abo-admin-products__product' }, [
        thumb,
        el('div', { class: 'abo-admin-products__product-text' }, [
            el('p', { class: 'abo-admin-products__product-name', text: p.name }),
            el('p', { class: 'abo-admin-products__product-sku',  text: p.sku || p.slug }),
        ]),
    ]));

    const categoryTd = el('td', { text: p.category || '—' });

    const priceTd = el('td');
    if (p.discount && p.discount < p.price) {
        priceTd.appendChild(el('span', { class: 'abo-admin-products__price-now', text: fmtEGP(p.discount) }));
        priceTd.appendChild(el('span', { class: 'abo-admin-products__price-was', text: fmtEGP(p.price) }));
    } else {
        priceTd.appendChild(el('span', { class: 'abo-admin-products__price-now', text: fmtEGP(p.price) }));
    }

    const variantsTd = el('td');
    const variantsInner = el('div', { class: 'abo-admin-products__variants-cell' });
    p.colors.slice(0, 4).forEach(c => {
        variantsInner.appendChild(el('span', {
            class: 'abo-admin-products__variant-dot',
            style: `--swatch: ${c.hex || '#8D928A'}`,
            title: c.name || '',
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
    const product = STATE.products.find(p => p.id === id);
    const items = [
        { label: 'Edit', action: 'edit' },
        {
            label: product && product.status === 'archived' ? 'Unarchive' : 'Archive',
            action: product && product.status === 'archived' ? 'unarchive' : 'archive',
        },
        { label: 'Delete', action: 'delete', danger: true },
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
    if (action === 'archive' || action === 'unarchive') {
        // The archive flag lives on the product row, so the toggle goes
        // to the server and the table is re-read from what it answers.
        setProductArchived(id, action === 'archive');
        return;
    }
    if (action === 'delete') {
        openModal({
            title: 'DELETE PRODUCT',
            body: 'Are you sure you want to delete this product? This action cannot be undone.',
            confirmLabel: 'DELETE',
            onConfirm: () => {
                bulkRequest('delete', [id]).then((res) => {
                    if (!res || !res.success) return;
                    STATE.products = STATE.products.filter(p => p.id !== id);
                    STATE.selection.delete(id);
                    renderAll();
                    showToast(res.message || 'Product deleted.');
                });
            },
        });
    }
}

/**
 * The CSRF token the admin pages issue in the layout.
 */
function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

/**
 * Ask the server to apply a bulk action to a set of products.
 *
 * @param  {string[]} action
 * @param  {string[]} ids
 * @param  {object}   [extra] additional body fields (e.g. category_id)
 */
function bulkRequest(action, ids, extra = {}) {
    return fetch('/admin/products/bulk', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({ action, ids, ...extra }),
    })
        .then(res => res.json().catch(() => null))
        .then(data => {
            if (!data || data.success !== true) {
                showToast((data && data.message) || 'The action could not be applied.');
                return null;
            }
            return data;
        })
        .catch(() => {
            showToast('The server could not be reached.');
            return null;
        });
}

/**
 * Archive or unarchive one product and re-read it from the server.
 */
function setProductArchived(id, archived) {
    const product = STATE.products.find(p => p.id === id);
    if (!product) return;

    fetch(`/admin/products/${id}/toggle-archive`, {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
    })
        .then(res => res.json().catch(() => null))
        .then(data => {
            if (!data || data.success !== true) {
                showToast((data && data.message) || 'The product could not be updated.');
                renderAll();
                return;
            }
            product.status = archived ? 'archived' : 'active';
            renderAll();
            showToast(archived ? 'Product archived.' : 'Product restored.');
        })
        .catch(() => {
            showToast('The server could not be reached.');
            renderAll();
        });
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
                // The checkbox is the is_archived flag on the product row.
                setProductArchived(id, !t.checked);
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

            if (action === 'category') {
                openCategoryPicker(ids);
                return;
            }

            if (action === 'delete') {
                openModal({
                    title: 'DELETE PRODUCTS',
                    body: `Delete ${ids.length} product(s)? This action cannot be undone.`,
                    confirmLabel: 'DELETE',
                    onConfirm: () => {
                        bulkRequest('delete', ids).then((res) => {
                            if (!res || !res.success) return;
                            STATE.products = STATE.products.filter(p => !STATE.selection.has(p.id));
                            STATE.selection.clear();
                            renderAll();
                            showToast(res.message || `${ids.length} product(s) deleted.`);
                        });
                    },
                });
                return;
            }

            bulkRequest(action, ids).then((res) => {
                if (!res || !res.success) return;
                const archived = action === 'archive';
                ids.forEach(id => {
                    const p = STATE.products.find(x => x.id === id);
                    if (p) p.status = archived ? 'archived' : 'active';
                });
                STATE.selection.clear();
                renderAll();
                showToast(res.message || `${ids.length} product(s) updated.`);
            });
        });
    });

    renderAll();
}

/**
 * Ask which category the selected products should move to, then apply
 * the move on the server.
 */
function openCategoryPicker(ids) {
    const options = CATEGORIES
        .map(c => `<option value="${escapeHTML(c.id)}">${escapeHTML(c.name)}</option>`)
        .join('');

    openModal({
        title: 'CHANGE CATEGORY',
        body: `<p>Move ${ids.length} product(s) to:</p>
               <select class="abo-admin-products__select" data-abo-admin-products-bulk-category>${options}</select>`,
        confirmLabel: 'MOVE',
        danger: false,
        onConfirm: () => {
            const select = $('[data-abo-admin-products-bulk-category]');
            const categoryId = select ? select.value : '';
            if (!categoryId) {
                showToast('Pick a category first.');
                return;
            }
            bulkRequest('category', ids, { category_id: categoryId }).then(res => {
                if (!res || !res.success) return;
                const category = CATEGORIES.find(c => c.id === categoryId);
                ids.forEach(id => {
                    const p = STATE.products.find(x => x.id === id);
                    if (p) {
                        p.categoryId = categoryId;
                        if (category) p.category = category.name;
                    }
                });
                STATE.selection.clear();
                renderAll();
                showToast(res.message || `${ids.length} product(s) moved.`);
            });
        },
    });
}

/* ══════════════════════════════════════════════════════════════
   FORM PAGE
   ══════════════════════════════════════════════════════════════ */

/*
 * The form is a real form: it posts to Admin\ProductController and the
 * server writes the product, its colours, its images and its variants.
 *
 * The script used to hydrate the form from the same hard-coded sample
 * array the list used, so opening a real product showed an empty name,
 * an empty variant table and a colour list the database never had —
 * and saving it wiped the variants the product actually had. The form
 * is now rendered by the server and the script only rebuilds the
 * colour × size matrix when the operator changes the selection.
 */

const FORM = {
    colors: [],   // [{ id, name, hex }]
    sizes: [],    // [{ id, name }]
    variants: [], // [{ colorId, sizeId, sku, price, cost, discountPrice, quantity }]
};

/**
 * Read the payload the view publishes next to the form.
 */
function readFormPayload() {
    const node = $('[data-abo-admin-products-form-payload]');
    if (!node) return null;

    try {
        return JSON.parse(node.textContent || '{}');
    } catch {
        return null;
    }
}

/**
 * The colour ids the operator ticked.
 */
function selectedColorIds() {
    return $$('[data-abo-admin-products-variant-color-input]')
        .filter((input) => input.checked)
        .map((input) => input.value);
}

/**
 * The size ids the operator ticked.
 */
function selectedSizeIds() {
    return $$('[data-abo-admin-products-variant-size-input]')
        .filter((input) => input.checked)
        .map((input) => input.value);
}

/**
 * The SKU the server derives for a colour × size pair.
 *
 * ProductService::buildSku() strips the slug and appends the two ids,
 * so the operator sees the same string the database will hold.
 */
function deriveSku(slug, colorId, sizeId) {
    /*
     * The same rule the server applies in ProductService::buildSku():
     * the slug of the product, slugified without a separator and
     * upper-cased, then the colour and the size of the row. A form
     * that shows one SKU and writes another is a form nobody can
     * trust.
     */
    const base = String(slug || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '');

    return `${(base || 'PROD').toUpperCase()}-C${colorId}-S${sizeId}`;
}

/**
 * Rebuild the colour × size matrix from the ticked boxes.
 *
 * A pair that already had a variant keeps its prices and its stock; a
 * new pair starts from the default price the operator typed.
 */
function buildMatrix() {
    const colorIds = selectedColorIds();
    const sizeIds = selectedSizeIds();
    const known = new Map(FORM.variants.map((v) => [`${v.colorId}:${v.sizeId}`, v]));
    const slug = $('[data-abo-admin-products-field="slug"]')?.value || '';

    const rows = [];

    colorIds.forEach((colorId) => {
        sizeIds.forEach((sizeId) => {
            const key = `${colorId}:${sizeId}`;
            const existing = known.get(key);

            // The SKU is derived from the slug on every pass: the server
            // rewrites it on every save, so the form must show the SKU
            // the save is going to write.
            rows.push({
                ...(existing || {
                    price: defaultPrice(),
                    cost: defaultCost(),
                    discountPrice: '',
                    quantity: 0,
                }),
                colorId,
                sizeId,
                sku: deriveSku(slug, colorId, sizeId),
            });
        });
    });

    FORM.variants = rows;

    renderVariantsTable();
}

function defaultPrice() {
    const value = parseFloat($('[data-abo-admin-products-field="price"]')?.value);
    return isNaN(value) ? 0 : value;
}

function defaultCost() {
    const value = parseFloat($('[data-abo-admin-products-field="cost"]')?.value);
    return isNaN(value) ? 0 : value;
}

/**
 * Paint the matrix. Every cell is a real input that posts to the
 * server, so the table survives a save without any JavaScript.
 */
function renderVariantsTable() {
    const tbody = $('[data-abo-admin-products-variant-rows]');
    if (!tbody) return;

    const colorById = new Map(FORM.colors.map((c) => [String(c.id), c]));
    const sizeById = new Map(FORM.sizes.map((s) => [String(s.id), s]));

    tbody.replaceChildren();

    if (FORM.variants.length === 0) {
        tbody.appendChild(el('tr', { 'data-abo-admin-products-variant-empty': '' }, [
            el('td', { colspan: '6', text: 'Pick the colours and sizes the product comes in.' }),
        ]));

        return;
    }

    FORM.variants.forEach((variant, index) => {
        const color = colorById.get(String(variant.colorId));
        const size = sizeById.get(String(variant.sizeId));

        const hidden = (name, val) => el('input', {
            type: 'hidden',
            name: `variants[${index}][${name}]`,
            value: val !== undefined ? val : (variant[name] ?? ''),
        });

        const number = (name, value, step) => el('input', {
            type: 'number',
            name: `variants[${index}][${name}]`,
            value: value === '' || value === null || value === undefined ? '' : value,
            min: '0',
            step: step,
            dataset: { aboAdminProductsVariantField: name, index },
        });

        tbody.appendChild(el('tr', { 'data-abo-admin-products-variant-row': `${variant.colorId}:${variant.sizeId}` }, [
            el('td', { class: 'abo-admin-products__variant-cell' }, [
                el('span', {
                    class: 'abo-admin-products__variant-cell-dot',
                    style: `--swatch: ${color?.hex || '#8D928A'}`,
                }),
                `${color?.name || variant.colorId} / ${size?.name || variant.sizeId}`,
            ]),
            el('td', { class: 'abo-admin-products__variant-cell' }, [
                hidden('color_id', variant.colorId ?? variant.color_id),
                hidden('size_id', variant.sizeId ?? variant.size_id),
                el('span', { class: 'abo-admin-products__variant-sku', text: variant.sku || '—' }),
            ]),
            el('td', { class: 'abo-admin-products__variant-cell' }, [number('quantity', variant.quantity, '1')]),
            el('td', { class: 'abo-admin-products__variant-cell' }, [number('price', variant.price, '0.01')]),
            el('td', { class: 'abo-admin-products__variant-cell' }, [number('cost', variant.cost, '0.01')]),
            el('td', { class: 'abo-admin-products__variant-cell' }, [number('discount_price', variant.discountPrice, '0.01')]),
        ]));
    });
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

function initForm() {
    const form = $('[data-abo-admin-products-form]');
    if (!form) return;

    initModal();

    const payload = readFormPayload() || {};

    FORM.colors = payload.colors || [];
    FORM.sizes = payload.sizes || [];
    FORM.variants = payload.product?.variants || [];

    // The defaults the operator typed are the starting point of a new
    // variant row; the existing rows keep what the database holds.
    const first = FORM.variants[0];
    const priceField = $('[data-abo-admin-products-field="price"]');
    const costField = $('[data-abo-admin-products-field="cost"]');
    const compareField = $('[data-abo-admin-products-field="compare"]');

    if (priceField && !priceField.value && first) priceField.value = first.price;
    if (costField && !costField.value && first) costField.value = first.cost;
    if (compareField && !compareField.value && first?.discountPrice) compareField.value = first.discountPrice;

    // Language switch: the English and the Arabic name live side by side
    // and the tabs decide which one is in front.
    $$('[data-abo-admin-products-lang]').forEach(btn => {
        btn.addEventListener('click', () => {
            const group = btn.parentElement;
            $$('button', group).forEach(b => {
                b.classList.toggle('is-active', b === btn);
                b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
            });

            const lang = btn.dataset.aboAdminProductsLang;

            $$('[data-abo-admin-products-lang-panel]').forEach(panel => {
                panel.hidden = panel.dataset.aboAdminProductsLangPanel !== lang;
            });

            $$('[data-abo-admin-products-lang-field]').forEach(field => {
                field.closest('[data-abo-admin-products-lang-panel], .abo-admin-products__field')?.toggleAttribute('hidden', field.dataset.aboAdminProductsLangField !== lang);
            });
        });
    });

    // Status segmented control — two radios that post the archive flag.
    $$('[data-abo-admin-products-status-input]').forEach(input => {
        input.addEventListener('change', () => {
            const group = input.closest('[data-abo-admin-products-status]');
            if (!group) return;
            $$('[data-abo-admin-products-status-input]', group).forEach(radio => {
                radio.closest('.abo-admin-products__segment')?.classList.toggle('is-active', radio.checked);
            });
        });
    });

    // Colour and size chips: the checkboxes inside them are the values
    // the server receives.
    $$('[data-abo-admin-products-variant-color-input]').forEach(input => {
        input.addEventListener('change', () => {
            input.closest('[data-abo-admin-products-variant-color]')?.classList.toggle('is-active', input.checked);
            buildMatrix();
        });
    });
    $$('[data-abo-admin-products-variant-size-input]').forEach(input => {
        input.addEventListener('change', () => {
            input.closest('[data-abo-admin-products-variant-size]')?.classList.toggle('is-active', input.checked);
            buildMatrix();
        });
    });

    // "Set stock for all" input.
    const setAll = $('[data-abo-admin-products-set-all]');
    if (setAll) {
        setAll.addEventListener('input', () => {
            const v = parseInt(setAll.value, 10);
            if (isNaN(v) || v < 0) return;
            FORM.variants.forEach((row) => { row.quantity = v; });
            renderVariantsTable();
        });
    }

    // The matrix cells write back into the model.
    const rows = $('[data-abo-admin-products-variant-rows]');
    if (rows) {
        rows.addEventListener('input', (e) => {
            const field = e.target.dataset?.aboAdminProductsVariantField;
            if (!field) return;
            const index = Number(e.target.dataset.index);
            if (!FORM.variants[index]) return;

            if (field === 'discount_price') {
                FORM.variants[index].discountPrice = e.target.value;
            } else if (field === 'quantity') {
                FORM.variants[index].quantity = e.target.value;
            } else {
                FORM.variants[index][field] = e.target.value;
            }
        });
    }

    // Pricing → live margin / preview.
    ['price', 'compare', 'cost'].forEach((k) => {
        const n = $(`[data-abo-admin-products-field="${k}"]`);
        if (n) n.addEventListener('input', () => { recalcMargin(); recalcPreview(); });
    });
    const nameField = $('[data-abo-admin-products-field="name"]');
    if (nameField) nameField.addEventListener('input', recalcPreview);

    // A new slug changes every SKU the operator sees.
    const slugField = $('[data-abo-admin-products-field="slug"]');
    if (slugField) {
        slugField.addEventListener('input', () => {
            FORM.variants.forEach((variant) => {
                variant.sku = deriveSku(slugField.value, variant.colorId, variant.sizeId);
            });
            renderVariantsTable();
        });
    }

    recalcMargin();
    recalcPreview();
    renderVariantsTable();

    // Action buttons (top bar). The form posts to the server, so
    // "save" submits it — there is no local state to save.
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
                return;
            }

            // The two save buttons decide the status of the product:
            // "save as draft" archives it, "save and publish" puts it
            // back in the store.
            if (a === 'draft' || a === 'publish') {
                const radio = $(`[data-abo-admin-products-status-input][value="${a === 'draft' ? '1' : '0'}"]`);

                if (radio) {
                    radio.checked = true;

                    $$('[data-abo-admin-products-status-input]').forEach((other) => {
                        other.closest('.abo-admin-products__segment')?.classList.toggle('is-active', other === radio);
                    });
                }
            }

            form.submit();
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
