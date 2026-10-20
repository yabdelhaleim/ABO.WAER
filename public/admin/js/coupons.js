/*
 * ABO Admin · Coupons — page-local script (front-end only).
 *
 * Pure DOM behaviour for the static coupons demo:
 *   - renders the list from the COUPONS static array below
 *   - status tabs (All / Active / Scheduled / Expired / Disabled)
 *   - search + type filters
 *   - 28x14 active toggle on each row (updates badge in the DOM)
 *   - clicking a row selects it + loads it into the edit panel
 *   - arrow keys / Enter / Space to navigate the list / toggle
 *   - full edit panel (code / type / value / caps / minimum / usage
 *     limits / dates / applies-to / switches / dirty dot / live
 *     summary / save / duplicate / delete with confirm modal)
 *   - slide-over on tablet/mobile, toast, delete modal
 *   - status is derived from today (2026-10-16), the dates, the
 *     usage and the active toggle
 *
 * No fetch / XHR / localStorage. The only persistence is the
 * optional `state` object that holds transient UI (selected id,
 * active tab, dirty flag, ...). It is reset on every page load.
 *
 * Markup anchor points (every selector uses data-* attributes):
 *   [data-abo-admin-coupons]                       — page root (layout)
 *   [data-abo-admin-coupons-list]                  — <ul role="listbox">
 *   [data-abo-admin-coupons-search]                — toolbar search input
 *   [data-abo-admin-coupons-type]                  — toolbar type select
 *   [data-abo-admin-coupons-tabs]                  — status tablist
 *   [data-abo-admin-coupons-tab="..."]             — each tab button
 *   [data-abo-admin-coupons-stats]                 — 3 stat cards
 *   [data-abo-admin-coupons-state="empty|noresults"]
 *   [data-abo-admin-coupons-skeleton]              — loading skeleton
 *   [data-abo-admin-coupons-panel]                 — right column edit panel
 *   [data-abo-admin-coupons-panel-close]           — slide-over close
 *   [data-abo-admin-coupons-modal]                 — delete modal root
 *   [data-abo-admin-coupons-toast]                 — single shared toast
 *
 * No external dependencies. Loaded only on /admin/coupons.
 */

(function () {
    'use strict';

    /* ─── Static sample data ─────────────────────────────────
     *
     * TODO(backend): replace this array with real data fetched
     * server-side from the coupons table (joined with orders for
     * the redemptions / discount-given counters). The view shape
     * is final; only the data source changes.
     *
     * Sample "today" date used for status computation: 2026-10-16.
     *
     * Shape per coupon:
     *   id, code, type ('percentage'|'fixed'|'shipping'),
     *   value (number — % for percentage, EGP for fixed, 0 for shipping),
     *   cap (number|null)        — max discount cap (percentage only)
     *   minimum (number|null)    — minimum order in EGP
     *   usesMax (number|null)    — total usage limit (null = unlimited)
     *   usesPerCustomer (number|null)
     *   uses (number)            — current redemption count
     *   startsAt (ISO date|'')
     *   endsAt   (ISO date|'')
     *   scope ('all'|'categories'|'products')
     *   scopeIds (string[])
     *   firstOrderOnly (bool)
     *   excludeSale (bool)
     *   active (bool)
     *   discountGiven (number)   — total EGP this coupon has given
     *   categoryLabel (string)   — only for HOODIE15 (used in summary)
     */

    /** Static catalog used by the "Applies to" multi-select. */
    const CATEGORIES = [
        { id: 'hoodies',     name: 'Hoodies' },
        { id: 'pants',       name: 'Pants' },
        { id: 't-shirts',    name: 'T-Shirts' },
        { id: 'jackets',     name: 'Jackets' },
        { id: 'accessories', name: 'Accessories' },
    ];

    const PRODUCTS = [
        { id: 'p-1', name: 'Oversize hoodie' },
        { id: 'p-2', name: 'Cargo pants' },
        { id: 'p-3', name: 'Heavy tee' },
        { id: 'p-4', name: 'Linen overshirt' },
        { id: 'p-5', name: 'Bomber jacket' },
        { id: 'p-6', name: 'Beanie' },
    ];

    const COUPONS = window.__COUPONS_DATA__ ?? [];

    /* ─── Local UI state (never persisted) ───────────────── */

    const TODAY = '2026-10-16';

    const state = {
        selectedId: null,
        tab: 'all',
        filter: '',
        typeFilter: '',
        editing: null,        // snapshot used to drive the panel form
        dirty: false,
        deleteTarget: null,
    };

    /* ─── Tiny helpers ──────────────────────────────── */

    const $  = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
    const ce = (tag, props = {}, ...children) => {
        const el = document.createElement(tag);
        for (const [k, v] of Object.entries(props)) {
            if (k === 'class') el.className = v;
            else if (k === 'dataset') Object.assign(el.dataset, v);
            else if (k === 'style')   Object.assign(el.style, v);
            else if (k === 'html')    el.innerHTML = v;
            else if (k.startsWith('on') && typeof v === 'function') {
                el.addEventListener(k.slice(2).toLowerCase(), v);
            } else if (v === false || v == null) {
                /* skip */
            } else if (v === true) {
                el.setAttribute(k, '');
            } else {
                el.setAttribute(k, String(v));
            }
        }
        for (const c of children) {
            if (c == null || c === false) continue;
            el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        }
        return el;
    };

    const escapeHtml = (s = '') => String(s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

    const isMobile = () => window.matchMedia('(max-width: 1100px)').matches;

    /** Format a numeric EGP amount the admin way: "EGP 100". */
    const egp = (n) => 'EGP ' + Math.round(Number(n) || 0).toLocaleString('en-US');

    /** Format an integer with the admin tabular style (0 → "0", 34 → "34"). */
    const num = (n) => Math.round(Number(n) || 0).toLocaleString('en-US');

    /** Compare an ISO date string to a "today" string ('' means "no bound"). */
    const lt = (a, b) => (a || '') && (b || '') && a < b;
    const gt = (a, b) => (a || '') && (b || '') && a > b;

    /** Derive the displayed status from today + dates + usage + active flag. */
    const derivedStatus = (c) => {
        if (!c.active) return 'disabled';
        if (c.usesMax != null && c.uses >= c.usesMax) return 'limit';
        if (c.endsAt && lt(TODAY, c.endsAt) === false && c.endsAt <= TODAY
            && (c.startsAt || '') <= TODAY) return 'expired';
        if (c.endsAt && c.endsAt <= TODAY) return 'expired';
        if (c.startsAt && c.startsAt > TODAY) return 'scheduled';
        return 'active';
    };

    /** Human-friendly validity string. */
    const validityText = (c) => {
        const fmt = (iso) => {
            if (!iso) return '';
            const d = new Date(iso + 'T00:00:00');
            if (isNaN(d)) return iso;
            const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            return d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
        };
        if (!c.startsAt && !c.endsAt) return 'No end date';
        const start = c.startsAt ? fmt(c.startsAt) : '';
        const end   = c.endsAt   ? fmt(c.endsAt)   : '';
        if (c.startsAt && c.startsAt > TODAY) {
            return 'Starts ' + start;
        }
        if (c.endsAt && c.endsAt <= TODAY) {
            return 'Ended ' + end;
        }
        if (c.endsAt) return 'Until ' + end;
        return 'From ' + start;
    };

    /** Value displayed as either "20%", "EGP 100", or "Free shipping". */
    const valueText = (c) => {
        if (c.type === 'percentage') return (Math.round(c.value || 0)) + '%';
        if (c.type === 'fixed')      return egp(c.value);
        return 'Free shipping';
    };

    /** Usage text: "34 / 100 used" or "34 used" when unlimited. */
    const usageText = (c) => {
        if (c.usesMax == null) return num(c.uses) + ' used';
        return num(c.uses) + ' / ' + num(c.usesMax) + ' used';
    };

    /** Usage fill width: 0 to 100, capped at 100. */
    const usageFill = (c) => {
        if (!c.usesMax) return 0;
        return Math.min(100, Math.round((c.uses / c.usesMax) * 100));
    };

    /** Status badge class + label. */
    const statusMeta = (key) => {
        switch (key) {
            case 'active':    return { cls: 'abo-admin-coupons__status--active',    label: 'Active' };
            case 'scheduled': return { cls: 'abo-admin-coupons__status--scheduled', label: 'Scheduled' };
            case 'expired':   return { cls: 'abo-admin-coupons__status--expired',   label: 'Expired' };
            case 'disabled':  return { cls: 'abo-admin-coupons__status--disabled',  label: 'Disabled' };
            case 'limit':     return { cls: 'abo-admin-coupons__status--limit',     label: 'Limit reached' };
        }
        return { cls: '', label: '' };
    };

    /* ─── Toast (single shared instance) ─────────── */

    let toastTimer = null;
    const toastEls = () => ({
        wrap:  $('[data-abo-admin-coupons-toast]'),
        text:  $('[data-abo-admin-coupons-toast-text]'),
        dot:   $('[data-abo-admin-coupons-toast-dot]'),
    });
    const showToast = (message, tone = 'lime') => {
        const t = toastEls();
        if (!t.wrap) return;
        if (toastTimer) clearTimeout(toastTimer);
        t.text.textContent = message;
        if (t.dot) t.dot.className = 'abo-admin-coupons__toast-dot abo-admin-coupons__toast-dot--' + tone;
        t.wrap.hidden = false;
        toastTimer = setTimeout(() => { t.wrap.hidden = true; }, 2400);
    };

    /* ─── Root anchor ─────────────────────────────── */

    const root = $('[data-abo-admin-coupons]');
    if (!root) return;

    const listEl       = $('[data-abo-admin-coupons-list]');
    const skeletonEl   = $('[data-abo-admin-coupons-skeleton]');
    const stateEmpty   = $('[data-abo-admin-coupons-state="empty"]');
    const stateNoRes   = $('[data-abo-admin-coupons-state="noresults"]');
    const tabsRoot     = $('[data-abo-admin-coupons-tabs]');
    const tabsButtons  = tabsRoot ? $$('[data-abo-admin-coupons-tab]', tabsRoot) : [];
    const tabCountEls  = () => ({
        all:       $('[data-abo-admin-coupons-tab-count="all"]'),
        active:    $('[data-abo-admin-coupons-tab-count="active"]'),
        scheduled: $('[data-abo-admin-coupons-tab-count="scheduled"]'),
        expired:   $('[data-abo-admin-coupons-tab-count="expired"]'),
        disabled:  $('[data-abo-admin-coupons-tab-count="disabled"]'),
    });

    /* ─── Stat cards (Active / Redemptions / Discount) ── */

    const renderStats = () => {
        const els = {
            active:      $('[data-abo-admin-coupons-stat="active"]'),
            redemptions: $('[data-abo-admin-coupons-stat="redemptions"]'),
            discount:    $('[data-abo-admin-coupons-stat="discount"]'),
        };
        const active = COUPONS.filter((c) => derivedStatus(c) === 'active').length;
        const redemptions = COUPONS.reduce((sum, c) => sum + (c.uses || 0), 0);
        const discount = COUPONS.reduce((sum, c) => sum + (c.discountGiven || 0), 0);
        if (els.active)      els.active.textContent      = String(active);
        if (els.redemptions) els.redemptions.textContent = num(redemptions);
        if (els.discount)    els.discount.textContent    = egp(discount);
    };

    /* ─── Tab counts + active tab styling ──────────── */

    const renderTabCounts = () => {
        const counts = tabCountEls();
        const tally = { all: COUPONS.length, active: 0, scheduled: 0, expired: 0, disabled: 0 };
        for (const c of COUPONS) {
            const s = derivedStatus(c);
            // "Limit reached" buckets under "expired" in the list filter,
            // so the badge should follow the same rule. The user only sees
            // the two ends — past-its-end and hit-the-cap — as the same
            // kind of coupon.
            const bucket = s === 'limit' ? 'expired' : s;
            if (tally[bucket] != null) tally[bucket]++;
        }
        if (counts.all)       counts.all.textContent       = num(tally.all);
        if (counts.active)    counts.active.textContent    = num(tally.active);
        if (counts.scheduled) counts.scheduled.textContent = num(tally.scheduled);
        if (counts.expired)   counts.expired.textContent   = num(tally.expired);
        if (counts.disabled)  counts.disabled.textContent  = num(tally.disabled);
    };

    const setActiveTab = (tab) => {
        state.tab = tab;
        for (const btn of tabsButtons) {
            const active = btn.dataset.aboAdminCouponsTab === tab;
            btn.classList.toggle('is-active', active);
            btn.setAttribute('aria-selected', String(active));
        }
    };

    /* ─── List rendering ──────────────────────────── */

    const matchesFilters = (c) => {
        if (state.tab !== 'all') {
            const s = derivedStatus(c);
            if (s !== state.tab) {
                // "limit reached" buckets under "expired" for the tab
                // only on request — keep it simple: limit buckets
                // under "expired" because it's a passive state.
                if (!(state.tab === 'expired' && s === 'limit')) return false;
            }
        }
        if (state.typeFilter && c.type !== state.typeFilter) return false;
        if (state.filter) {
            const q = state.filter.toLowerCase();
            if (!c.code.toLowerCase().includes(q)) return false;
        }
        return true;
    };

    const buildRow = (c) => {
        const status = derivedStatus(c);
        const sm = statusMeta(status);
        const fill = usageFill(c);
        const fillReached = (c.usesMax != null && fill >= 100);
        const isActiveTab = state.tab === 'active';

        const li = ce('li', {
            role: 'option',
            'aria-selected': String(state.selectedId === c.id),
            tabindex: state.selectedId === c.id ? '0' : '-1',
            dataset: { id: c.id, aboAdminCouponsItem: '', status: status },
            class: [
                'abo-admin-coupons__row',
                state.selectedId === c.id ? 'is-selected' : '',
            ].filter(Boolean).join(' '),
        });

        // Code
        li.appendChild(ce('p', {
            class: 'abo-admin-coupons__code abo-admin-coupons__row-cell--code',
        }, c.code));

        // Type badge
        const typeLabel = (
            c.type === 'percentage' ? 'Percentage' :
            c.type === 'fixed'      ? 'Fixed' :
                                      'Free shipping'
        );
        li.appendChild(ce('span', {
            class: 'abo-admin-coupons__type-badge abo-admin-coupons__type-badge--' + c.type
                + ' abo-admin-coupons__row-cell--type',
            'aria-hidden': 'true',
        }, typeLabel));

        // Value
        li.appendChild(ce('p', {
            class: 'abo-admin-coupons__value abo-admin-coupons__row-cell--type',
        }, valueText(c)));

        // Usage (text + 2px flat line)
        const fillClass = fillReached
            ? 'abo-admin-coupons__usage-fill abo-admin-coupons__usage-fill--coral'
            : 'abo-admin-coupons__usage-fill';
        li.appendChild(ce('div', {
            class: 'abo-admin-coupons__usage abo-admin-coupons__row-cell--usage',
        },
            ce('p', { class: 'abo-admin-coupons__usage-text' }, usageText(c)),
            ce('div', {
                class: 'abo-admin-coupons__usage-bar',
                'aria-hidden': 'true',
                html: '<span class="' + fillClass + '" style="--w: ' + fill + '%;"></span>',
            }),
        ));

        // Validity
        li.appendChild(ce('p', {
            class: 'abo-admin-coupons__valid abo-admin-coupons__row-cell--valid',
        }, validityText(c)));

        // Status badge
        li.appendChild(ce('span', {
            class: 'abo-admin-coupons__status ' + sm.cls + ' abo-admin-coupons__row-cell--status',
        }, sm.label));

        // Row toggle
        const toggle = ce('label', {
            class: 'abo-admin-coupons__row-toggle abo-admin-coupons__row-cell--toggle',
            'aria-label': (c.active ? 'Deactivate ' : 'Activate ') + c.code,
        });
        const toggleInput = ce('input', {
            type: 'checkbox',
            'aria-checked': String(c.active),
            dataset: { aboAdminCouponsRowToggle: c.id },
        });
        toggleInput.checked = c.active;
        toggle.appendChild(toggleInput);
        toggle.appendChild(ce('span', { class: 'abo-admin-coupons__row-toggle-track' }));
        toggle.appendChild(ce('span', { class: 'abo-admin-coupons__row-toggle-thumb' }));
        li.appendChild(toggle);

        return li;
    };

    const renderList = () => {
        if (!listEl) return;

        // Global empty state (no coupons at all).
        if (COUPONS.length === 0) {
            listEl.hidden = true;
            if (stateEmpty) stateEmpty.hidden = false;
            if (stateNoRes) stateNoRes.hidden = true;
            if (skeletonEl) skeletonEl.hidden = true;
            updateResultsCount(0, 0);
            return;
        }

        const filtered = COUPONS.filter(matchesFilters);
        if (filtered.length === 0) {
            // No-results state.
            listEl.hidden = true;
            if (stateEmpty) stateEmpty.hidden = true;
            if (stateNoRes) stateNoRes.hidden = false;
        } else {
            listEl.hidden = false;
            if (stateEmpty) stateEmpty.hidden = true;
            if (stateNoRes) stateNoRes.hidden = true;
            listEl.innerHTML = '';
            for (const c of filtered) listEl.appendChild(buildRow(c));
        }
        if (skeletonEl) skeletonEl.hidden = true;

        const totalShown = filtered.length;
        const totalAll = COUPONS.length;
        updateResultsCount(totalShown, totalAll);
    };

    const updateResultsCount = (shown, total) => {
        const el = $('[data-abo-admin-coupons-results]');
        if (!el) return;
        if (state.tab === 'all' && !state.filter && !state.typeFilter) {
            el.textContent = '';
            return;
        }
        const head = state.tab !== 'all'
            ? state.tab.charAt(0).toUpperCase() + state.tab.slice(1) + ' — '
            : '';
        el.textContent = head + 'Showing ' + num(shown) + ' of ' + num(total);
    };

    /* ─── Results count + sync after edit ──────────── */

    const wireResultsCount = () => {
        // Live update when changes happen. The count is purely visual
        // and recomputed from COUPONS on each render.
    };

    /* ─── Row click / keyboard navigation / toggle ──── */

    const onRowActivate = (li) => {
        const id = li.dataset.id;
        if (!id) return;
        state.selectedId = id;
        const c = COUPONS.find((x) => x.id === id);
        if (!c) return;
        state.editing = snapshotFromCoupon(c);
        state.dirty = false;
        renderList();           // re-render to show lime border
        renderPanel();
        if (isMobile()) openPanel();
    };

    const onRowKeydown = (e, li) => {
        const visible = $$('[data-abo-admin-coupons-list] > [data-abo-admin-coupons-item]');
        const idx = visible.indexOf(li);
        if (idx === -1) return;

        switch (e.key) {
            case 'ArrowDown': {
                e.preventDefault();
                const next = visible[Math.min(idx + 1, visible.length - 1)];
                if (next) {
                    next.focus();
                    moveTabIndex(next, visible);
                }
                break;
            }
            case 'ArrowUp': {
                e.preventDefault();
                const prev = visible[Math.max(idx - 1, 0)];
                if (prev) {
                    prev.focus();
                    moveTabIndex(prev, visible);
                }
                break;
            }
            case 'Home': {
                e.preventDefault();
                if (visible[0]) {
                    visible[0].focus();
                    moveTabIndex(visible[0], visible);
                }
                break;
            }
            case 'End': {
                e.preventDefault();
                if (visible.length) {
                    visible[visible.length - 1].focus();
                    moveTabIndex(visible[visible.length - 1], visible);
                }
                break;
            }
            case 'Enter': {
                e.preventDefault();
                onRowActivate(li);
                break;
            }
            case ' ': {
                // Space toggles the active switch when it has focus.
                const t = li.querySelector('[data-abo-admin-coupons-row-toggle]');
                if (document.activeElement === t) {
                    e.preventDefault();
                    t.checked = !t.checked;
                    t.setAttribute('aria-checked', String(t.checked));
                    t.dispatchEvent(new Event('change', { bubbles: true }));
                } else {
                    e.preventDefault();
                    onRowActivate(li);
                }
                break;
            }
        }
    };

    const moveTabIndex = (focus, all) => {
        for (const li of all) li.tabIndex = -1;
        focus.tabIndex = 0;
    };

    /* ─── Wire the list once (delegated) ───────────── */

    const wireList = () => {
        if (!listEl) return;
        listEl.addEventListener('click', (e) => {
            const row = e.target.closest('[data-abo-admin-coupons-item]');
            if (!row) return;

            const t = e.target.closest('[data-abo-admin-coupons-row-toggle]');
            if (t) {
                e.stopPropagation();
                const id = t.dataset.aboAdminCouponsRowToggle;
                const c = COUPONS.find((x) => x.id === id);
                if (!c) return;
                c.active = t.checked;
                t.setAttribute('aria-checked', String(t.checked));
                // Re-render the row so the badge updates in the DOM
                // (Active ↔ Disabled).
                renderList();
                renderStats();
                renderTabCounts();
                // If the toggled row is also the one being edited,
                // sync the panel switch.
                if (state.editing && state.editing.id === id) {
                    state.editing.active = t.checked;
                    state.editing._status = derivedStatus(c);
                    renderPanel({ skipSyncEditing: true });
                }
                showToast(t.checked ? 'Activated (demo)' : 'Deactivated (demo)', 'lime');
                return;
            }

            onRowActivate(row);
        });

        listEl.addEventListener('keydown', (e) => {
            const row = e.target.closest('[data-abo-admin-coupons-item]');
            if (!row) return;
            onRowKeydown(e, row);
        });

        listEl.addEventListener('focusin', (e) => {
            const row = e.target.closest('[data-abo-admin-coupons-item]');
            if (!row) return;
            const all = $$('[data-abo-admin-coupons-list] > [data-abo-admin-coupons-item]');
            moveTabIndex(row, all);
        });
    };

    /* ─── Toolbar: search + type select ────────────── */

    const wireToolbar = () => {
        const search = $('[data-abo-admin-coupons-search]');
        if (search) {
            search.addEventListener('input', () => {
                state.filter = search.value.trim();
                renderList();
            });
        }
        const type = $('[data-abo-admin-coupons-type]');
        if (type) {
            type.addEventListener('change', () => {
                state.typeFilter = type.value || '';
                renderList();
            });
        }

        // Empty / no-results CTA buttons.
        const addEmpty = $('[data-abo-admin-coupons-add-empty]');
        if (addEmpty) {
            addEmpty.addEventListener('click', () => openNewCoupon());
        }
        const clearBtn = $('[data-abo-admin-coupons-clear-filters]');
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                state.filter = '';
                state.typeFilter = '';
                if (search) search.value = '';
                if (type)   type.value = '';
                setActiveTab('all');
                renderList();
            });
        }
    };

    /* ─── Tabs ──────────────────────────────────────── */

    const wireTabs = () => {
        if (!tabsRoot) return;
        for (const btn of tabsButtons) {
            btn.addEventListener('click', () => {
                const tab = btn.dataset.aboAdminCouponsTab;
                setActiveTab(tab);
                renderList();
            });
        }
    };

    /* ─── Snapshot / sync editing state to a coupon ── */

    const snapshotFromCoupon = (c) => {
        return {
            id: c.id,
            _isNew: false,
            code: c.code,
            type: c.type,
            value: c.value || 0,
            cap: c.cap == null ? '' : c.cap,
            minimum: c.minimum == null ? '' : c.minimum,
            usesMax: c.usesMax == null ? '' : c.usesMax,
            usesPerCustomer: c.usesPerCustomer == null ? '' : c.usesPerCustomer,
            uses: c.uses || 0,
            startsAt: c.startsAt || '',
            endsAt: c.endsAt || '',
            scope: c.scope || 'all',
            scopeIds: Array.from(c.scopeIds || []),
            firstOrderOnly: !!c.firstOrderOnly,
            excludeSale: !!c.excludeSale,
            active: !!c.active,
            _status: derivedStatus(c),
        };
    };

    const snapshotNew = () => ({
        id: '__new',
        _isNew: true,
        code: '',
        type: 'percentage',
        value: 10,
        cap: '',
        minimum: '',
        usesMax: '',
        usesPerCustomer: '',
        uses: 0,
        startsAt: '',
        endsAt: '',
        scope: 'all',
        scopeIds: [],
        firstOrderOnly: false,
        excludeSale: false,
        active: true,
        _status: 'active',
    });

    /* ─── Right panel (edit form) ──────────────────── */

    const panelEl        = $('[data-abo-admin-coupons-panel]');
    const panelCloseBtn  = $('[data-abo-admin-coupons-panel-close]');
    const panelOverlay   = $('[data-abo-admin-coupons-panel-overlay]');
    const panelTitleEl   = $('[data-abo-admin-coupons-panel-title-text]');
    const dirtyDotEl     = $('[data-abo-admin-coupons-dirty-dot]');
    const codeInput      = $('[data-abo-admin-coupons-code]');
    const codeErrorEl    = $('[data-abo-admin-coupons-code-error]');
    const generateBtn    = $('[data-abo-admin-coupons-generate]');
    const typeChips      = $$('[data-abo-admin-coupons-type-value]');
    const valueField     = $('[data-abo-admin-coupons-value-field]');
    const valueInput     = $('[data-abo-admin-coupons-value]');
    const valueSuffix    = $('[data-abo-admin-coupons-value-suffix]');
    const valueHint      = $('[data-abo-admin-coupons-value-hint]');
    const capField       = $('[data-abo-admin-coupons-cap-field]');
    const capInput       = $('[data-abo-admin-coupons-cap]');
    const minInput       = $('[data-abo-admin-coupons-minimum]');
    const usesTotalInput = $('[data-abo-admin-coupons-uses-total]');
    const usesCustInput  = $('[data-abo-admin-coupons-uses-customer]');
    const startsInput    = $('[data-abo-admin-coupons-starts]');
    const endsInput      = $('[data-abo-admin-coupons-ends]');
    const dateErrorEl    = $('[data-abo-admin-coupons-date-error]');
    const scopeChips     = $$('[data-abo-admin-coupons-scope]');
    const scopeList      = $('[data-abo-admin-coupons-scope-list]');
    const scopeCatsWrap  = $('[data-abo-admin-coupons-scope-categories]');
    const scopeProdWrap  = $('[data-abo-admin-coupons-scope-products]');
    const catsSelect     = $('[data-abo-admin-coupons-categories]');
    const prodSelect     = $('[data-abo-admin-coupons-products]');
    const selectedWrap   = $('[data-abo-admin-coupons-scope-selected]');
    const firstOrder     = $('[data-abo-admin-coupons-first-order]');
    const excludeSale    = $('[data-abo-admin-coupons-exclude-sale]');
    const activeSwitch   = $('[data-abo-admin-coupons-active]');
    const saveBtn        = $('[data-abo-admin-coupons-save]');
    const duplicateBtn   = $('[data-abo-admin-coupons-duplicate]');
    const deleteBtn      = $('[data-abo-admin-coupons-delete]');
    const deleteNoteEl   = $('[data-abo-admin-coupons-delete-note]');
    const summaryEl      = $('[data-abo-admin-coupons-summary]');

    const updateValueFieldVisibility = () => {
        if (!valueField || !state.editing) return;
        const t = state.editing.type;
        if (t === 'shipping') {
            valueField.hidden = true;
        } else {
            valueField.hidden = false;
            if (t === 'percentage') {
                if (valueSuffix) valueSuffix.textContent = '%';
                if (valueHint)   valueHint.textContent   = 'Percentage off the order total.';
                if (valueInput)  valueInput.max = '100';
            } else {
                if (valueSuffix) valueSuffix.textContent = 'EGP';
                if (valueHint)   valueHint.textContent   = 'Amount off, in Egyptian pounds.';
                if (valueInput)  valueInput.removeAttribute('max');
            }
        }
        if (capField) {
            capField.hidden = t !== 'percentage';
        }
    };

    const syncTypeChips = () => {
        if (!state.editing) return;
        for (const chip of typeChips) {
            const active = chip.dataset.aboAdminCouponsTypeValue === state.editing.type;
            chip.classList.toggle('is-active', active);
            chip.setAttribute('aria-checked', String(active));
        }
    };

    const syncScopeChips = () => {
        for (const chip of scopeChips) {
            const active = chip.dataset.aboAdminCouponsScope === state.editing.scope;
            chip.classList.toggle('is-active', active);
            chip.setAttribute('aria-checked', String(active));
        }
        // Show / hide the multi-select underneath.
        if (scopeList) {
            scopeList.hidden = state.editing.scope === 'all';
        }
        if (scopeCatsWrap) {
            scopeCatsWrap.hidden = state.editing.scope !== 'categories';
        }
        if (scopeProdWrap) {
            scopeProdWrap.hidden = state.editing.scope !== 'products';
        }
    };

    /** Build the chips below the multi-select for already-selected items. */
    const renderScopeSelected = () => {
        if (!selectedWrap || !state.editing) return;
        selectedWrap.innerHTML = '';
        const source = state.editing.scope === 'categories' ? CATEGORIES : PRODUCTS;
        for (const id of state.editing.scopeIds) {
            const item = source.find((x) => x.id === id);
            if (!item) continue;
            const chip = ce('div', {
                class: 'abo-admin-coupons__chip-selected',
            },
                ce('span', {}, item.name),
                ce('button', {
                    type: 'button',
                    class: 'abo-admin-coupons__chip-remove',
                    'aria-label': 'Remove ' + item.name,
                    dataset: { id: id, aboAdminCouponsScopeRemove: id },
                    html: '<svg viewBox="0 0 10 10" aria-hidden="true"><path d="M1 1l8 8M9 1l-8 8" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>',
                }),
            );
            selectedWrap.appendChild(chip);
        }
    };

    const populateScopeOptions = () => {
        if (catsSelect) {
            catsSelect.innerHTML = '';
            for (const c of CATEGORIES) {
                const opt = ce('option', { value: c.id }, c.name);
                catsSelect.appendChild(opt);
            }
        }
        if (prodSelect) {
            prodSelect.innerHTML = '';
            for (const p of PRODUCTS) {
                const opt = ce('option', { value: p.id }, p.name);
                prodSelect.appendChild(opt);
            }
        }
    };

    const syncScopeSelections = () => {
        if (catsSelect) {
            const sel = new Set(state.editing.scope === 'categories' ? state.editing.scopeIds : []);
            for (const opt of catsSelect.options) opt.selected = sel.has(opt.value);
        }
        if (prodSelect) {
            const sel = new Set(state.editing.scope === 'products' ? state.editing.scopeIds : []);
            for (const opt of prodSelect.options) opt.selected = sel.has(opt.value);
        }
        renderScopeSelected();
    };

    const renderPanel = (opts = {}) => {
        if (!panelEl) return;
        if (!state.editing) {
            panelEl.querySelector('[data-abo-admin-coupons-panel-form]').hidden = true;
            const empty = panelEl.querySelector('[data-abo-admin-coupons-panel-no-selection]');
            if (empty) empty.hidden = false;
            return;
        }
        const f = panelEl.querySelector('[data-abo-admin-coupons-panel-form]');
        const empty = panelEl.querySelector('[data-abo-admin-coupons-panel-no-selection]');
        if (empty) empty.hidden = true;
        if (f) f.hidden = false;

        const e = state.editing;

        if (panelTitleEl) {
            panelTitleEl.textContent = e._isNew ? 'NEW COUPON' : 'EDIT COUPON';
        }

        // Code field.
        if (codeInput) codeInput.value = e.code || '';
        if (codeErrorEl) codeErrorEl.hidden = true;

        // Type chips + value field visibility.
        syncTypeChips();
        updateValueFieldVisibility();

        // Value + cap.
        if (valueInput) valueInput.value = (e.value === '' || e.value == null) ? '' : e.value;
        if (capInput)   capInput.value   = (e.cap   === '' || e.cap   == null) ? '' : e.cap;
        if (minInput)   minInput.value   = (e.minimum === '' || e.minimum == null) ? '' : e.minimum;

        // Usage limits.
        if (usesTotalInput) usesTotalInput.value = (e.usesMax === '' || e.usesMax == null) ? '' : e.usesMax;
        if (usesCustInput)  usesCustInput.value  = (e.usesPerCustomer === '' || e.usesPerCustomer == null) ? '' : e.usesPerCustomer;

        // Dates.
        if (startsInput) startsInput.value = e.startsAt || '';
        if (endsInput)   endsInput.value   = e.endsAt   || '';
        if (dateErrorEl) dateErrorEl.hidden = true;

        // Scope chips + multi-select.
        syncScopeChips();
        syncScopeSelections();

        // Switches.
        if (firstOrder) {
            firstOrder.checked = !!e.firstOrderOnly;
            firstOrder.setAttribute('aria-checked', String(firstOrder.checked));
        }
        if (excludeSale) {
            excludeSale.checked = !!e.excludeSale;
            excludeSale.setAttribute('aria-checked', String(excludeSale.checked));
        }
        if (activeSwitch) {
            activeSwitch.checked = !!e.active;
            activeSwitch.setAttribute('aria-checked', String(activeSwitch.checked));
        }

        // Delete rules: disabled if used > 0.
        const used = Number(e.uses || 0);
        if (deleteBtn) {
            deleteBtn.disabled = used > 0;
        }
        if (deleteNoteEl) {
            if (used > 0) {
                deleteNoteEl.textContent = "Used coupons can't be deleted. Disable it instead.";
                deleteNoteEl.hidden = false;
            } else {
                deleteNoteEl.hidden = true;
            }
        }

        // Dirty dot.
        if (dirtyDotEl) dirtyDotEl.classList.toggle('is-visible', state.dirty);

        // Live summary.
        renderSummary();

        // Move dirty baseline if asked to skip sync (e.g. when the
        // row toggle updates the panel).
        void opts;
    };

    const renderSummary = () => {
        if (!summaryEl || !state.editing) return;
        const e = state.editing;
        const parts = [];
        if (e.type === 'percentage') {
            parts.push(Math.round(e.value || 0) + '% off');
        } else if (e.type === 'fixed') {
            parts.push(egp(e.value) + ' off');
        } else {
            parts.push('Free shipping');
        }
        if (e.type === 'percentage' && e.cap !== '' && e.cap != null && Number(e.cap) > 0) {
            parts.push('up to ' + egp(e.cap));
        }
        if (e.minimum !== '' && e.minimum != null && Number(e.minimum) > 0) {
            parts.push('on orders above ' + egp(e.minimum));
        }
        if (e.firstOrderOnly) parts.push('first order only');
        if (e.excludeSale)    parts.push('excludes sale items');
        if (e.usesPerCustomer !== '' && e.usesPerCustomer != null && Number(e.usesPerCustomer) > 0) {
            parts.push('once per customer');
        }
        if (e.endsAt) {
            const fmt = (iso) => {
                const d = new Date(iso + 'T00:00:00');
                const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                return d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
            };
            parts.push('until ' + fmt(e.endsAt));
        } else if (e.startsAt) {
            const fmt = (iso) => {
                const d = new Date(iso + 'T00:00:00');
                const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                return d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
            };
            parts.push('from ' + fmt(e.startsAt));
        }
        summaryEl.textContent = parts.join(', ') + '.';
    };

    /* ─── Code validation + Generate ────────────────── */

    const CODE_RE = /^[A-Z0-9-]{4,20}$/;
    const validateCode = (code) => {
        const v = (code || '').toUpperCase().replace(/[^A-Z0-9-]/g, '');
        return { ok: CODE_RE.test(v), value: v };
    };

    const wirePanel = () => {
        // Code input — uppercase forced, letters/digits/dashes only.
        if (codeInput) {
            codeInput.addEventListener('input', () => {
                const v = validateCode(codeInput.value);
                codeInput.value = v.value;
                if (state.editing) state.editing.code = v.value;
                if (codeErrorEl) codeErrorEl.hidden = v.ok || v.value === '';
                markDirty();
            });
        }

        // Generate button.
        if (generateBtn) {
            generateBtn.addEventListener('click', () => {
                const rand = () => 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'.charAt(Math.floor(Math.random() * 32));
                const code = 'SAVE-' + rand() + rand() + rand() + rand();
                if (codeInput) {
                    codeInput.value = code;
                    const v = validateCode(code);
                    codeInput.value = v.value;
                    if (state.editing) state.editing.code = v.value;
                    if (codeErrorEl) codeErrorEl.hidden = !v.ok;
                }
                markDirty();
            });
        }

        // Type radio chips.
        for (const chip of typeChips) {
            chip.addEventListener('click', () => {
                if (!state.editing) return;
                const v = chip.dataset.aboAdminCouponsTypeValue;
                if (v === state.editing.type) return;
                state.editing.type = v;
                if (v === 'shipping') state.editing.value = 0;
                syncTypeChips();
                updateValueFieldVisibility();
                renderSummary();
                markDirty();
            });
        }

        // Value field.
        if (valueInput) {
            valueInput.addEventListener('input', () => {
                if (!state.editing) return;
                const v = Number(valueInput.value);
                state.editing.value = isNaN(v) ? '' : v;
                renderSummary();
                markDirty();
            });
        }
        if (capInput) {
            capInput.addEventListener('input', () => {
                if (!state.editing) return;
                const v = Number(capInput.value);
                state.editing.cap = isNaN(v) ? '' : v;
                renderSummary();
                markDirty();
            });
        }
        if (minInput) {
            minInput.addEventListener('input', () => {
                if (!state.editing) return;
                const v = Number(minInput.value);
                state.editing.minimum = isNaN(v) ? '' : v;
                renderSummary();
                markDirty();
            });
        }
        if (usesTotalInput) {
            usesTotalInput.addEventListener('input', () => {
                if (!state.editing) return;
                const v = Number(usesTotalInput.value);
                state.editing.usesMax = isNaN(v) ? '' : v;
                markDirty();
            });
        }
        if (usesCustInput) {
            usesCustInput.addEventListener('input', () => {
                if (!state.editing) return;
                const v = Number(usesCustInput.value);
                state.editing.usesPerCustomer = isNaN(v) ? '' : v;
                renderSummary();
                markDirty();
            });
        }

        // Dates.
        const onDateChange = () => {
            if (!state.editing) return;
            state.editing.startsAt = startsInput ? startsInput.value : '';
            state.editing.endsAt   = endsInput   ? endsInput.value   : '';
            if (state.editing.startsAt && state.editing.endsAt
                && state.editing.endsAt <= state.editing.startsAt) {
                if (dateErrorEl) dateErrorEl.hidden = false;
            } else if (dateErrorEl) {
                dateErrorEl.hidden = true;
            }
            renderSummary();
            markDirty();
        };
        if (startsInput) startsInput.addEventListener('input', onDateChange);
        if (endsInput)   endsInput.addEventListener('input',   onDateChange);

        // Scope chips.
        for (const chip of scopeChips) {
            chip.addEventListener('click', () => {
                if (!state.editing) return;
                const v = chip.dataset.aboAdminCouponsScope;
                if (v === state.editing.scope) return;
                state.editing.scope = v;
                if (v === 'all') state.editing.scopeIds = [];
                syncScopeChips();
                syncScopeSelections();
                markDirty();
            });
        }

        // Multi-select change → sync back to scopeIds.
        if (catsSelect) {
            catsSelect.addEventListener('change', () => {
                if (!state.editing) return;
                if (state.editing.scope !== 'categories') return;
                state.editing.scopeIds = $$('option', catsSelect).filter((o) => o.selected).map((o) => o.value);
                renderScopeSelected();
                markDirty();
            });
        }
        if (prodSelect) {
            prodSelect.addEventListener('change', () => {
                if (!state.editing) return;
                if (state.editing.scope !== 'products') return;
                state.editing.scopeIds = $$('option', prodSelect).filter((o) => o.selected).map((o) => o.value);
                renderScopeSelected();
                markDirty();
            });
        }

        // Remove chip (delegated).
        if (selectedWrap) {
            selectedWrap.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-abo-admin-coupons-scope-remove]');
                if (!btn) return;
                const id = btn.dataset.id;
                if (!state.editing) return;
                state.editing.scopeIds = state.editing.scopeIds.filter((x) => x !== id);
                syncScopeSelections();
                markDirty();
            });
        }

        // Switches.
        if (firstOrder) {
            firstOrder.addEventListener('change', () => {
                if (!state.editing) return;
                state.editing.firstOrderOnly = firstOrder.checked;
                firstOrder.setAttribute('aria-checked', String(firstOrder.checked));
                renderSummary();
                markDirty();
            });
        }
        if (excludeSale) {
            excludeSale.addEventListener('change', () => {
                if (!state.editing) return;
                state.editing.excludeSale = excludeSale.checked;
                excludeSale.setAttribute('aria-checked', String(excludeSale.checked));
                renderSummary();
                markDirty();
            });
        }
        if (activeSwitch) {
            activeSwitch.addEventListener('change', () => {
                if (!state.editing) return;
                state.editing.active = activeSwitch.checked;
                activeSwitch.setAttribute('aria-checked', String(activeSwitch.checked));
                markDirty();
            });
        }

        // Save.
        if (saveBtn) {
            saveBtn.addEventListener('click', () => {
                if (!state.editing) return;
                const e = state.editing;
                const codeOk = validateCode(e.code).ok;
                if (!codeOk) {
                    if (codeErrorEl) codeErrorEl.hidden = false;
                    return;
                }
                const target = COUPONS.find((c) => c.id === e.id);
                if (target) {
                    Object.assign(target, {
                        code: e.code,
                        type: e.type,
                        value: e.type === 'shipping' ? 0 : Number(e.value || 0),
                        cap: e.type === 'percentage' && e.cap !== '' ? Number(e.cap) : null,
                        minimum: e.minimum === '' ? null : Number(e.minimum || 0),
                        usesMax: e.usesMax === '' ? null : Number(e.usesMax || 0),
                        usesPerCustomer: e.usesPerCustomer === '' ? null : Number(e.usesPerCustomer || 0),
                        startsAt: e.startsAt || '',
                        endsAt:   e.endsAt   || '',
                        scope: e.scope,
                        scopeIds: Array.from(e.scopeIds),
                        firstOrderOnly: !!e.firstOrderOnly,
                        excludeSale: !!e.excludeSale,
                        active: !!e.active,
                    });
                } else {
                    // New coupon.
                    const newId = 'cp-new-' + Date.now();
                    const newC = {
                        id: newId,
                        code: e.code,
                        type: e.type,
                        value: e.type === 'shipping' ? 0 : Number(e.value || 0),
                        cap: e.type === 'percentage' && e.cap !== '' ? Number(e.cap) : null,
                        minimum: e.minimum === '' ? null : Number(e.minimum || 0),
                        usesMax: e.usesMax === '' ? null : Number(e.usesMax || 0),
                        usesPerCustomer: e.usesPerCustomer === '' ? null : Number(e.usesPerCustomer || 0),
                        uses: 0,
                        startsAt: e.startsAt || '',
                        endsAt:   e.endsAt   || '',
                        scope: e.scope,
                        scopeIds: Array.from(e.scopeIds),
                        firstOrderOnly: !!e.firstOrderOnly,
                        excludeSale: !!e.excludeSale,
                        active: !!e.active,
                        discountGiven: 0,
                        categoryLabel: '',
                    };
                    COUPONS.unshift(newC);
                    e.id = newId;
                    e._isNew = false;
                    state.selectedId = newId;
                }
                state.dirty = false;
                if (dirtyDotEl) dirtyDotEl.classList.remove('is-visible');
                renderStats();
                renderTabCounts();
                renderList();
                renderPanel();
                showToast('Saved (demo)', 'lime');
            });
        }

        // Duplicate.
        if (duplicateBtn) {
            duplicateBtn.addEventListener('click', () => {
                if (!state.editing) return;
                const e = state.editing;
                const source = COUPONS.find((c) => c.id === e.id);
                if (!source) return;
                const dup = JSON.parse(JSON.stringify(source));
                dup.id = 'cp-new-' + Date.now();
                dup.code = (source.code || '') + '-COPY';
                dup.uses = 0;
                dup.discountGiven = 0;
                dup.active = false;
                COUPONS.unshift(dup);
                state.selectedId = dup.id;
                state.editing = snapshotFromCoupon(dup);
                state.dirty = false;
                renderStats();
                renderTabCounts();
                renderList();
                renderPanel();
                showToast('Duplicated (demo)', 'lime');
            });
        }

        // Delete (opens modal).
        if (deleteBtn) {
            deleteBtn.addEventListener('click', () => {
                if (deleteBtn.disabled) return;
                if (!state.editing) return;
                if (state.editing._isNew) return;
                openDeleteModal();
            });
        }

        // Slide-over close.
        if (panelCloseBtn) {
            panelCloseBtn.addEventListener('click', () => closePanel());
        }
        if (panelOverlay) {
            panelOverlay.addEventListener('click', () => closePanel());
        }
    };

    const markDirty = () => {
        if (!state.dirty) {
            state.dirty = true;
            if (dirtyDotEl) dirtyDotEl.classList.add('is-visible');
        }
    };

    /* ─── Slide-over open/close ────────────────────── */

    const openPanel = () => {
        if (!panelEl) return;
        if (isMobile()) {
            panelEl.classList.add('is-open');
            panelEl.setAttribute('aria-modal', 'true');
            if (panelCloseBtn) panelCloseBtn.focus();
            if (panelOverlay)   panelOverlay.hidden = false;
        }
    };

    const closePanel = () => {
        if (!panelEl) return;
        if (isMobile()) {
            panelEl.classList.remove('is-open');
            if (panelOverlay) panelOverlay.hidden = true;
        }
    };

    /* ─── Delete-confirm modal ──────────────────────── */

    const modalEl     = $('[data-abo-admin-coupons-modal]');
    const modalBody   = $('[data-abo-admin-coupons-modal-body]');
    const modalConfirm= $('[data-abo-admin-coupons-modal-confirm]');
    const modalCancel = $('[data-abo-admin-coupons-modal-cancel]');
    const modalCloseEls = () => $$('[data-abo-admin-coupons-modal-close]');

    const openDeleteModal = () => {
        if (!modalEl || !state.editing) return;
        const c = COUPONS.find((x) => x.id === state.editing.id);
        if (!c) return;
        if (modalBody) modalBody.textContent = 'Delete “' + c.code + '”? This action cannot be undone.';
        modalEl.hidden = false;
        modalEl.setAttribute('aria-modal', 'true');
        if (modalConfirm) modalConfirm.focus();
    };

    const closeDeleteModal = () => {
        if (!modalEl) return;
        modalEl.hidden = true;
    };

    const wireModal = () => {
        for (const el of modalCloseEls()) {
            el.addEventListener('click', closeDeleteModal);
        }
        if (modalCancel) modalCancel.addEventListener('click', closeDeleteModal);
        if (modalConfirm) modalConfirm.addEventListener('click', () => {
            if (!state.editing) return;
            const idx = COUPONS.findIndex((c) => c.id === state.editing.id);
            if (idx > -1) COUPONS.splice(idx, 1);
            state.selectedId = null;
            state.editing = null;
            state.dirty = false;
            closeDeleteModal();
            renderStats();
            renderTabCounts();
            renderList();
            renderPanel();
            showToast('Coupon deleted (demo)', 'coral');
        });
    };

    /* ─── Add-coupon button ────────────────────────── */

    const openNewCoupon = () => {
        state.selectedId = '__new';
        state.editing = snapshotNew();
        state.dirty = false;
        renderList();
        renderPanel();
        if (isMobile()) openPanel();
    };

    const addBtn = $('[data-abo-admin-coupons-add]');
    if (addBtn) {
        addBtn.addEventListener('click', openNewCoupon);
    }

    /* ─── Keyboard: Escape closes modal/slide-over ─── */

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if (modalEl && !modalEl.hidden) {
            closeDeleteModal();
            return;
        }
        if (panelEl && panelEl.classList.contains('is-open')) {
            closePanel();
        }
    });

    /* ─── Today's date line ────────────────────────── */

    const renderToday = () => {
        const el = $('#abo-admin-coupons-today-date');
        if (!el) return;
        // Static string — keeps the page completely deterministic
        // regardless of locale / clock.
        el.textContent = 'Today · 16 Oct 2026';
    };

    /* ─── Initial render after a 600ms skeleton delay ── */

    const skeletonDelayMs = 600;

    const init = () => {
        renderToday();
        populateScopeOptions();
        renderStats();
        renderTabCounts();
        renderList();
        wireList();
        wireToolbar();
        wireTabs();
        wirePanel();
        wireModal();
        if (isMobile() && panelEl) panelEl.classList.remove('is-open');
    };

    setTimeout(init, skeletonDelayMs);

    // If the catalog is empty, skip the skeleton and render immediately.
    if (COUPONS.length === 0) {
        if (skeletonEl) skeletonEl.hidden = true;
        init();
    }

    // Resize → re-sync slide-over state.
    window.addEventListener('resize', () => {
        if (!panelEl) return;
        if (!isMobile()) {
            panelEl.classList.remove('is-open');
            if (panelOverlay) panelOverlay.hidden = true;
        }
    });

    // Silence lint about unused references that exist for parity
    // with categories.js / colors-sizes.js.
    void wireResultsCount;
})();
