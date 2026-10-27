// ABO Admin · Customers — wired to the database.
//
// The customers, their addresses, their orders and the figures above
// the list all arrive in one JSON payload built by
// Admin\CustomerService. The segment of a customer is decided on the
// server as well: this script reads it, it never derives it — there is
// one definition of "spend" and of "VIP" in the application, and it
// lives in App\Services\CustomerMetricsService.
//
// The three actions the screen offers are real requests:
//
//   save tags + note  → PUT    /admin/customers/{customer}
//   block / unblock   → PATCH  /admin/customers/{customer}/block
//   export            → GET    /admin/customers/export
//
// The script wires six interactive things and nothing else:
//   1. The segment tabs (with per-segment counts + lime active bar)
//   2. The toolbar (search, governorate, sort, clear filters)
//   3. Sortable Orders / Total spent headers with aria-sort
//   4. The customer table + mobile stacked cards
//   5. The customer detail slide-over (open/close/trap/Esc/prev/
//      next) with contact, stats, addresses, order history, tags,
//      internal note, and the block / unblock action
//   6. The block / unblock confirm modal + a single shared toast

/* eslint-disable no-console */

(function () {
    'use strict';

    // ─── Data ─────────────────────────────────────────────────

    const dataEl = document.getElementById('abo-admin-customers-data');
    let CUSTOMERS = [];
    try {
        CUSTOMERS = JSON.parse(dataEl ? dataEl.textContent : '[]') || [];
    } catch (err) {
        console.error('[customers] failed to parse dataset', err);
        CUSTOMERS = [];
    }

    const routesEl = document.getElementById('abo-admin-customers-routes');
    let ROUTES = {};
    try {
        ROUTES = JSON.parse(routesEl ? routesEl.textContent : '{}') || {};
    } catch (err) {
        console.error('[customers] failed to parse route map', err);
        ROUTES = {};
    }

    // ─── Shortcuts ────────────────────────────────────────────

    const $  = (sel, root) => (root || document).querySelector(sel);
    const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

    const root = $('.abo-admin-page') || document.body;

    if (!root || CUSTOMERS.length === 0) {
        // Defensive: bail without crashing the page.
        console.warn('[customers] no customers dataset found; leaving shell rendered.');
        return;
    }

    // ─── State ────────────────────────────────────────────────

    const state = {
        customers:  CUSTOMERS.slice(),
        filter: {
            tab:         'all',   // all|new|returning|vip|noorders|blocked
            search:      '',
            governorate: '',
            sort:        'newest', // newest|orders-desc|spend-desc|name-asc
        },
        page:          1,
        perPage:       10,
        drawer:        { open: false, id: null, returnFocus: null },
        blockTarget:   null,       // id pending block / unblock
    };

    // ─── Catalogue ────────────────────────────────────────────

    // Segment display order (also used for the tab order).
    const SEGMENT_KEYS = ['new', 'returning', 'vip', 'noorders', 'blocked'];

    const SEGMENT_LABELS = {
        new:       'New',
        returning: 'Returning',
        vip:       'VIP',
        noorders:  'No orders',
        blocked:   'Blocked',
    };

    // Badge variant key, mapped 1:1 to the CSS class.
    const SEGMENT_BADGE = {
        new:       'abo-admin-customers__badge--new',
        returning: 'abo-admin-customers__badge--returning',
        vip:       'abo-admin-customers__badge--vip',
        noorders:  'abo-admin-customers__badge--noorders',
        blocked:   'abo-admin-customers__badge--blocked',
    };

    // Status catalogue for the recent-order badge — re-uses the
    // CSS class names from the orders page so the visual language
    // stays consistent across both lists.
    const ORDER_STATUS_LABELS = {
        new:        'New',
        processing: 'Processing',
        shipped:    'Shipped',
        delivered:  'Delivered',
        cancelled:  'Cancelled',
        returned:   'Returned',
    };

    const ORDER_STATUS_BADGE = {
        new:        'abo-admin-orders__badge--new',
        processing: 'abo-admin-orders__badge--processing',
        shipped:    'abo-admin-orders__badge--shipped',
        delivered:  'abo-admin-orders__badge--delivered',
        cancelled:  'abo-admin-orders__badge--cancelled',
        returned:   'abo-admin-orders__badge--returned',
    };

    // ─── Helpers ───────────────────────────────────────────────

    const fmtEGP = (n) => 'EGP ' + new Intl.NumberFormat('en-EG').format(n);

    const fmtDate = (iso) => {
        // iso: "YYYY-MM-DD"
        if (!iso) return null;
        const d  = new Date(iso + 'T00:00:00');
        const dd = String(d.getDate()).padStart(2, '0');
        const mm = d.toLocaleString('en-GB', { month: 'short' });
        const yy = d.getFullYear();
        return dd + ' ' + mm + ' ' + yy;
    };

    // The segment of a customer is decided on the server, by the one
    // service that owns the rule. The browser reads it — it never
    // derives it, so the badge, the tab counts and the segment a
    // campaign reaches can never drift apart.
    function segmentOf(c) {
        return c.segment || 'new';
    }

    function escapeHTML(s) {
        return String(s).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        })[c]);
    }

    // ─── Filtering + sorting ──────────────────────────────────

    function filteredList() {
        const f = state.filter;
        const hay = f.search.trim().toLowerCase();

        let list = state.customers.filter((c) => {
            // Tab membership.
            if (f.tab !== 'all' && segmentOf(c) !== f.tab) return false;

            if (hay) {
                const h = (c.name + ' ' + c.email + ' ' + c.phone).toLowerCase();
                if (h.indexOf(hay) === -1) return false;
            }
            if (f.governorate && c.governorate !== f.governorate) return false;
            return true;
        });

        const cmp = sortComparator(f.sort);
        list.sort(cmp);
        return list;
    }

    function sortComparator(key) {
        switch (key) {
            case 'orders-desc':
                return (a, b) => b.ordersCount - a.ordersCount
                    || (b.lastOrder || '').localeCompare(a.lastOrder || '');
            case 'spend-desc':
                return (a, b) => b.totalSpent - a.totalSpent;
            case 'name-asc':
                return (a, b) => a.name.localeCompare(b.name);
            case 'newest':
            default:
                return (a, b) => b.signupDate.localeCompare(a.signupDate);
        }
    }

    function currentPageSlice() {
        const list = filteredList();
        const start = (state.page - 1) * state.perPage;
        return { list, slice: list.slice(start, start + state.perPage), total: list.length };
    }

    // ─── Toast ────────────────────────────────────────────────

    let toastTimer;
    function toast(text) {
        const wrap = $('[data-abo-admin-customers-toast]');
        const txt  = $('[data-abo-admin-customers-toast-text]', wrap);
        if (!wrap) return;
        txt.textContent = text;
        wrap.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { wrap.hidden = true; }, 2400);
    }

    // ─── Requests

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    /**
     * Pull the most useful message out of a Laravel error body.
     */
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
        return 'The request failed (' + status + ').';
    }

    /**
     * Run one mutation against the server.
     *
     * Rejects with the server's own message when the action is refused,
     * so the caller can show it without knowing the rules.
     */
    async function request(method, url, body) {
        const options = {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
        };

        if (body !== undefined) {
            options.body = JSON.stringify(body);
        }

        const response = await fetch(url, options);

        let payload = null;
        try {
            payload = await response.json();
        } catch (err) {
            payload = null;
        }

        if (!response.ok || (payload && payload.success === false)) {
            throw new Error(errorMessage(payload, response.status));
        }

        return payload || { success: true };
    }

    /**
     * The URL of one of the customer endpoints, with the customer id
     * put in.
     */
    function urlFor(key, customerId) {
        const url = ROUTES[key];
        if (!url) throw new Error('No route configured for: ' + key);
        return customerId === undefined
            ? url
            : url.replace('__CUSTOMER__', encodeURIComponent(customerId));
    }

    // ─── Render: segment tab counts
    // ─── Render: segment tab counts ───────────────────────────

    function renderTabCounts() {
        const counts = { all: state.customers.length };
        SEGMENT_KEYS.forEach((k) => { counts[k] = 0; });
        state.customers.forEach((c) => {
            const seg = segmentOf(c);
            counts[seg] = (counts[seg] || 0) + 1;
        });
        $$('[data-abo-admin-customers-tab-count]').forEach((el) => {
            const k = el.getAttribute('data-abo-admin-customers-tab-count');
            el.textContent = '(' + (counts[k] || 0) + ')';
        });
    }

    // ─── Render: badges ───────────────────────────────────────

    function segmentBadgeHTML(c) {
        const seg = segmentOf(c);
        const variant = SEGMENT_BADGE[seg] || 'abo-admin-customers__badge--new';
        const label   = SEGMENT_LABELS[seg] || 'New';
        if (seg === 'vip') {
            return '<span class="abo-admin-customers__badge ' + variant + '">' +
                '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
                    '<path d="m12 2 2.9 6.9 7.4.6-5.6 4.9 1.7 7.3L12 17.8 5.6 21.7l1.7-7.3-5.6-4.9 7.4-.6L12 2z" fill="currentColor"/>' +
                '</svg>' +
                'VIP</span>';
        }
        return '<span class="abo-admin-customers__badge ' + variant + '">' + label + '</span>';
    }

    function orderStatusBadgeHTML(status) {
        const variant = ORDER_STATUS_BADGE[status] || 'abo-admin-orders__badge--new';
        const label   = ORDER_STATUS_LABELS[status] || status;
        return '<span class="abo-admin-orders__badge ' + variant + '">' + label + '</span>';
    }

    // ─── Render: customer rows (desktop table) ────────────────

    function rowHTML(c) {
        const lastOrder = c.lastOrder
            ? '<span class="abo-admin-customers__date-cell">' + escapeHTML(fmtDate(c.lastOrder)) + '</span>'
            : '<span class="abo-admin-customers__date-cell abo-admin-customers__date-cell--muted">Never</span>';

        return '' +
            '<tr class="abo-admin-customers__row" data-abo-admin-customers-id="' + escapeHTML(c.id) + '" tabindex="0">' +
                '<td>' +
                    '<div class="abo-admin-customers__customer">' +
                        '<span class="abo-admin-customers__avatar-sm" aria-hidden="true">' + escapeHTML(c.initials) + '</span>' +
                        '<div class="abo-admin-customers__customer-text">' +
                            '<span class="abo-admin-customers__customer-name">' + escapeHTML(c.name) + '</span>' +
                            '<span class="abo-admin-customers__customer-email">' + escapeHTML(c.email) + '</span>' +
                        '</div>' +
                    '</div>' +
                '</td>' +
                '<td><span class="abo-admin-customers__phone">' + escapeHTML(c.phone) + '</span></td>' +
                '<td><span class="abo-admin-customers__gov">' + escapeHTML(c.governorate) + '</span></td>' +
                '<td><span class="abo-admin-customers__orders-cell">' + c.ordersCount + '</span></td>' +
                '<td><span class="abo-admin-customers__spend-cell">' + fmtEGP(c.totalSpent) + '</span></td>' +
                '<td data-abo-admin-customers-lastorder-cell="' + escapeHTML(c.id) + '">' + lastOrder + '</td>' +
                '<td data-abo-admin-customers-segment-cell="' + escapeHTML(c.id) + '">' + segmentBadgeHTML(c) + '</td>' +
                '<td class="abo-admin-customers__th-actions">' +
                    '<button type="button" class="abo-admin-customers__view-btn" aria-label="View ' + escapeHTML(c.name) + '" data-abo-admin-customers-view="' + escapeHTML(c.id) + '">' +
                        '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
                            '<path d="M12 5c-7 0-10 7-10 7s3 7 10 7 10-7 10-7-3-7-10-7zm0 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8z" fill="currentColor"/>' +
                        '</svg>' +
                    '</button>' +
                '</td>' +
            '</tr>';
    }

    function renderTable(rows) {
        const tbody = $('[data-abo-admin-customers-tbody]');
        tbody.innerHTML = rows.map(rowHTML).join('');
    }

    function renderCards(rows) {
        const ul = $('[data-abo-admin-customers-cards]');
        ul.innerHTML = rows.map((c) => {
            const last = c.lastOrder ? fmtDate(c.lastOrder) : 'Never';
            return '' +
                '<li class="abo-admin-customers__card" data-abo-admin-customers-id="' + escapeHTML(c.id) + '" data-abo-admin-customers-view="' + escapeHTML(c.id) + '">' +
                    '<div class="abo-admin-customers__card-row">' +
                        '<div class="abo-admin-customers__card-head">' +
                            '<span class="abo-admin-customers__avatar-sm" aria-hidden="true">' + escapeHTML(c.initials) + '</span>' +
                            '<span class="abo-admin-customers__card-name">' + escapeHTML(c.name) + '</span>' +
                        '</div>' +
                        '<span data-abo-admin-customers-segment-cell="' + escapeHTML(c.id) + '">' + segmentBadgeHTML(c) + '</span>' +
                    '</div>' +
                    '<div class="abo-admin-customers__card-row">' +
                        '<span class="abo-admin-customers__card-meta">' + escapeHTML(c.phone) + ' · ' + escapeHTML(c.governorate) + '</span>' +
                    '</div>' +
                    '<div class="abo-admin-customers__card-row">' +
                        '<span class="abo-admin-customers__card-stats">' +
                            '<span><strong>' + c.ordersCount + '</strong> orders</span>' +
                            '<span><strong>' + fmtEGP(c.totalSpent) + '</strong> spent</span>' +
                        '</span>' +
                        '<span class="abo-admin-customers__card-meta">' + last + '</span>' +
                    '</div>' +
                '</li>';
        }).join('');
    }

    // ─── Render: sortable headers ─────────────────────────────

    function renderSortHeaders() {
        const sortKeyMap = {
            'orders': 'orders-desc',
            'spend':  'spend-desc',
        };
        const activeSort = state.filter.sort;
        $$('[data-abo-admin-customers-sort-col]').forEach((th) => {
            const col = th.getAttribute('data-abo-admin-customers-sort-col');
            const matches = sortKeyMap[col] === activeSort;
            th.setAttribute('aria-sort', matches ? 'descending' : 'none');
        });
    }

    // ─── Render: pagination + state ───────────────────────────

    function renderPagination(total) {
        const info = $('[data-abo-admin-customers-pagination-info]');
        const list = $('[data-abo-admin-customers-pagination-list]');
        const perPage = state.perPage;
        const totalPages = Math.max(1, Math.ceil(total / perPage));
        if (state.page > totalPages) state.page = totalPages;
        if (state.page < 1) state.page = 1;

        const from = total === 0 ? 0 : (state.page - 1) * perPage + 1;
        const to   = Math.min(total, state.page * perPage);
        info.textContent = 'Showing ' + from + ' to ' + to + ' of ' + total + ' customers';

        const buttons = [];
        buttons.push('<li><button type="button" class="abo-admin-customers__page-btn" aria-label="Previous page"' + (state.page <= 1 ? ' disabled' : '') + ' data-abo-admin-customers-page="prev">‹</button></li>');
        for (let p = 1; p <= totalPages; p++) {
            buttons.push('<li><button type="button" class="abo-admin-customers__page-btn' + (p === state.page ? ' is-active' : '') + '" aria-label="Page ' + p + '" data-abo-admin-customers-page="' + p + '">' + p + '</button></li>');
        }
        buttons.push('<li><button type="button" class="abo-admin-customers__page-btn" aria-label="Next page"' + (state.page >= totalPages ? ' disabled' : '') + ' data-abo-admin-customers-page="next">›</button></li>');
        list.innerHTML = buttons.join('');

        const countEl = $('[data-abo-admin-customers-result-count]');
        if (countEl) countEl.textContent = total + ' ' + (total === 1 ? 'customer' : 'customers');
    }

    function renderState(total) {
        $$('[data-abo-admin-customers-state]').forEach((el) => {
            const v = el.getAttribute('data-abo-admin-customers-state');
            if (v === 'loading') return; // never auto-show
            el.hidden = true;
        });
        if (state.customers.length === 0) {
            $('[data-abo-admin-customers-state="empty"]').hidden = false;
            return;
        }
        if (total === 0) {
            $('[data-abo-admin-customers-state="noresults"]').hidden = false;
        }
    }

    // ─── Clear-filters button visibility ─────────────────────

    function renderClearVisibility() {
        const f = state.filter;
        const any = !!f.search || !!f.governorate || f.tab !== 'all' || f.sort !== 'newest';
        $$('[data-abo-admin-customers-clear]').forEach((b) => { b.hidden = !any; });
    }

    // ─── Single render entry point ───────────────────────────

    function render() {
        const { list, slice, total } = currentPageSlice();
        renderTable(slice);
        renderCards(slice);
        renderPagination(total);
        renderSortHeaders();
        renderState(total);
        renderClearVisibility();
        // Drawer needs to keep up if the row inside it disappears.
        if (state.drawer.open && !list.find((c) => c.id === state.drawer.id)) {
            closeDrawer();
        }
    }

    // ─── Listeners: tabs ──────────────────────────────────────

    $$('[data-abo-admin-customers-tab]').forEach((btn) => {
        btn.addEventListener('click', () => {
            state.filter.tab = btn.getAttribute('data-abo-admin-customers-tab');
            state.page = 1;
            $$('[data-abo-admin-customers-tab]').forEach((b) => {
                const isActive = b === btn;
                b.classList.toggle('is-active', isActive);
                b.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });
            render();
        });
    });

    // ─── Listeners: toolbar ───────────────────────────────────

    const searchInput  = $('[data-abo-admin-customers-search]');
    const govSel       = $('[data-abo-admin-customers-filter="governorate"]');
    const sortSel      = $('[data-abo-admin-customers-sort]');

    if (searchInput) {
        searchInput.addEventListener('input', () => {
            state.filter.search = searchInput.value;
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
    if (sortSel) {
        sortSel.addEventListener('change', () => {
            state.filter.sort = sortSel.value;
            state.page = 1;
            render();
        });
    }
    $$('[data-abo-admin-customers-clear]').forEach((b) => {
        b.addEventListener('click', () => {
            state.filter = { tab: 'all', search: '', governorate: '', sort: 'newest' };
            if (searchInput) searchInput.value = '';
            if (govSel)      govSel.value      = '';
            if (sortSel)     sortSel.value     = 'newest';
            state.page = 1;
            $$('[data-abo-admin-customers-tab]').forEach((tb) => {
                const isActive = tb.getAttribute('data-abo-admin-customers-tab') === 'all';
                tb.classList.toggle('is-active', isActive);
                tb.setAttribute('aria-selected', isActive ? 'true' : 'false');
            });
            render();
        });
    });

    // ─── Listeners: sortable headers ──────────────────────────

    $$('[data-abo-admin-customers-sort-col]').forEach((th) => {
        th.addEventListener('click', () => {
            const col = th.getAttribute('data-abo-admin-customers-sort-col');
            // Click a header to jump to the matching sort, and
            // also sync the toolbar select.
            if (col === 'orders') {
                state.filter.sort = (state.filter.sort === 'orders-desc') ? 'name-asc' : 'orders-desc';
            } else if (col === 'spend') {
                state.filter.sort = (state.filter.sort === 'spend-desc') ? 'name-asc' : 'spend-desc';
            }
            if (sortSel) sortSel.value = state.filter.sort;
            render();
        });
        th.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                th.click();
            }
        });
    });

    // ─── Listeners: row open / view ───────────────────────────

    document.addEventListener('click', (e) => {
        const target = e.target;

        const viewBtn = target.closest('[data-abo-admin-customers-view]');
        if (viewBtn) {
            e.preventDefault();
            e.stopPropagation();
            const id = viewBtn.getAttribute('data-abo-admin-customers-view')
                     || viewBtn.closest('[data-abo-admin-customers-id]')?.getAttribute('data-abo-admin-customers-id');
            if (id) openDrawer(id, viewBtn);
            return;
        }

        const row = target.closest('tr.abo-admin-customers__row');
        if (row && !target.matches('input, button, a, label, [role="button"]')) {
            openDrawer(row.getAttribute('data-abo-admin-customers-id'), row);
            return;
        }

        const card = target.closest('.abo-admin-customers__card');
        if (card && !target.matches('input, button, a, label, [role="button"]')) {
            openDrawer(card.getAttribute('data-abo-admin-customers-id'), card);
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            const row = e.target.closest && e.target.closest('tr.abo-admin-customers__row');
            if (row) {
                e.preventDefault();
                openDrawer(row.getAttribute('data-abo-admin-customers-id'), row);
            }
        }
    });

    // ─── Drawer (slide-over) ──────────────────────────────────

    const drawer = $('[data-abo-admin-customers-drawer]');
    const panel  = drawer ? drawer.querySelector('.abo-admin-customers__drawer-panel') : null;

    function findCustomer(id) { return state.customers.find((c) => c.id === id); }

    function renderAddresses(c) {
        const ul = $('[data-abo-admin-customers-drawer-addresses]');
        if (!c.addresses || c.addresses.length === 0) {
            ul.innerHTML = '<li class="abo-admin-customers__address"><span class="abo-admin-customers__address-label">—</span><span class="abo-admin-customers__address-text">No saved address.</span></li>';
            return;
        }
        ul.innerHTML = c.addresses.map((a) => {
            const place = [a.area, a.governorate].filter(Boolean).join(', ');
            return '<li class="abo-admin-customers__address">' +
                (a.isDefault ? '<span class="abo-admin-customers__address-default">Default</span>' : '') +
                '<span class="abo-admin-customers__address-label">' + escapeHTML(a.label) + '</span>' +
                '<span class="abo-admin-customers__address-text">' +
                    escapeHTML(a.name) +
                    (a.phone ? ' \u00b7 ' + escapeHTML(a.phone) : '') + '<br>' +
                    escapeHTML(a.address) +
                    (place ? '<br>' + escapeHTML(place) : '') +
                '</span>' +
            '</li>';
        }).join('');
    }

    /**
     * The orders screen, filtered down to one customer.
     */
    function ordersUrlFor(customerId) {
        const base = ROUTES.orders || '/admin/orders';
        return base + '?customer=' + encodeURIComponent(customerId);
    }

    function renderOrderHistory(c) {
        const ul  = $('[data-abo-admin-customers-drawer-orders-list]');
        const emp = $('[data-abo-admin-customers-drawer-orders-empty]');
        if (!c.recentOrders || c.recentOrders.length === 0) {
            ul.innerHTML = '';
            emp.hidden = false;
            return;
        }
        emp.hidden = true;
        ul.innerHTML = c.recentOrders.map((o) => {
            return '<li class="abo-admin-customers__order-row">' +
                '<span class="abo-admin-customers__order-num">' + escapeHTML(o.number) + '</span>' +
                '<span class="abo-admin-customers__order-date">' + escapeHTML(fmtDate(o.date)) + '</span>' +
                orderStatusBadgeHTML(o.status) +
                '<span class="abo-admin-customers__order-total">' + fmtEGP(o.total) + '</span>' +
                '<a href="' + escapeHTML(ordersUrlFor(c.id)) + '" class="abo-admin-customers__order-view">View</a>' +
            '</li>';
        }).join('');
    }

    function renderTags(c) {
        const ul = $('[data-abo-admin-customers-drawer-tags]');
        if (!c.tags || c.tags.length === 0) {
            ul.innerHTML = '';
            return;
        }
        ul.innerHTML = c.tags.map((t) => {
            return '<li class="abo-admin-customers__tag">' +
                '<span>' + escapeHTML(t) + '</span>' +
                '<button type="button" class="abo-admin-customers__tag-remove" aria-label="Remove tag ' + escapeHTML(t) + '" data-abo-admin-customers-tag-remove="' + escapeHTML(t) + '">' +
                    '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
                        '<path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linecap="round"/>' +
                    '</svg>' +
                '</button>' +
            '</li>';
        }).join('');
    }

    function renderDrawer(id) {
        const c = findCustomer(id);
        if (!c) return closeDrawer();
        const seg = segmentOf(c);

        $('[data-abo-admin-customers-drawer-avatar]').textContent  = c.initials;
        $('[data-abo-admin-customers-drawer-name]').textContent   = c.name;
        $('[data-abo-admin-customers-drawer-since]').textContent  = 'Customer since ' + fmtDate(c.signupDate);
        $('[data-abo-admin-customers-drawer-badge]').innerHTML    = segmentBadgeHTML(c);

        const phoneLink = $('[data-abo-admin-customers-drawer-phone]');
        phoneLink.textContent = c.phone;
        phoneLink.setAttribute('href', 'tel:' + c.phone.replace(/\s/g, ''));
        const emailLink = $('[data-abo-admin-customers-drawer-email]');
        emailLink.textContent = c.email;
        emailLink.setAttribute('href', 'mailto:' + c.email);

        $('[data-abo-admin-customers-drawer-promotions]').textContent =
            c.promotions ? 'Opted in' : 'Opted out';

        $('[data-abo-admin-customers-drawer-orders]').textContent = c.ordersCount;
        $('[data-abo-admin-customers-drawer-spent]').textContent  = fmtEGP(c.totalSpent);
        const avg = c.ordersCount > 0 ? Math.round(c.totalSpent / c.ordersCount) : 0;
        $('[data-abo-admin-customers-drawer-avg]').textContent    = avg > 0 ? fmtEGP(avg) : '—';

        renderAddresses(c);
        renderOrderHistory(c);
        renderTags(c);

        // Internal note — load the customer's saved note on every
        // open (in real life this would be fetched from the API).
        const noteInput = $('[data-abo-admin-customers-drawer-note-input]');
        if (noteInput) noteInput.value = c.note || '';

        // Block / Unblock button — label + variant depends on the
        // current blocked state.
        const blockBtn  = $('[data-abo-admin-customers-drawer-block]');
        const blockLbl  = $('[data-abo-admin-customers-drawer-block-label]');
        if (c.blocked) {
            blockLbl.textContent = 'UNBLOCK CUSTOMER';
            blockBtn.classList.add('is-unblock');
        } else {
            blockLbl.textContent = 'BLOCK CUSTOMER';
            blockBtn.classList.remove('is-unblock');
        }

        // Position indicator on the footer (filtered slice).
        const list = filteredList();
        const idx  = list.findIndex((x) => x.id === c.id);
        $('[data-abo-admin-customers-drawer-pos]').textContent =
            list.length > 0 ? ('Customer ' + (idx + 1) + ' of ' + list.length) : '—';
        $('[data-abo-admin-customers-drawer-prev]').disabled = (idx <= 0);
        $('[data-abo-admin-customers-drawer-next]').disabled = (idx >= list.length - 1);
    }

    /**
     * Write the tags and the note of one customer.
     *
     * The screen never keeps a change it could not save: the local copy
     * is only updated once the server has answered, so what is drawn
     * is always what is stored.
     */
    async function saveCustomer(c, changes) {
        const payload = await request('PUT', urlFor('update', c.id), {
            tags: c.tags || [],
            note: c.note || '',
            ...changes,
        });

        if (Array.isArray(payload.tags)) c.tags = payload.tags;
        if (typeof payload.note === 'string') c.note = payload.note;

        renderTags(c);
        return payload;
    }

    /**
     * Take the server's word for what a customer now looks like.
     *
     * The segment is one of the things the server owns: when a
     * customer is blocked they move into the blocked segment, and that
     * is the server's decision to make, not the browser's.
     */
    function applyServerState(c, payload) {
        if (typeof payload.segment === 'string') c.segment = payload.segment;
        if (typeof payload.blocked === 'boolean') c.blocked = payload.blocked;
        if (payload.blockReason !== undefined) c.blockReason = payload.blockReason;
    }

    /**
     * Save a new set of tags for one customer.
     */
    async function persistTags(c, tags) {
        try {
            await saveCustomer(c, { tags: tags });
            toast('Customer updated.');
        } catch (err) {
            toast(err.message);
        }
    }

    function updateSegmentBadgeInList(c) {
        $$('[data-abo-admin-customers-segment-cell="' + c.id + '"]').forEach((el) => {
            el.innerHTML = segmentBadgeHTML(c);
        });
    }

    // Focus trap inside the drawer.
    const FOCUSABLE = 'a[href], area[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    function openDrawer(id, fromEl) {
        const c = findCustomer(id);
        if (!c) return;
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
        $$('[data-abo-admin-customers-drawer-close]', drawer).forEach((el) => {
            el.addEventListener('click', closeDrawer);
        });
        drawer.querySelector('.abo-admin-customers__drawer-overlay')
            .addEventListener('click', closeDrawer);

        // Save the tags and the internal note.
        const noteSave = $('[data-abo-admin-customers-drawer-note-save]', drawer);
        if (noteSave) {
            noteSave.addEventListener('click', async () => {
                const c = findCustomer(state.drawer.id);
                if (!c) return;
                const inp = $('[data-abo-admin-customers-drawer-note-input]', drawer);
                noteSave.disabled = true;
                try {
                    await saveCustomer(c, { note: (inp.value || '').trim() });
                    toast('Customer updated.');
                } catch (err) {
                    toast(err.message);
                } finally {
                    noteSave.disabled = false;
                }
            });
        }

        // Tags
        const tagForm = $('[data-abo-admin-customers-tag-form]', drawer);
        const tagInp  = $('[data-abo-admin-customers-tag-input]', drawer);
        if (tagInp) {
            tagInp.addEventListener('keydown', async (e) => {
                if (e.key !== 'Enter') return;
                e.preventDefault();
                const c = findCustomer(state.drawer.id);
                if (!c) return;
                const v = tagInp.value.trim();
                if (!v) return;
                if (!c.tags) c.tags = [];
                if (c.tags.indexOf(v) !== -1) { tagInp.value = ''; return; }
                const tags = c.tags.concat([v]);
                tagInp.value = '';
                await persistTags(c, tags);
            });
        }
        if (tagForm) {
            tagForm.addEventListener('submit', (e) => {
                e.preventDefault();
                if (tagInp) tagInp.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }));
            });
        }
        $('[data-abo-admin-customers-drawer-tags]', drawer).addEventListener('click', async (e) => {
            const rm = e.target.closest('[data-abo-admin-customers-tag-remove]');
            if (!rm) return;
            const t = rm.getAttribute('data-abo-admin-customers-tag-remove');
            const c = findCustomer(state.drawer.id);
            if (!c || !c.tags) return;
            const tags = c.tags.filter((x) => x !== t);
            await persistTags(c, tags);
        });

        // Block / Unblock (opens the confirm modal)
        const blockBtn = $('[data-abo-admin-customers-drawer-block]', drawer);
        if (blockBtn) {
            blockBtn.addEventListener('click', () => {
                const c = findCustomer(state.drawer.id);
                if (!c) return;
                state.blockTarget = c.id;
                const modal = $('[data-abo-admin-customers-modal]');
                const title = modal.querySelector('.abo-admin-customers__modal-title');
                const body  = $('[data-abo-admin-customers-modal-body]', modal);
                const conf  = $('[data-abo-admin-customers-modal-confirm]', modal);
                const reasonField = modal.querySelector('[data-abo-admin-customers-modal-reason-field]');
                const reasonInput = modal.querySelector('[data-abo-admin-customers-modal-reason]');
                if (reasonInput) reasonInput.value = '';

                if (c.blocked) {
                    title.textContent = 'UNBLOCK CUSTOMER?';
                    body.textContent  = c.name + ' will be able to place new orders again.';
                    conf.textContent  = 'UNBLOCK CUSTOMER';
                    if (reasonField) reasonField.hidden = true;
                } else {
                    title.textContent = 'BLOCK CUSTOMER?';
                    body.textContent  = c.name + " won't be able to place new orders until unblocked.";
                    conf.textContent  = 'BLOCK CUSTOMER';
                    if (reasonField) reasonField.hidden = false;
                }
                modal.hidden = false;
                requestAnimationFrame(() => {
                    modal.querySelector('[data-abo-admin-customers-modal-close]').focus();
                });
            });
        }

        // Prev / next
        $('[data-abo-admin-customers-drawer-prev]', drawer)
            .addEventListener('click', () => moveDrawer(-1));
        $('[data-abo-admin-customers-drawer-next]', drawer)
            .addEventListener('click', () => moveDrawer(1));
    }

    function moveDrawer(delta) {
        const list = filteredList();
        const idx  = list.findIndex((c) => c.id === state.drawer.id);
        const next = list[idx + delta];
        if (!next) return;
        state.drawer.id = next.id;
        renderDrawer(next.id);
    }

    // Keyboard: Esc + focus trap
    document.addEventListener('keydown', (e) => {
        const modal = $('[data-abo-admin-customers-modal]');
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

    // ─── Modal (block / unblock) ──────────────────────────────

    function closeModal() {
        const modal = $('[data-abo-admin-customers-modal]');
        if (!modal) return;
        modal.hidden = true;
        state.blockTarget = null;
        const blockBtn = $('[data-abo-admin-customers-drawer-block]');
        if (blockBtn) blockBtn.focus();
    }

    const modal = $('[data-abo-admin-customers-modal]');
    if (modal) {
        $$('[data-abo-admin-customers-modal-close]', modal).forEach((el) => {
            el.addEventListener('click', closeModal);
        });
        $('[data-abo-admin-customers-modal-confirm]', modal).addEventListener('click', async (e) => {
            const c = findCustomer(state.blockTarget);
            if (!c) { closeModal(); return; }

            const blocked = !c.blocked;
            const reasonEl = $('[data-abo-admin-customers-modal-reason]', modal);
            const reason = reasonEl ? (reasonEl.value || '').trim() : '';

            if (blocked && !reason) {
                toast('Say why the account is being blocked.');
                if (reasonEl) reasonEl.focus();
                return;
            }

            const confirmBtn = e.currentTarget;
            confirmBtn.disabled = true;
            try {
                const payload = await request('PATCH', urlFor('block', c.id), {
                    blocked: blocked,
                    reason: reason,
                });
                applyServerState(c, payload);
                // Keep the table badge + tab counts in sync.
                updateSegmentBadgeInList(c);
                renderTabCounts();
                // Re-render the table on the current page so a row
                // that should now move between tabs is refreshed.
                render();
                if (state.drawer.open && state.drawer.id === c.id) renderDrawer(c.id);
                toast(blocked ? 'Customer blocked.' : 'Customer unblocked.');
                closeModal();
            } catch (err) {
                toast(err.message);
            } finally {
                confirmBtn.disabled = false;
            }
        });
    }

    // ─── Today-date label (matches dashboard + orders) ────────

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
