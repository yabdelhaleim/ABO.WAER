// ABO Admin · Orders.
//
// Scope:
//   - The order list arrives from the server: Admin\OrderService
//     serialises it into one <script type="application/json"> tag and
//     everything below hydrates from that single dataset. All
//     filtering, sorting and paging happen on the client.
//   - The three mutations (status, bulk status, cancel) are the only
//     calls made back to the server. Each applies its edit locally
//     first and rolls it back if the call fails.
//   - No localStorage / IndexedDB.
//
// The script wires six interactive things and nothing else:
//   1. The status tabs (with per-status counts + lime active bar)
//   2. The toolbar (search, payment, governorate, dateFrom, dateTo,
//      clear filters)
//   3. Sortable Date / Total headers with aria-sort toggling
//   4. Row + header checkboxes; bulk bar with status apply + print
//   5. The order detail slide-over (open/close/trap/Esc/prev/next)
//      with the timeline, items, payment, notes, cancel button
//   6. The cancel-confirm modal + a single shared toast

/* eslint-disable no-console */

(function () {
    'use strict';

    // ─── Data ─────────────────────────────────────────────────

    const dataEl = document.getElementById('abo-admin-orders-data');
    let ORDERS = [];
    try {
        ORDERS = JSON.parse(dataEl ? dataEl.textContent : '[]') || [];
    } catch (err) {
        console.error('[orders] failed to parse dataset', err);
        ORDERS = [];
    }

    // ─── Server routes ─────────────────────────────────────────
    //
    // The three mutation endpoints, rendered by Admin\OrderController.
    // Each URL carries an __ORDER__ placeholder that endpoint() swaps
    // for the order the action is being performed on.

    const routesEl = document.getElementById('abo-admin-orders-routes');
    let ROUTES = {};
    try {
        ROUTES = JSON.parse(routesEl ? routesEl.textContent : '{}') || {};
    } catch (err) {
        console.error('[orders] failed to parse route map', err);
        ROUTES = {};
    }

    // The customer the screen was narrowed to, when it was opened with
    // ?customer=<id> — the link from the customers screen. Null when
    // the whole list is shown.
    const customerFilterEl = document.getElementById('abo-admin-orders-customer-filter');
    let CUSTOMER_FILTER = null;
    try {
        CUSTOMER_FILTER = JSON.parse(customerFilterEl ? customerFilterEl.textContent : 'null');
    } catch (err) {
        console.error('[orders] failed to parse the customer filter', err);
        CUSTOMER_FILTER = null;
    }

    const csrfToken = () => {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    };

    /** Pull the most useful message out of a Laravel error body. */
    function errorMessage(payload, status) {
        if (payload && typeof payload.message === 'string' && payload.message) {
            return payload.message;
        }
        const errors = (payload && payload.errors) || {};
        const firstKey = Object.keys(errors)[0];
        if (firstKey) {
            const value = errors[firstKey];
            return Array.isArray(value) ? (value[0] || '') : String(value);
        }
        return 'Request failed (' + status + ')';
    }

    /**
     * Run one order mutation against the server.
     *
     * Rejects when the call fails so the caller can roll back the
     * optimistic local edit it already made.
     */
    async function request(routeKey, orderId, body) {
        const route = ROUTES[routeKey];
        if (!route || !route.url) throw new Error('No route configured for: ' + routeKey);

        const response = await fetch(route.url.replace('__ORDER__', encodeURIComponent(orderId)), {
            method: route.method || 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(body || {}),
        });

        let payload = null;
        try {
            payload = await response.json();
        } catch (err) {
            payload = null;
        }

        if (!response.ok || (payload && payload.success === false)) {
            throw new Error(errorMessage(payload, response.status));
        }

        return payload;
    }

    // ─── Shortcuts ────────────────────────────────────────────

    const $  = (sel, root) => (root || document).querySelector(sel);
    const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

    const root = $('.abo-admin-page') || document.body;

    if (!root || ORDERS.length === 0) {
        // Defensive: bail without crashing the page.
        console.warn('[orders] no orders dataset found; leaving shell rendered.');
        return;
    }

    // ─── State ────────────────────────────────────────────────

    const state = {
        orders:    ORDERS.slice(),
        filter: {
            tab:         'all',   // all|new|processing|shipped|delivered|cancelled|returned
            search:      '',
            payment:     '',
            governorate: '',
            dateFrom:    '',
            dateTo:      '',
            // The customer the screen was opened for, if any. It is
            // not one of the toolbar filters: it comes from the URL,
            // and the toolbar's "Clear filters" leaves it alone.
            customer:    CUSTOMER_FILTER ? String(CUSTOMER_FILTER.id) : '',
            sort:        { key: 'date', dir: 'desc' },
        },
        page:          1,
        perPage:       10,
        selected:      new Set(),  // ids selected across the filtered set
        drawer:        { open: false, id: null, returnFocus: null },
        cancelTarget:  null,       // id pending cancellation
    };

    // ─── Status catalogue (label + variant key) ───────────────

    const STATUS_LABELS = {
        new:        'New',
        processing: 'Processing',
        shipped:    'Shipped',
        delivered:  'Delivered',
        cancelled:  'Cancelled',
        returned:   'Returned',
    };

    const STATUS_BADGE = {
        new:        'abo-admin-orders__badge--new',
        processing: 'abo-admin-orders__badge--processing',
        shipped:    'abo-admin-orders__badge--shipped',
        delivered:  'abo-admin-orders__badge--delivered',
        cancelled:  'abo-admin-orders__badge--cancelled',
        returned:   'abo-admin-orders__badge--returned',
    };

    const PAY_LABELS = {
        cod:    'Cash on delivery',
        card:   'Card',
        wallet: 'Wallet',
    };

    const PAY_PILL = {
        paid:     'abo-admin-orders__pay-pill--paid',
        pending:  'abo-admin-orders__pay-pill--pending',
        refunded: 'abo-admin-orders__pay-pill--refunded',
    };

    const PAY_PILL_LABEL = {
        paid:     'Paid',
        pending:  'Pending',
        refunded: 'Refunded',
    };

    // ─── Helpers ───────────────────────────────────────────────

    const fmtEGP = (n) => 'EGP ' + new Intl.NumberFormat('en-EG').format(n);

    const fmtDate = (iso) => {
        // iso: "YYYY-MM-DDTHH:mm:ss"
        const d  = new Date(iso);
        const dd = String(d.getDate()).padStart(2, '0');
        const mm = d.toLocaleString('en-GB', { month: 'short' });
        const yy = d.getFullYear();
        const hh = String(d.getHours()).padStart(2, '0');
        const mn = String(d.getMinutes()).padStart(2, '0');
        return { short: `${dd} ${mm} ${yy}`, full: `${dd} ${mm} ${yy}, ${hh}:${mn}` };
    };

    const todayISO = () => new Date().toISOString().slice(0, 10);

    // ─── Filtering + sorting ──────────────────────────────────

    function filteredList() {
        const f = state.filter;
        const from = f.dateFrom ? f.dateFrom + 'T00:00:00' : '';
        const to   = f.dateTo   ? f.dateTo   + 'T23:59:59' : '';
        const hay  = f.search.trim().toLowerCase();

        let list = state.orders.filter((o) => {
            if (f.tab !== 'all' && o.status !== f.tab) return false;
            if (hay) {
                const h = (o.id + ' ' + o.customer.name + ' ' + o.customer.phone).toLowerCase();
                if (h.indexOf(hay) === -1) return false;
            }
            if (f.payment     && o.payment.method !== f.payment) return false;
            if (f.governorate && o.customer.address.governorate !== f.governorate) return false;
            if (f.customer    && String(o.customer.id) !== f.customer) return false;
            if (from && o.createdAt < from) return false;
            if (to   && o.createdAt > to)   return false;
            return true;
        });

        const { key, dir } = f.sort;
        const mul = dir === 'asc' ? 1 : -1;
        list.sort((a, b) => {
            let av, bv;
            if (key === 'total') { av = a.total;      bv = b.total; }
            else                 { av = a.createdAt;  bv = b.createdAt; }
            if (av < bv) return -1 * mul;
            if (av > bv) return  1 * mul;
            return 0;
        });
        return list;
    }

    function currentPageSlice() {
        const list = filteredList();
        const start = (state.page - 1) * state.perPage;
        return { list, slice: list.slice(start, start + state.perPage), total: list.length };
    }

    // ─── Toast ────────────────────────────────────────────────

    let toastTimer;
    function toast(text) {
        const wrap = $('[data-abo-admin-orders-toast]');
        const txt  = $('[data-abo-admin-orders-toast-text]', wrap);
        if (!wrap) return;
        txt.textContent = text;
        wrap.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { wrap.hidden = true; }, 2400);
    }

    // ─── Render: status tab counts ────────────────────────────

    function renderTabCounts() {
        // The counts follow the customer filter, so they describe the
        // orders actually on screen and not the whole book.
        const scoped = state.filter.customer
            ? state.orders.filter((o) => String(o.customer.id) === state.filter.customer)
            : state.orders;

        const counts = { all: scoped.length };
        scoped.forEach((o) => { counts[o.status] = (counts[o.status] || 0) + 1; });
        $$('[data-abo-admin-orders-tab-count]').forEach((el) => {
            const k = el.getAttribute('data-abo-admin-orders-tab-count');
            el.textContent = '(' + (counts[k] || 0) + ')';
        });
    }

    // ─── Render: order rows (desktop table) ───────────────────

    function badgeHTML(o) {
        const variant = STATUS_BADGE[o.status] || 'abo-admin-orders__badge--new';
        return '<span class="abo-admin-orders__badge ' + variant + '">' + STATUS_LABELS[o.status] + '</span>';
    }

    function paymentHTML(o) {
        const m = PAY_LABELS[o.payment.method] || o.payment.method;
        const v = PAY_PILL[o.payment.status]  || PAY_PILL.pending;
        const lbl = PAY_PILL_LABEL[o.payment.status] || 'Pending';
        return '' +
            '<div class="abo-admin-orders__payment">' +
                '<span class="abo-admin-orders__payment-method">' + escapeHTML(m) + '</span>' +
                '<span class="abo-admin-orders__pay-pill ' + v + '">' + lbl + '</span>' +
            '</div>';
    }

    function rowHTML(o) {
        const d = fmtDate(o.createdAt);
        return '' +
            '<tr class="abo-admin-orders__row" data-abo-admin-orders-id="' + escapeHTML(o.id) + '" tabindex="0">' +
                '<td class="abo-admin-orders__check-cell"><label class="sr-only" for="abo-admin-orders-check-' + escapeHTML(o.id) + '">Select order ' + escapeHTML(o.id) + '</label>' +
                    '<input id="abo-admin-orders-check-' + escapeHTML(o.id) + '" type="checkbox" data-abo-admin-orders-check="' + escapeHTML(o.id) + '"' + (state.selected.has(o.id) ? ' checked' : '') + '></td>' +
                '<td><span class="abo-admin-orders__id">#' + escapeHTML(o.id) + '</span></td>' +
                '<td><span class="abo-admin-orders__date-cell">' + d.short + '</span></td>' +
                '<td>' +
                    '<div class="abo-admin-orders__customer">' +
                        '<span class="abo-admin-orders__customer-name">' + escapeHTML(o.customer.name) + '</span>' +
                        '<span class="abo-admin-orders__customer-phone">' + escapeHTML(o.customer.phone) + '</span>' +
                    '</div>' +
                '</td>' +
                '<td>' + escapeHTML(o.customer.address.governorate) + '</td>' +
                '<td><span class="abo-admin-orders__items-count">' + o.itemsCount + '</span></td>' +
                '<td><span class="abo-admin-orders__total-cell">' + fmtEGP(o.total) + '</span></td>' +
                '<td>' + paymentHTML(o) + '</td>' +
                '<td data-abo-admin-orders-status-cell="' + escapeHTML(o.id) + '">' + badgeHTML(o) + '</td>' +
                '<td class="abo-admin-orders__th-actions">' +
                    '<button type="button" class="abo-admin-orders__view-btn" aria-label="View order #' + escapeHTML(o.id) + '" data-abo-admin-orders-view="' + escapeHTML(o.id) + '">' +
                        '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
                            '<path d="M12 5c-7 0-10 7-10 7s3 7 10 7 10-7 10-7-3-7-10-7zm0 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8z" fill="currentColor"/>' +
                        '</svg>' +
                    '</button>' +
                '</td>' +
            '</tr>';
    }

    function renderTable(rows) {
        const tbody = $('[data-abo-admin-orders-tbody]');
        tbody.innerHTML = rows.map(rowHTML).join('');
    }

    function renderCards(rows) {
        const ul = $('[data-abo-admin-orders-cards]');
        ul.innerHTML = rows.map((o) => {
            const d = fmtDate(o.createdAt);
            return '' +
                '<li class="abo-admin-orders__card" data-abo-admin-orders-id="' + escapeHTML(o.id) + '" data-abo-admin-orders-view="' + escapeHTML(o.id) + '">' +
                    '<div class="abo-admin-orders__card-row">' +
                        '<span class="abo-admin-orders__card-id">#' + escapeHTML(o.id) + '</span>' +
                        '<span data-abo-admin-orders-status-cell="' + escapeHTML(o.id) + '">' + badgeHTML(o) + '</span>' +
                    '</div>' +
                    '<div class="abo-admin-orders__card-row">' +
                        '<span class="abo-admin-orders__card-strong">' + escapeHTML(o.customer.name) + '</span>' +
                        '<span class="abo-admin-orders__card-strong">' + fmtEGP(o.total) + '</span>' +
                    '</div>' +
                    '<div class="abo-admin-orders__card-row">' +
                        '<span class="abo-admin-orders__card-meta">' + escapeHTML(PAY_LABELS[o.payment.method]) + ' · ' + escapeHTML(o.customer.address.governorate) + '</span>' +
                        '<span class="abo-admin-orders__card-meta">' + d.short + '</span>' +
                    '</div>' +
                '</li>';
        }).join('');
    }

    function renderSortHeaders() {
        $$('[data-abo-admin-orders-sort]').forEach((th) => {
            const key = th.getAttribute('data-abo-admin-orders-sort');
            th.setAttribute('aria-sort', state.filter.sort.key === key
                ? (state.filter.sort.dir === 'asc' ? 'ascending' : 'descending')
                : 'none');
        });
    }

    // ─── Render: pagination + state ───────────────────────────

    function renderPagination(total) {
        const info = $('[data-abo-admin-orders-pagination-info]');
        const list = $('[data-abo-admin-orders-pagination-list]');
        const perPage = state.perPage;
        const totalPages = Math.max(1, Math.ceil(total / perPage));
        if (state.page > totalPages) state.page = totalPages;
        if (state.page < 1) state.page = 1;

        const from = total === 0 ? 0 : (state.page - 1) * perPage + 1;
        const to   = Math.min(total, state.page * perPage);
        info.textContent = 'Showing ' + from + ' to ' + to + ' of ' + total + ' orders';

        const buttons = [];
        buttons.push('<li><button type="button" class="abo-admin-orders__page-btn" aria-label="Previous page"' + (state.page <= 1 ? ' disabled' : '') + ' data-abo-admin-orders-page="prev">‹</button></li>');
        for (let p = 1; p <= totalPages; p++) {
            buttons.push('<li><button type="button" class="abo-admin-orders__page-btn' + (p === state.page ? ' is-active' : '') + '" aria-label="Page ' + p + '" data-abo-admin-orders-page="' + p + '">' + p + '</button></li>');
        }
        buttons.push('<li><button type="button" class="abo-admin-orders__page-btn" aria-label="Next page"' + (state.page >= totalPages ? ' disabled' : '') + ' data-abo-admin-orders-page="next">›</button></li>');
        list.innerHTML = buttons.join('');

        const countEl = $('[data-abo-admin-orders-result-count]');
        if (countEl) countEl.textContent = total + ' ' + (total === 1 ? 'order' : 'orders');
    }

    function renderState(total) {
        // The empty state only shows when the *dataset* itself is
        // empty, not when filters return zero. If filters return
        // zero we show the no-results state instead.
        $$('[data-abo-admin-orders-state]').forEach((el) => {
            const v = el.getAttribute('data-abo-admin-orders-state');
            if (v === 'loading') return; // never auto-show
            el.hidden = true;
        });
        if (state.orders.length === 0) {
            $('[data-abo-admin-orders-state="empty"]').hidden = false;
            return;
        }
        if (total === 0) {
            $('[data-abo-admin-orders-state="noresults"]').hidden = false;
        }
    }

    // ─── Selection + bulk ────────────────────────────────────

    function renderSelectionHeader() {
        const total = filteredList().length;
        const slice = currentPageSlice().slice.map((o) => o.id);
        const allOnPageSelected = slice.length > 0 && slice.every((id) => state.selected.has(id));
        const someOnPageSelected = slice.some((id) => state.selected.has(id));
        const headerCheck = $('[data-abo-admin-orders-check-all]');
        if (!headerCheck) return;
        headerCheck.checked    = allOnPageSelected;
        headerCheck.indeterminate = someOnPageSelected && !allOnPageSelected;
        headerCheck.setAttribute('aria-checked', allOnPageSelected ? 'true' : (someOnPageSelected ? 'mixed' : 'false'));
    }

    function renderBulkBar() {
        const bar = $('[data-abo-admin-orders-bulk]');
        const count = state.selected.size;
        bar.hidden = count === 0;
        $('[data-abo-admin-orders-bulk-count]').textContent = count;
    }

    // ─── Clear-filters button visibility ─────────────────────

    function renderClearVisibility() {
        const f = state.filter;
        const any = !!f.search || !!f.payment || !!f.governorate || !!f.dateFrom || !!f.dateTo || f.tab !== 'all';
        $$('[data-abo-admin-orders-clear]').forEach((b) => { b.hidden = !any; });
    }

    // ─── Single render entry point ───────────────────────────

    function render() {
        const { list, slice, total } = currentPageSlice();
        renderTable(slice);
        renderCards(slice);
        renderPagination(total);
        renderSortHeaders();
        renderState(total);
        renderSelectionHeader();
        renderBulkBar();
        renderClearVisibility();
        // The export carries the filters, so it follows the list.
        syncExportLink();
        // Drawer needs to keep up if the row inside it disappears.
        if (state.drawer.open && !list.find((o) => o.id === state.drawer.id)) {
            closeDrawer();
        }
    }

    // ─── Listeners: tabs ──────────────────────────────────────

    $$('[data-abo-admin-orders-tab]').forEach((btn) => {
        btn.addEventListener('click', () => {
            state.filter.tab = btn.getAttribute('data-abo-admin-orders-tab');
            state.page = 1;
            $$('[data-abo-admin-orders-tab]').forEach((b) => {
                const isActive = b === btn;
                b.classList.toggle('is-active', isActive);
                b.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });
            render();
        });
    });

    // ─── Listeners: toolbar ───────────────────────────────────

    const searchInput  = $('[data-abo-admin-orders-search]');
    const paymentSel   = $('[data-abo-admin-orders-filter="payment"]');
    const govSel       = $('[data-abo-admin-orders-filter="governorate"]');
    const dateFromInp  = $('[data-abo-admin-orders-filter="dateFrom"]');
    const dateToInp    = $('[data-abo-admin-orders-filter="dateTo"]');

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            state.filter.search = searchInput.value;
            state.page = 1;
            render();
        });
    }
    if (paymentSel) {
        paymentSel.addEventListener('change', () => {
            state.filter.payment = paymentSel.value;
            state.page = 1;
            render();
        });
    }
    if (govSel) {
        govSel.addEventListener('change', () => {
            state.filter.governorate = govSel.value;
            state.page = 1;
            render();
        });
    }
    if (dateFromInp) {
        dateFromInp.max = todayISO();
        dateFromInp.addEventListener('change', () => {
            state.filter.dateFrom = dateFromInp.value;
            state.page = 1;
            if (state.filter.dateTo && state.filter.dateTo < state.filter.dateFrom) {
                state.filter.dateTo = state.filter.dateFrom;
                dateToInp.value = state.filter.dateTo;
            }
            render();
        });
    }
    if (dateToInp) {
        dateToInp.max = todayISO();
        dateToInp.addEventListener('change', () => {
            state.filter.dateTo = dateToInp.value;
            state.page = 1;
            render();
        });
    }
    $$('[data-abo-admin-orders-clear]').forEach((b) => {
        b.addEventListener('click', () => {
            // The customer filter comes from the URL, so "Clear
            // filters" leaves it alone — the chip beside the toolbar
            // is what takes it off.
            state.filter = { tab: 'all', search: '', payment: '', governorate: '', dateFrom: '', dateTo: '', customer: state.filter.customer, sort: { key: 'date', dir: 'desc' } };
            if (searchInput)  searchInput.value  = '';
            if (paymentSel)   paymentSel.value   = '';
            if (govSel)       govSel.value       = '';
            if (dateFromInp)  dateFromInp.value  = '';
            if (dateToInp)    dateToInp.value    = '';
            state.page = 1;
            $$('[data-abo-admin-orders-tab]').forEach((tb) => {
                const isActive = tb.getAttribute('data-abo-admin-orders-tab') === 'all';
                tb.classList.toggle('is-active', isActive);
                tb.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });
            render();
        });
    });

    // ─── Listeners: sortable headers ──────────────────────────

    $$('[data-abo-admin-orders-sort]').forEach((th) => {
        th.addEventListener('click', () => {
            const key = th.getAttribute('data-abo-admin-orders-sort');
            const cur = state.filter.sort;
            if (cur.key === key) {
                cur.dir = cur.dir === 'asc' ? 'desc' : 'asc';
            } else {
                cur.key = key;
                cur.dir = (key === 'date') ? 'desc' : 'asc';
            }
            render();
        });
        th.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                th.click();
            }
        });
    });

    // ─── Export CSV ───────────────────────────────────────────

    /**
     * The export link, carrying the filters the screen is showing.
     *
     * The server does the filtering: the link names the same filters
     * the toolbar holds, and the endpoint reads the rows out of the
     * orders table. The browser never builds the file itself.
     */
    function exportUrl() {
        const route = ROUTES.export;
        if (!route || !route.url) return null;

        const f = state.filter;
        const params = new URLSearchParams();

        if (f.tab && f.tab !== 'all') params.set('status', f.tab);
        if (f.search.trim()) params.set('q', f.search.trim());
        if (f.payment) params.set('payment', f.payment);
        if (f.governorate) params.set('governorate', f.governorate);
        if (f.customer) params.set('customer', f.customer);
        if (f.dateFrom) params.set('from', f.dateFrom);
        if (f.dateTo) params.set('to', f.dateTo);
        if (f.sort) params.set('sort', f.sort.key);
        if (f.sort) params.set('dir', f.sort.dir);

        const query = params.toString();

        return route.url + (query ? '?' + query : '');
    }

    /**
     * Keep the export link in step with the list on screen.
     */
    function syncExportLink() {
        const link = $('[data-abo-admin-orders-action="export"]');
        const url = exportUrl();
        if (link && url) link.setAttribute('href', url);
    }

    // ─── Print ────────────────────────────────────────────────
    //
    // The two documents are server-rendered pages: the invoice for one
    // order, and the labels sheet for the whole selection. The browser
    // only opens them — every line on the paper is measured from the
    // database by App\Services\Admin\ReceiptService, never from the
    // copy this file happens to be holding.

    /**
     * Open a print document in a new tab.
     *
     * The dialog is opened by the document itself once it has loaded
     * (the page carries the script that calls window.print()), so the
     * sheet is laid out before the operator sees it.
     */
    function openPrintDocument(url) {
        if (!url) {
            toast('Print is not available right now');
            return;
        }

        window.open(url, '_blank', 'noopener');
    }

    /**
     * The invoice for one order.
     */
    function invoiceUrl(orderId) {
        const route = ROUTES.invoice;
        if (!route || !route.url || !orderId) return null;

        return route.url.replace('__ORDER__', encodeURIComponent(orderId));
    }

    /**
     * Print the invoice of the order the drawer is showing.
     */
    function printInvoice(orderId) {
        const url = invoiceUrl(orderId);
        if (!url) {
            toast('Print is not available right now');
            return;
        }

        openPrintDocument(url);
    }

    /**
     * The labels sheet for the selected orders.
     *
     * The selection is the set of order numbers the rows carry, and
     * they travel in the query string in the order they were picked —
     * the sheet comes out in the order the operator chose.
     */
    function labelsUrl() {
        const route = ROUTES.labels;
        if (!route || !route.url) return null;

        const numbers = Array.from(state.selected);
        if (numbers.length === 0) return null;

        return route.url + '?orders=' + encodeURIComponent(numbers.join(','));
    }

    /**
     * Print the labels of every selected order.
     */
    function printLabels() {
        if (state.selected.size === 0) {
            toast('Select at least one order');
            return;
        }

        const url = labelsUrl();
        if (!url) {
            toast('Print is not available right now');
            return;
        }

        openPrintDocument(url);
    }

    // ─── Listeners: row open / view / checkbox ────────────────

    document.addEventListener('click', (e) => {
        const target = e.target;

        // View button
        const viewBtn = target.closest('[data-abo-admin-orders-view]');
        if (viewBtn) {
            e.preventDefault();
            e.stopPropagation();
            const id = viewBtn.getAttribute('data-abo-admin-orders-view')
                     || viewBtn.closest('[data-abo-admin-orders-id]')?.getAttribute('data-abo-admin-orders-id');
            if (id) openDrawer(id, viewBtn);
            return;
        }

        // Bulk actions
        const bulkApply = target.closest('[data-abo-admin-orders-bulk-action="apply"]');
        if (bulkApply) {
            const sel = $('[data-abo-admin-orders-bulk-status]');
            const newStatus = sel && sel.value;
            if (!newStatus) { toast('Pick a status first'); return; }

            const targets = state.orders.filter((o) => state.selected.has(o.id));
            if (targets.length === 0) { toast('Select at least one order'); return; }

            const previous = new Map(targets.map((o) => [o.id, o.status]));

            targets.forEach((o) => {
                o.status = newStatus;
                const at = new Date().toISOString().slice(0, 19);
                o.timeline.unshift({ at, label: STATUS_LABELS[newStatus] });
            });
            state.selected.clear();
            updateAllStatusCells();
            render();

            // One call per order; each row reverts on its own if the
            // server refuses it.
            Promise.all(targets.map((o) =>
                request('status', o.id, { status: newStatus })
                    .then(() => null)
                    .catch((error) => ({ order: o, error }))
            )).then((outcomes) => {
                const failed = outcomes.filter(Boolean);
                if (failed.length === 0) {
                    toast(targets.length + ' order(s) updated');
                    return;
                }
                failed.forEach(({ order }) => {
                    order.status = previous.get(order.id);
                    order.timeline.shift();
                });
                updateAllStatusCells();
                render();
                if (state.drawer.open && failed.some((f) => f.order.id === state.drawer.id)) {
                    renderDrawer(state.drawer.id);
                }
                toast(failed.length + ' order(s) could not be updated: ' + failed[0].error.message);
            });
            return;
        }
        const bulkPrint = target.closest('[data-abo-admin-orders-bulk-action="print"]');
        if (bulkPrint) {
            printLabels();
            return;
        }

        // Row click (open drawer), but ignore clicks on inputs / buttons.
        const row = target.closest('tr.abo-admin-orders__row');
        if (row && !target.matches('input, button, a, label, [role="button"]')) {
            openDrawer(row.getAttribute('data-abo-admin-orders-id'), row);
            return;
        }

        // Card click (mobile)
        const card = target.closest('.abo-admin-orders__card');
        if (card && !target.matches('input, button, a, label, [role="button"]')) {
            openDrawer(card.getAttribute('data-abo-admin-orders-id'), card);
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            const row = e.target.closest && e.target.closest('tr.abo-admin-orders__row');
            if (row) {
                e.preventDefault();
                openDrawer(row.getAttribute('data-abo-admin-orders-id'), row);
            }
        }
    });

    document.addEventListener('change', (e) => {
        const target = e.target;

        // Header checkbox selects all visible (current page)
        if (target.matches('[data-abo-admin-orders-check-all]')) {
            const slice = currentPageSlice().slice;
            const allSelected = slice.every((o) => state.selected.has(o.id));
            slice.forEach((o) => {
                if (allSelected) state.selected.delete(o.id);
                else state.selected.add(o.id);
            });
            render();
            return;
        }

        // Per-row checkbox
        if (target.matches('[data-abo-admin-orders-check]')) {
            const id = target.getAttribute('data-abo-admin-orders-check');
            if (target.checked) state.selected.add(id);
            else state.selected.delete(id);
            render();
        }
    });

    // ─── Drawer (slide-over) ──────────────────────────────────

    const drawer = $('[data-abo-admin-orders-drawer]');
    const panel  = drawer ? drawer.querySelector('.abo-admin-orders__drawer-panel') : null;

    function escapeHTML(s) {
        return String(s).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        })[c]);
    }

    function findOrder(id) { return state.orders.find((o) => o.id === id); }

    function renderTimeline(o) {
        const ul = $('[data-abo-admin-orders-drawer-timeline]');
        ul.innerHTML = o.timeline.map((t) => {
            const ts = fmtDate(t.at);
            return '<li>' +
                '<span>' + escapeHTML(t.label) + '</span>' +
                '<time datetime="' + escapeHTML(t.at) + '">' + escapeHTML(ts.full) + '</time>' +
            '</li>';
        }).join('');
    }

    function renderItems(o) {
        const ul = $('[data-abo-admin-orders-drawer-items]');
        ul.innerHTML = o.items.map((it) => {
            return '<li class="abo-admin-orders__item">' +
                '<span class="abo-admin-orders__item-thumb" aria-hidden="true">ITM</span>' +
                '<div class="abo-admin-orders__item-text">' +
                    '<p class="abo-admin-orders__item-name">' + escapeHTML(it.name) + '</p>' +
                    '<p class="abo-admin-orders__item-variant">' + escapeHTML(it.color) + ' / ' + escapeHTML(it.size) +
                    ' &nbsp;·&nbsp; <span class="abo-admin-orders__item-qty">×' + it.qty + '</span></p>' +
                '</div>' +
                '<div class="abo-admin-orders__item-price">' +
                    '<span class="abo-admin-orders__item-unit">' + fmtEGP(it.unitPrice) + '</span>' +
                    '<span class="abo-admin-orders__item-line">' + fmtEGP(it.lineTotal) + '</span>' +
                '</div>' +
            '</li>';
        }).join('');
    }

    function renderNotes(o) {
        const ul = $('[data-abo-admin-orders-drawer-notes]');
        if (!o.notes || o.notes.length === 0) {
            ul.innerHTML = '';
            return;
        }
        ul.innerHTML = o.notes.map((n) => {
            const ts = fmtDate(n.at);
            return '<li>' +
                '<span>' + escapeHTML(n.text) + '</span>' +
                '<time datetime="' + escapeHTML(n.at) + '">' + escapeHTML(ts.full) + '</time>' +
            '</li>';
        }).join('');
    }

    function renderDrawer(id) {
        const o = findOrder(id);
        if (!o) return closeDrawer();
        const d = fmtDate(o.createdAt);

        $('[data-abo-admin-orders-drawer-id]').textContent       = '#' + o.id;
        $('[data-abo-admin-orders-drawer-date]').textContent     = d.full;
        $('[data-abo-admin-orders-drawer-status-wrap]').innerHTML = badgeHTML(o);

        $('[data-abo-admin-orders-drawer-status]').value         = o.status;

        $('[data-abo-admin-orders-drawer-customer-name]').textContent = o.customer.name;
        const phoneLink = $('[data-abo-admin-orders-drawer-phone]');
        phoneLink.textContent = o.customer.phone;
        phoneLink.setAttribute('href', 'tel:' + o.customer.phone.replace(/\s/g, ''));
        const emailLink = $('[data-abo-admin-orders-drawer-email]');
        emailLink.textContent = o.customer.email;
        emailLink.setAttribute('href', 'mailto:' + o.customer.email);

        const a = o.customer.address;
        const addressParts = [
            a.street,
            a.building,
            a.city + ', ' + a.governorate,
        ];
        let addressText = addressParts.filter(Boolean).join(', ');
        if (a.notes) addressText += '. ' + a.notes;
        $('[data-abo-admin-orders-drawer-address]').textContent = addressText;
        $('[data-abo-admin-orders-drawer-delivery]').textContent = o.deliveryMethod;

        renderItems(o);

        $('[data-abo-admin-orders-drawer-payment]').innerHTML =
            escapeHTML(PAY_LABELS[o.payment.method]) + ' ' +
            '<span class="abo-admin-orders__pay-pill ' + PAY_PILL[o.payment.status] + '">' + PAY_PILL_LABEL[o.payment.status] + '</span>';

        $('[data-abo-admin-orders-drawer-subtotal]').textContent = fmtEGP(o.subtotal);
        $('[data-abo-admin-orders-drawer-shipping]').textContent = fmtEGP(o.shipping);
        const discountRow = $('[data-abo-admin-orders-drawer-discount-row]');
        if (o.discount > 0 || (o.coupon && o.coupon !== '')) {
            discountRow.hidden = false;
            $('[data-abo-admin-orders-drawer-discount]').textContent = (o.discount > 0 ? '−' + fmtEGP(o.discount) : '—');
            $('[data-abo-admin-orders-drawer-coupon]').textContent = o.coupon ? '(' + o.coupon + ')' : '';
        } else {
            discountRow.hidden = true;
        }
        $('[data-abo-admin-orders-drawer-total]').textContent = fmtEGP(o.total);

        renderTimeline(o);
        renderNotes(o);

        // Danger zone visibility — hidden for Delivered, Cancelled, Returned.
        const dangerZone = $('[data-abo-admin-orders-drawer-danger]');
        dangerZone.hidden = (o.status === 'delivered' || o.status === 'cancelled' || o.status === 'returned');

        // Position indicator on the footer (filtered slice).
        const list = filteredList();
        const idx  = list.findIndex((x) => x.id === o.id);
        $('[data-abo-admin-orders-drawer-pos]').textContent =
            list.length > 0 ? ('Order ' + (idx + 1) + ' of ' + list.length) : '—';
        $('[data-abo-admin-orders-drawer-prev]').disabled = (idx <= 0);
        $('[data-abo-admin-orders-drawer-next]').disabled = (idx >= list.length - 1);

        // Reset the note input every time the drawer is rendered.
        const noteInput = $('[data-abo-admin-orders-drawer-note-input]');
        if (noteInput) noteInput.value = '';
    }

    function updateStatusBadgeInList(o) {
        $$('[data-abo-admin-orders-status-cell="' + o.id + '"]').forEach((el) => {
            el.innerHTML = badgeHTML(o);
        });
    }

    function updateAllStatusCells() {
        state.orders.forEach(updateStatusBadgeInList);
    }

    // Focus trap inside the drawer.
    const FOCUSABLE = 'a[href], area[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    function openDrawer(id, fromEl) {
        const o = findOrder(id);
        if (!o) return;
        state.drawer.open = true;
        state.drawer.id = id;
        state.drawer.returnFocus = fromEl || document.activeElement;
        renderDrawer(id);
        drawer.hidden = false;
        // Two rAFs so the transition kicks in after display becomes block.
        requestAnimationFrame(() => {
            drawer.classList.add('is-open');
            requestAnimationFrame(() => {
                panel.focus();
            });
        });
        document.body.style.overflow = 'hidden';
    }

    function closeDrawer() {
        if (!drawer) return;
        drawer.classList.remove('is-open');
        const ret = state.drawer.returnFocus;
        setTimeout(() => {
            drawer.hidden = true;
            state.drawer.open = false;
            state.drawer.id = null;
            document.body.style.overflow = '';
            if (ret && typeof ret.focus === 'function') ret.focus();
        }, 240);
    }

    // Drawer close handlers
    if (drawer) {
        $$('[data-abo-admin-orders-drawer-close]', drawer).forEach((el) => {
            el.addEventListener('click', closeDrawer);
        });
        drawer.querySelector('.abo-admin-orders__drawer-overlay')
            .addEventListener('click', closeDrawer);

        // Print invoice — the document is rendered by the server from
        // the order's own rows and the receipt settings the store saved.
        $('[data-abo-admin-orders-drawer-print]', drawer)
            .addEventListener('click', () => printInvoice(state.drawer.id));

        // Status update
        $('[data-abo-admin-orders-drawer-status-apply]', drawer)
            .addEventListener('click', () => {
                const o = findOrder(state.drawer.id);
                if (!o) return;
                const sel = $('[data-abo-admin-orders-drawer-status]', drawer);
                const next = sel.value;
                if (!next || next === o.status) {
                    toast(next === o.status ? 'Already on this status' : 'Pick a status first');
                    return;
                }
                const previous = o.status;
                o.status = next;
                const at = new Date().toISOString().slice(0, 19);
                o.timeline.unshift({ at, label: STATUS_LABELS[next] });
                updateStatusBadgeInList(o);
                renderDrawer(o.id);
                request('status', o.id, { status: next })
                    .then((res) => { toast(res.message || 'Status updated'); })
                    .catch((err) => {
                        // Put the row back the way it was.
                        o.status = previous;
                        o.timeline.shift();
                        updateStatusBadgeInList(o);
                        if (state.drawer.open && state.drawer.id === o.id) renderDrawer(o.id);
                        toast(err.message);
                    });
            });

        // Add note
        const noteAdd = $('[data-abo-admin-orders-drawer-note-add]', drawer);
        if (noteAdd) {
            noteAdd.addEventListener('click', () => {
                const o = findOrder(state.drawer.id);
                if (!o) return;
                const inp = $('[data-abo-admin-orders-drawer-note-input]', drawer);
                const text = (inp.value || '').trim();
                if (!text) {
                    toast('Type a note first');
                    return;
                }
                const at = new Date().toISOString().slice(0, 19);
                if (!o.notes) o.notes = [];
                o.notes.unshift({ at, text });
                inp.value = '';
                renderNotes(o);
            });
        }

        // Cancel order (open confirm modal)
        const cancelBtn = $('[data-abo-admin-orders-drawer-cancel]', drawer);
        if (cancelBtn) {
            cancelBtn.addEventListener('click', () => {
                const o = findOrder(state.drawer.id);
                if (!o) return;
                state.cancelTarget = o.id;
                const modal = $('[data-abo-admin-orders-modal]');
                const body  = $('[data-abo-admin-orders-modal-body]', modal);
                body.textContent = 'Mark order #' + o.id + ' as cancelled? This cannot be undone.';
                modal.hidden = false;
                requestAnimationFrame(() => {
                    modal.querySelector('[data-abo-admin-orders-modal-close]').focus();
                });
            });
        }

        // Prev / next
        $('[data-abo-admin-orders-drawer-prev]', drawer)
            .addEventListener('click', () => moveDrawer(-1));
        $('[data-abo-admin-orders-drawer-next]', drawer)
            .addEventListener('click', () => moveDrawer(1));
    }

    function moveDrawer(delta) {
        const list = filteredList();
        const idx  = list.findIndex((o) => o.id === state.drawer.id);
        const next = list[idx + delta];
        if (!next) return;
        state.drawer.id = next.id;
        renderDrawer(next.id);
    }

    // Keyboard: Esc + focus trap
    document.addEventListener('keydown', (e) => {
        // Modal focus trap and close
        const modal = $('[data-abo-admin-orders-modal]');
        if (modal && !modal.hidden) {
            if (e.key === 'Escape') { e.preventDefault(); closeModal(); return; }
            if (e.key === 'Tab') {
                const focusables = Array.from(modal.querySelectorAll(FOCUSABLE))
                    .filter((el) => !el.hasAttribute('disabled') && el.offsetParent !== null);
                if (focusables.length === 0) return;
                const first = focusables[0];
                const last  = focusables[focusables.length - 1];
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault(); last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault(); first.focus();
                }
            }
            return;
        }

        if (state.drawer.open) {
            if (e.key === 'Escape') { e.preventDefault(); closeDrawer(); return; }
            if (e.key === 'Tab') {
                const focusables = Array.from(panel.querySelectorAll(FOCUSABLE))
                    .filter((el) => !el.hasAttribute('disabled') && el.offsetParent !== null);
                if (focusables.length === 0) return;
                const first = focusables[0];
                const last  = focusables[focusables.length - 1];
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault(); last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault(); first.focus();
                }
            }
        }
    });

    // ─── Modal (cancel order) ────────────────────────────────

    function closeModal() {
        const modal = $('[data-abo-admin-orders-modal]');
        if (!modal) return;
        modal.hidden = true;
        state.cancelTarget = null;
        // Refocus the cancel button inside the drawer (if still there).
        const cancelBtn = $('[data-abo-admin-orders-drawer-cancel]');
        if (cancelBtn) cancelBtn.focus();
    }

    const modal = $('[data-abo-admin-orders-modal]');
    if (modal) {
        $$('[data-abo-admin-orders-modal-close]', modal).forEach((el) => {
            el.addEventListener('click', closeModal);
        });
        $('[data-abo-admin-orders-modal-confirm]', modal).addEventListener('click', () => {
            const o = findOrder(state.cancelTarget);
            if (!o) { closeModal(); return; }

            const previous = o.status;
            o.status = 'cancelled';
            const at = new Date().toISOString().slice(0, 19);
            o.timeline.unshift({ at, label: 'Cancelled' });
            updateStatusBadgeInList(o);
            if (state.drawer.open && state.drawer.id === o.id) renderDrawer(o.id);
            closeModal();

            // The server returns the sold quantity to the variants, so
            // this is the call that puts stock back.
            request('cancel', o.id, {})
                .then((res) => { toast(res.message || 'Order cancelled'); })
                .catch((err) => {
                    o.status = previous;
                    o.timeline.shift();
                    updateStatusBadgeInList(o);
                    if (state.drawer.open && state.drawer.id === o.id) renderDrawer(o.id);
                    toast(err.message);
                });
        });
    }

    // ─── Today-date label (matches dashboard) ─────────────────

    const dateEl = $('#abo-admin-today-date');
    if (dateEl) {
        dateEl.textContent = new Date().toLocaleDateString('en-GB', {
            weekday: 'long', day: '2-digit', month: 'long', year: 'numeric',
        });
    }

    // ─── Initial render ───────────────────────────────────────

    renderTabCounts();
    render();
})();
