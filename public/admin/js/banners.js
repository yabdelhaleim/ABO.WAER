/*
 * ABO Admin · Banners — page-local script.
 *
 * DOM behaviour for the admin banner manager:
 *   - renders the list per placement (hero / promo / announcement)
 *     from the server-supplied window.__BANNERS__ list
 *   - tab switching with live counts and today's date
 *   - status badges derived from the active toggle + schedule
 *     (Live / Scheduled / Expired / Hidden)
 *   - HTML5 drag-to-reorder inside a single tab (with a lime drop
 *     indicator) and Alt+ArrowUp / Alt+ArrowDown keyboard reorder
 *   - 28x14 active toggle per row (lime square) with text fallback
 *   - edit panel with all of: placement select, two image pickers
 *     (Desktop 16:7, Mobile 4:5), Alt / Title / Subtitle / Button
 *     fields with an English / Arabic switch, a Link to select with
 *     conditional secondary fields, a Look block (text colour /
 *     position / overlay as segmented controls), a Starts / Ends
 *     schedule pair, a live preview (Desktop / Mobile frames), and
 *     Save / Duplicate / Delete buttons
 *   - local image previews via URL.createObjectURL; the picked files
 *     are uploaded with the save
 *   - one shared delete-confirm modal + toast
 *
 * The database owns the list. Every change — save, duplicate, delete,
 * the on/off switch and the order — is a request to
 * Admin\BannerController, and the row on screen is only replaced once
 * the server has answered.
 *
 * Markup anchor points (every selector uses data-* attributes):
 *   [data-abo-admin-banners]                — page root
 *   [data-abo-admin-banners-tabs]           — placement tabs
 *   [data-abo-admin-banners-tab]            — one tab (value="hero|promo|announcement")
 *   [data-abo-admin-banners-tab-count]      — count badge inside a tab
 *   [data-abo-admin-banners-list]           — <ul role="listbox">
 *   [data-abo-admin-banners-counter]        — hero-only counter
 *   [data-abo-admin-banners-helper]         — helper text
 *   [data-abo-admin-banners-panel]          — right column edit panel
 *   [data-abo-admin-banners-panel-overlay]  — slide-over backdrop
 *   [data-abo-admin-banners-panel-close]    — slide-over close button
 *   [data-abo-admin-banners-modal]          — delete modal root
 *   [data-abo-admin-banners-toast]          — single shared toast
 *
 * No external dependencies. Loaded only on /admin/banners.
 */

(function () {
    'use strict';

    /* ─── Static sample data ─────────────────────────────────────
     *
     * TODO(backend): replace this array with real banner data
     * fetched server-side from a banners table grouped by placement.
     * The view shape is final; only the data source changes.
     *
     * Status (derived at render time, see `deriveStatus`):
     *   - Hidden    : active === false
     *   - Live      : active && no future start && no past end
     *   - Scheduled : active && starts in the future
     *   - Expired   : active && ends in the past
     */
    // Today's date — the schedule badges are counted against it.
    const TODAY = new Date().toISOString().slice(0, 10);

    // The link pickers list what the catalog actually holds. The
    // server sends both lists with the page; nothing here is invented.
    const CATEGORIES = window.__BANNER_CATEGORIES__ ?? [];
    const PRODUCTS = window.__BANNER_PRODUCTS__ ?? [];

    // The banner list is hydrated server-side from the banners table.
    // When the table is empty, the controller injects `[]` and the
    // page simply renders the empty state.
    //
    // The ids are strings: the DOM hands an id back as a string, and
    // a row is only the same row when the two say the same.
    const normalize = (banner) => ({ ...banner, id: String(banner.id) });

    const BANNERS = (window.__BANNERS__ ?? []).map(normalize);

    // The endpoints this page talks to.
    const ROUTES = window.__BANNER_ROUTES__ ?? {};


    /* ─── Local UI state ───────────────────────────────────────── */

    const PLACEMENTS = ['hero', 'promo', 'announcement'];

    const state = {
        placement: 'hero',          // active tab
        selectedId: null,
        filterIdsByPlacement: {     // ordered lists per placement
            hero: [],
            promo: [],
            announcement: [],
        },
        editing: null,              // working copy of the row being edited
        isNew: false,               // true when in "+ ADD BANNER" mode
        langTabs: { alt: 'en', title: 'en', subtitle: 'en' },
        imageObjectUrls: { desktop: null, mobile: null },
        imageFiles: { desktop: null, mobile: null },
        removedImages: { desktop: false, mobile: false },
        imageFiles: { desktop: null, mobile: null },
        dirty: false,
        devicePreview: 'desktop',   // 'desktop' | 'mobile'
        deleteTarget: null,
        busy: false,                // a request is in flight
    };

    /**
     * Group the banner list by placement, keeping the order the server
     * sent. The server owns the order — it is the order the storefront
     * shows — so the list and the shop never disagree.
     */
    const groupByPlacement = () => {
        const groups = { hero: [], promo: [], announcement: [] };

        for (const b of BANNERS) {
            const key = PLACEMENTS.includes(b.placement) ? b.placement : 'hero';
            groups[key].push(b.id);
        }

        return groups;
    };

    /* ─── Tiny helpers ─────────────────────────────────────────── */

    const $  = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    const escapeHtml = (s = '') => String(s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

    const isMobile = () => window.matchMedia('(max-width: 1100px)').matches;

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

    /* ─── Status helpers ───────────────────────────────────────── */

    const compareDate = (iso) => {
        if (!iso) return null;
        // Compare two ISO dates as integers to dodge TZ issues.
        return iso.replaceAll('-', '');
    };

    const todayInt = () => compareDate(TODAY);

    const deriveStatus = (banner) => {
        if (!banner.active) return 'hidden';
        const today = todayInt();
        if (banner.starts) {
            const sInt = compareDate(banner.starts);
            if (sInt && sInt > today) return 'scheduled';
        }
        if (banner.ends) {
            const eInt = compareDate(banner.ends);
            if (eInt && eInt < today) return 'expired';
        }
        return 'live';
    };

    const scheduleText = (banner) => {
        if (!banner.starts && !banner.ends) return 'Always on';
        const fmt = (iso) => {
            const d = new Date(iso + 'T00:00:00');
            return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
        };
        if (banner.starts && !banner.ends) return 'Starts ' + fmt(banner.starts);
        if (!banner.starts && banner.ends) {
            // "Until 31 Dec 2026"
            const d = new Date(banner.ends + 'T00:00:00');
            return 'Until ' + d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
        }
        return fmt(banner.starts) + ' – ' + fmt(banner.ends);
    };

    const statusLabel = (s) => ({
        live: 'Live',
        scheduled: 'Scheduled',
        expired: 'Expired',
        hidden: 'Hidden',
    })[s] || 'Hidden';

    /* ─── Toast (single shared instance) ───────────────────────── */

    let toastTimer = null;
    const toastEls = () => ({
        wrap: $('[data-abo-admin-banners-toast]'),
        text: $('[data-abo-admin-banners-toast-text]'),
        dot:  $('[data-abo-admin-banners-toast-dot]'),
    });
    const showToast = (message, tone = 'lime') => {
        const t = toastEls();
        if (!t.wrap) return;
        if (toastTimer) clearTimeout(toastTimer);
        t.text.textContent = message;
        if (t.dot) t.dot.className = 'abo-admin-banners__toast-dot abo-admin-banners__toast-dot--' + tone;
        t.wrap.hidden = false;
        toastTimer = setTimeout(() => { t.wrap.hidden = true; }, 2400);
    };

    /* ─── Server requests ──────────────────────────────────────── */

    const csrfToken = () => {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    };

    /** The endpoint for a banner, with the placeholder filled in. */
    const urlFor = (key, bannerId) => {
        const url = ROUTES[key] || '';
        return url.replace('__BANNER__', encodeURIComponent(bannerId));
    };

    /** The message the server sent, or a readable stand-in. */
    const errorMessage = (payload, status) => {
        if (payload && typeof payload.message === 'string' && payload.message.trim()) {
            return payload.message;
        }

        if (payload && payload.errors) {
            const first = Object.values(payload.errors)[0];
            if (Array.isArray(first) && first.length) return first[0];
        }

        return 'The server refused the change (HTTP ' + status + ').';
    };

    /**
     * Send a request to the banner endpoints.
     *
     * `body` is a plain object (sent as JSON) or a FormData (used when
     * an image travels with the fields).
     */
    const request = async (method, url, body) => {
        const headers = {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        };

        const options = { method, headers, credentials: 'same-origin' };

        if (typeof FormData !== 'undefined' && body instanceof FormData) {
            options.body = body;
        } else if (body !== undefined && body !== null) {
            headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body);
        }

        const response = await fetch(url, options);

        let payload = null;
        try {
            payload = await response.json();
        } catch (_) {
            payload = null;
        }

        if (!response.ok || (payload && payload.success === false)) {
            throw new Error(errorMessage(payload, response.status));
        }

        return payload || {};
    };

    /* ─── Render the list ──────────────────────────────────────── */

    const root = $('[data-abo-admin-banners]');
    if (!root) return;

    const listEl        = $('[data-abo-admin-banners-list]');
    const skeletonEl    = $('[data-abo-admin-banners-state="loading"]');
    const stateEmpty    = $('[data-abo-admin-banners-state="empty"]');
    const counterEl     = $('[data-abo-admin-banners-counter]');
    const tabsEl        = $('[data-abo-admin-banners-tabs]');
    const dateLabelEl   = $('#abo-admin-banners-today-date');

    if (dateLabelEl) {
        const d = new Date(TODAY + 'T00:00:00');
        dateLabelEl.textContent = 'Today · ' + d.toLocaleDateString('en-GB', {
            day: 'numeric', month: 'long', year: 'numeric',
        });
    }

    const bannersFor = (placement) => state.filterIdsByPlacement[placement]
        .map((id) => BANNERS.find((b) => b.id === id))
        .filter(Boolean);

    const getBanner = (id) => BANNERS.find((b) => String(b.id) === String(id));

    /* Build the schedule sub-line safely (text only). */
    const renderSchedule = (b) => {
        return ce('p', { class: 'abo-admin-banners__schedule' }, scheduleText(b));
    };

    const renderThumb = (b) => {
        const thumb = ce('div', {
            class: 'abo-admin-banners__thumb',
            'aria-hidden': 'true',
        });
        if (b.placement === 'announcement') {
            // Compact text-only icon for announcements.
            thumb.appendChild(ce('span', { class: 'abo-admin-banners__thumb-icon' }, '··'));
            return thumb;
        }
        if (b.desktopImage) {
            const img = ce('img', { src: b.desktopImage, alt: '' });
            thumb.appendChild(img);
        } else {
            thumb.appendChild(ce('span', { class: 'abo-admin-banners__thumb-icon' }, '··'));
        }
        return thumb;
    };

    const renderStatus = (b) => {
        const s = deriveStatus(b);
        return ce('span', {
            class: 'abo-admin-banners__status abo-admin-banners__status--' + s,
        }, statusLabel(s));
    };

    const renderRow = (b) => {
        const isAnnouncement = b.placement === 'announcement';
        const selected = state.selectedId === b.id;
        const li = ce('li', {
            role: 'option',
            'aria-selected': String(selected),
            tabindex: '0',
            dataset: { id: b.id, aboAdminBannersRow: '' },
            class: [
                'abo-admin-banners__row',
                selected ? 'is-selected' : '',
                isAnnouncement ? 'abo-admin-banners__row--announcement' : '',
            ].filter(Boolean).join(' '),
        });

        // drag handle (always before the thumb)
        const handle = ce('span', {
            class: 'abo-admin-banners__handle',
            'aria-label': 'Drag to reorder',
            role: 'button',
            tabindex: '0',
            dataset: { aboAdminBannersHandle: b.id },
            html: '<svg viewBox="0 0 6 10" aria-hidden="true"><circle cx="1.5" cy="1.5" r="1"/><circle cx="4.5" cy="1.5" r="1"/><circle cx="1.5" cy="5" r="1"/><circle cx="4.5" cy="5" r="1"/><circle cx="1.5" cy="8.5" r="1"/><circle cx="4.5" cy="8.5" r="1"/></svg>',
        });
        handle.draggable = true;
        li.appendChild(handle);

        // thumb
        li.appendChild(renderThumb(b));

        // meta (title + schedule)
        li.appendChild(ce('div', { class: 'abo-admin-banners__meta' },
            ce('p', { class: 'abo-admin-banners__name' }, b.title || 'Untitled banner'),
            renderSchedule(b),
        ));

        // status
        li.appendChild(renderStatus(b));

        // toggle (28x14 lime-on when active). aria-checked so AT users hear it.
        const toggleLabel = (b.active ? 'Deactivate' : 'Activate') + ' ' + (b.title || 'banner');
        const toggle = ce('label', {
            class: 'abo-admin-banners__toggle',
            'aria-label': toggleLabel,
        });
        const input = ce('input', {
            type: 'checkbox',
            'aria-checked': String(b.active),
            dataset: { aboAdminBannersToggle: b.id },
        });
        input.checked = b.active;
        toggle.appendChild(input);
        toggle.appendChild(ce('span', { class: 'abo-admin-banners__toggle-track' }));
        toggle.appendChild(ce('span', { class: 'abo-admin-banners__toggle-thumb' }));
        li.appendChild(toggle);

        return li;
    };

    const renderList = () => {
        // Hide skeleton first.
        const items = bannersFor(state.placement);

        // Tab counts (always reflect data)
        const countEls = {
            hero: $('[data-abo-admin-banners-tab-count="hero"]'),
            promo: $('[data-abo-admin-banners-tab-count="promo"]'),
            announcement: $('[data-abo-admin-banners-tab-count="announcement"]'),
        };
        for (const p of ['hero', 'promo', 'announcement']) {
            if (countEls[p]) countEls[p].textContent = state.filterIdsByPlacement[p].length;
        }

        // Wipe list
        listEl.replaceChildren();

        if (skeletonEl) skeletonEl.hidden = true;
        stateEmpty.hidden = true;

        if (items.length === 0) {
            stateEmpty.hidden = false;
            counterEl.hidden = true;
            return;
        }

        for (const b of items) {
            listEl.appendChild(renderRow(b));
        }

        // Hero-only counter
        if (state.placement === 'hero') {
            const liveCount = items.filter((b) => deriveStatus(b) === 'live').length;
            const total = items.length;
            const text = `${liveCount} of ${total} slides recommended`;
            counterEl.textContent = text;
            counterEl.hidden = false;
            counterEl.classList.toggle('abo-admin-banners__counter--warn', total > 5);
        } else {
            counterEl.hidden = true;
        }
    };

    /* ─── Tabs ─────────────────────────────────────────────────── */

    if (tabsEl) {
        tabsEl.addEventListener('click', (e) => {
            const tab = e.target.closest('[data-abo-admin-banners-tab]');
            if (!tab) return;
            const next = tab.dataset.aboAdminBannersTab;
            if (!next || next === state.placement) return;
            setPlacement(next);
        });
    }

    const setPlacement = (next) => {
        state.placement = next;
        $$('[data-abo-admin-banners-tab]').forEach((t) => {
            const active = t.dataset.aboAdminBannersTab === next;
            t.classList.toggle('is-active', active);
            t.setAttribute('aria-selected', String(active));
        });
        // If the currently selected banner lives in another placement,
        // keep it selected in the data but only load it when relevant.
        renderList();
        // The edit panel content adapts via the placement select when
        // a banner is shown; we don't auto-reselect a row here.
    };

    /* ─── Row clicks, keyboard, drag ───────────────────────────── */

    listEl.addEventListener('click', (e) => {
        // Toggle interaction — never trigger row select when the
        // toggle (label > input) is the click target.
        const tog = e.target.closest('[data-abo-admin-banners-toggle]');
        if (tog) return;

        const row = e.target.closest('[data-abo-admin-banners-row]');
        if (!row) return;
        selectRow(row.dataset.id);
    });

    listEl.addEventListener('keydown', (e) => {
        const row = e.target.closest('[data-abo-admin-banners-row]');
        if (!row) return;
        const id = row.dataset.id;

        // Alt+Arrow keys → reorder
        if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
            e.preventDefault();
            moveRow(id, e.key === 'ArrowUp' ? -1 : +1);
            return;
        }

        // Space on toggle → toggle activation
        if (e.key === ' ' && e.target.matches('[data-abo-admin-banners-toggle]')) {
            e.preventDefault();
            toggleActive(id);
            return;
        }

        // Arrow up/down → move focus between rows
        if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
            e.preventDefault();
            const rows = $$('[data-abo-admin-banners-row]');
            const idx = rows.indexOf(row);
            const nextIdx = e.key === 'ArrowUp' ? Math.max(0, idx - 1) : Math.min(rows.length - 1, idx + 1);
            if (rows[nextIdx]) {
                rows[nextIdx].focus();
                if (rows[nextIdx].dataset.id !== id) selectRow(rows[nextIdx].dataset.id, { scroll: false });
            }
            return;
        }

        // Enter → select
        if (e.key === 'Enter') {
            e.preventDefault();
            selectRow(id);
        }
    });

    listEl.addEventListener('change', async (e) => {
        const t = e.target;
        if (!t.matches('[data-abo-admin-banners-toggle]')) return;
        const id = t.dataset.aboAdminBannersToggle;
        const b = getBanner(id);
        if (!b) return;

        const next = t.checked;

        try {
            const result = await request('PUT', urlFor('update', id), { is_active: next });
            const saved = result.banner ? normalize(result.banner) : null;

            if (saved) {
                const idx = BANNERS.findIndex((x) => x.id === id);
                if (idx > -1) BANNERS[idx] = saved;
            } else {
                b.active = next;
            }

            renderList();

            // If the row is also the currently edited one, sync the switch.
            const current = getBanner(id);
            if (state.selectedId === id && state.editing && current) {
                state.editing.active = current.active;
                setSwitch('active', current.active);
            }

            showToast(next ? 'Banner turned on' : 'Banner hidden');
        } catch (err) {
            // The server refused — the row keeps the state it has there.
            renderList();
            showToast(err.message, 'coral');
        }
    });

    /* ─── Drag and drop ────────────────────────────────────────── */

    let dragId = null;

    listEl.addEventListener('dragstart', (e) => {
        const handle = e.target.closest('[data-abo-admin-banners-handle]');
        if (!handle) {
            e.preventDefault();
            return;
        }
        dragId = handle.dataset.aboAdminBannersHandle;
        const row = handle.closest('[data-abo-admin-banners-row]');
        if (row) row.classList.add('is-dragging');
        try { e.dataTransfer.setData('text/plain', dragId); } catch (_) { /* sandboxed */ }
        e.dataTransfer.effectAllowed = 'move';
    });

    listEl.addEventListener('dragend', () => {
        $$('[data-abo-admin-banners-row]').forEach((r) => {
            r.classList.remove('is-dragging', 'is-drop-before', 'is-drop-after');
        });
        dragId = null;
    });

    listEl.addEventListener('dragover', (e) => {
        const row = e.target.closest('[data-abo-admin-banners-row]');
        if (!row || !dragId || row.dataset.id === dragId) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        const rect = row.getBoundingClientRect();
        const before = e.clientY < rect.top + rect.height / 2;
        $$('[data-abo-admin-banners-row]').forEach((r) => {
            r.classList.remove('is-drop-before', 'is-drop-after');
        });
        row.classList.add(before ? 'is-drop-before' : 'is-drop-after');
    });

    listEl.addEventListener('drop', (e) => {
        const row = e.target.closest('[data-abo-admin-banners-row]');
        if (!row || !dragId || row.dataset.id === dragId) return;
        e.preventDefault();
        const before = row.classList.contains('is-drop-before');
        reorderWithin(state.placement, dragId, row.dataset.id, before);
        renderList();
        dragId = null;
        persistOrder(state.placement);
    });

    const reorderWithin = (placement, draggedId, targetId, before) => {
        const arr = state.filterIdsByPlacement[placement];
        const from = arr.indexOf(draggedId);
        if (from < 0) return;
        arr.splice(from, 1);
        let to = arr.indexOf(targetId);
        if (to < 0) to = arr.length;
        arr.splice(before ? to : to + 1, 0, draggedId);
    };

    /**
     * Write the order of a placement back to the server.
     *
     * The sort order is the position inside the placement, so the
     * storefront — which reads the banners of one placement — shows
     * them in the order the admin left them.
     */
    const persistOrder = async (placement) => {
        const ids = state.filterIdsByPlacement[placement] || [];

        const results = await Promise.allSettled(ids.map((id, index) => {
            const banner = getBanner(id);
            if (!banner) return Promise.resolve(null);

            return request('PUT', urlFor('update', id), { sort_order: index });
        }));

        let failure = null;

        for (const result of results) {
            if (result.status === 'rejected') {
                failure = result.reason;
                continue;
            }

            const saved = result.value && result.value.banner
                ? normalize(result.value.banner)
                : null;
            if (!saved) continue;

            const idx = BANNERS.findIndex((x) => x.id === saved.id);
            if (idx > -1) BANNERS[idx] = saved;
        }

        if (failure) {
            showToast(failure.message || 'The new order could not be saved.', 'coral');
            return;
        }

        showToast('Order updated');
    };

    const moveRow = (id, direction) => {
        const arr = state.filterIdsByPlacement[state.placement];
        const idx = arr.indexOf(id);
        const newIdx = idx + direction;
        if (newIdx < 0 || newIdx >= arr.length) return;
        [arr[idx], arr[newIdx]] = [arr[newIdx], arr[idx]];
        renderList();
        // Restore focus
        const newRow = listEl.querySelector(`[data-id="${CSS.escape(id)}"]`);
        if (newRow) newRow.focus();
        persistOrder(state.placement);
    };

    /* ─── Selection + edit panel open/close ───────────────────── */

    const selectRow = (id, opts = {}) => {
        const b = getBanner(id);
        if (!b) return;
        state.selectedId = id;
        state.isNew = false;
        // clone for editing so cancel can discard (we use Save to commit)
        state.editing = JSON.parse(JSON.stringify(b));
        // Clear any object URLs from previous edit session.
        releaseObjectUrls();
        state.imageFiles.desktop = null;
        state.imageFiles.mobile = null;
        state.removedImages.desktop = false;
        state.removedImages.mobile = false;
        renderList();
        openPanel();
        hydrateEditPanel();
        // Optional scroll the row into view (default true)
        if (opts.scroll !== false) {
            const row = listEl.querySelector(`[data-id="${CSS.escape(id)}"]`);
            if (row && row.scrollIntoView) row.scrollIntoView({ block: 'nearest' });
        }
    };

    /* ─── Add button ───────────────────────────────────────────── */

    const addEmptyBtn = $('[data-abo-admin-banners-add-empty]');
    const addMainBtn  = $('[data-abo-admin-banners-add]');
    const onAdd = () => {
        const placement = state.placement;
        // Make sure we're on the right tab (always true since the
        // button is in the header, but be defensive).
        setPlacement(placement);

        // The row joins the list once the server has it — until then it
        // only lives in the edit panel.
        state.isNew = true;
        state.selectedId = null;
        state.editing = {
            id: null,
            placement,
            active: false,            // starts hidden
            starts: null,
            ends: null,
            title: '',
            titleAr: '',
            subtitle: '',
            subtitleAr: '',
            buttonLabel: '',
            alt: '',
            altAr: '',
            desktopImage: null,
            mobileImage: null,
            textColor: 'light',
            textPosition: 'left',
            overlay: 'none',
            linkType: CATEGORIES.length ? 'category' : 'url',
            linkCategory: CATEGORIES.length ? CATEGORIES[0].slug : null,
            linkProduct: PRODUCTS.length ? PRODUCTS[0].slug : null,
            linkUrl: '',
        };
        state.imageFiles.desktop = null;
        state.imageFiles.mobile = null;
        state.removedImages.desktop = false;
        state.removedImages.mobile = false;

        renderList();
        openPanel();
        hydrateEditPanel();
    };
    if (addMainBtn)  addMainBtn.addEventListener('click', onAdd);
    if (addEmptyBtn) addEmptyBtn.addEventListener('click', onAdd);

    /* ─── Edit panel open / close ──────────────────────────────── */

    const panelEl       = $('[data-abo-admin-banners-panel]');
    const panelForm     = $('[data-abo-admin-banners-panel-form]');
    const panelEmpty    = $('[data-abo-admin-banners-panel-no-selection]');
    const panelOverlay  = $('[data-abo-admin-banners-panel-overlay]');
    const panelCloseBtn = $('[data-abo-admin-banners-panel-close]');
    const panelTitle    = $('[data-abo-admin-banners-panel-title-text]');

    const openPanel = () => {
        if (!panelEl) return;
        panelForm.hidden = false;
        panelEmpty.hidden = true;
        panelEl.classList.add('is-open');
        if (panelOverlay) panelOverlay.hidden = false;
    };

    const closePanel = () => {
        if (!panelEl) return;
        panelEl.classList.remove('is-open');
        if (panelOverlay) panelOverlay.hidden = true;
        // Mark no selection only on mobile/tablet (desktop keeps form
        // visible so admin can keep working).
        if (isMobile()) {
            panelForm.hidden = true;
            panelEmpty.hidden = false;
        }
    };

    if (panelCloseBtn) panelCloseBtn.addEventListener('click', closePanel);
    if (panelOverlay)  panelOverlay.addEventListener('click', closePanel);

    /* ─── Hydrate the edit panel from state.editing ────────────── */

    const placementSel     = $('[data-abo-admin-banners-placement]');
    const imagesWrap       = $('[data-abo-admin-banners-images]');
    const subtitleField    = $('[data-abo-admin-banners-subtitle-field]');
    const buttonField      = $('[data-abo-admin-banners-button-field]');
    const lookField        = $('[data-abo-admin-banners-look]');
    const altField         = $('[data-abo-admin-banners-alt-field]');
    const titleInput       = $('[data-abo-admin-banners-title]');
    const subtitleInput    = $('[data-abo-admin-banners-subtitle]');
    const buttonInput      = $('[data-abo-admin-banners-button]');
    const altInput         = $('[data-abo-admin-banners-alt]');
    const startsInput      = $('[data-abo-admin-banners-starts]');
    const endsInput        = $('[data-abo-admin-banners-ends]');
    const dateErrorEl      = $('[data-abo-admin-banners-date-error]');
    const altErrorEl       = $('[data-abo-admin-banners-alt-error]');
    const desktopPreviewEl = $('[data-abo-admin-banners-desktop-preview]');
    const mobilePreviewEl  = $('[data-abo-admin-banners-mobile-preview]');
    const desktopRemoveBtn = $('[data-abo-admin-banners-desktop-remove]');
    const mobileRemoveBtn  = $('[data-abo-admin-banners-mobile-remove]');
    const desktopFile      = $('[data-abo-admin-banners-desktop-file]');
    const mobileFile       = $('[data-abo-admin-banners-mobile-file]');
    const desktopChangeBtn = $('[data-abo-admin-banners-desktop-change]');
    const mobileChangeBtn  = $('[data-abo-admin-banners-mobile-change]');
    const desktopErrorEl   = $('[data-abo-admin-banners-desktop-error]');
    const mobileErrorEl    = $('[data-abo-admin-banners-mobile-error]');
    const linkTypeSel      = $('[data-abo-admin-banners-link-type]');
    const linkCategoryWrap = $('[data-abo-admin-banners-link-category]');
    const linkProductWrap  = $('[data-abo-admin-banners-link-product]');
    const linkUrlWrap      = $('[data-abo-admin-banners-link-url]');
    const linkCategorySel  = $('[data-abo-admin-banners-link-category-value]');
    const linkProductSel   = $('[data-abo-admin-banners-link-product-value]');
    const linkUrlInput     = $('[data-abo-admin-banners-link-url-value]');
    const linkUrlErrorEl   = $('[data-abo-admin-banners-link-url-error]');
    const previewWrap      = $('[data-abo-admin-banners-preview-wrap]');
    const previewFrame     = $('[data-abo-admin-banners-preview-frame]');
    const previewBanner    = $('[data-abo-admin-banners-preview-banner]');
    const previewOverlay   = $('[data-abo-admin-banners-preview-overlay]');
    const previewText      = $('[data-abo-admin-banners-preview-text]');
    const previewBtn       = $('[data-abo-admin-banners-preview-btn]');
    const previewDevice    = $('[data-abo-admin-banners-preview-device]');

    // Populate category/product selects once.
    const fillLinkSelects = () => {
        linkCategorySel.replaceChildren();
        for (const c of CATEGORIES) {
            const opt = ce('option', { value: c.slug }, c.name);
            linkCategorySel.appendChild(opt);
        }
        linkProductSel.replaceChildren();
        for (const p of PRODUCTS) {
            const opt = ce('option', { value: p.slug }, p.name);
            linkProductSel.appendChild(opt);
        }
    };
    fillLinkSelects();

    const setSwitch = (which, value) => {
        const wrap = $(`[data-abo-admin-banners-switch="${which}"]`);
        if (!wrap) return;
        const input = wrap.querySelector('input[type="checkbox"]');
        if (!input) return;
        input.checked = !!value;
        input.setAttribute('aria-checked', String(!!value));
    };

    const setSegmented = (rootSel, value) => {
        const wrap = $(rootSel);
        if (!wrap) return;
        const btns = wrap.querySelectorAll('button[data-value]');
        btns.forEach((b) => {
            const active = b.dataset.value === value;
            b.classList.toggle('is-active', active);
            b.setAttribute('aria-checked', String(active));
        });
    };

    const setLangTab = (which, value) => {
        state.langTabs[which] = value;
        const wrap = $(`[data-abo-admin-banners-${which}-lang]`);
        if (!wrap) return;
        wrap.querySelectorAll('button[data-lang]').forEach((b) => {
            const active = b.dataset.lang === value;
            b.classList.toggle('is-active', active);
            b.setAttribute('aria-selected', String(active));
            b.setAttribute('aria-pressed', String(active));
        });
        // Update the input to reflect the language-active value.
        const input = $(`[data-abo-admin-banners-${which === 'title' ? 'title' : which === 'subtitle' ? 'subtitle' : 'alt'}]`);
        if (input && state.editing) {
            const map = {
                alt:      { en: 'alt',         ar: 'altAr' },
                title:    { en: 'title',       ar: 'titleAr' },
                subtitle: { en: 'subtitle',    ar: 'subtitleAr' },
            };
            const key = map[which][value];
            input.value = state.editing[key] || '';
        }
    };

    const showLangErrors = () => {
        // Alt text required check (any of EN or AR present is fine,
        // but per the brief we treat the active input being empty as
        // a hard fail against the visible field).
        if (altErrorEl) {
            const v = (state.editing && (state.langTabs.alt === 'ar' ? state.editing.altAr : state.editing.alt)) || '';
            altErrorEl.hidden = !!String(v).trim();
        }
        if (dateErrorEl) {
            const s = state.editing && state.editing.starts;
            const e = state.editing && state.editing.ends;
            const bad = s && e && compareDate(s) >= compareDate(e);
            dateErrorEl.hidden = !bad;
        }
        if (linkUrlErrorEl) {
            const v = (state.editing && state.editing.linkUrl) || '';
            const ok = !v || v.startsWith('/') || v.startsWith('https://');
            linkUrlErrorEl.hidden = ok;
        }
    };

    const applyPlacementVisibility = (placement) => {
        const isAnno = placement === 'announcement';
        const isHero = placement === 'hero';
        if (imagesWrap)     imagesWrap.hidden = isAnno;
        if (subtitleField)  subtitleField.hidden = !isHero;
        if (buttonField)    buttonField.hidden = !isHero;
        if (lookField)      lookField.hidden = isAnno;
        if (altField)       altField.hidden = isAnno; // announcements have alt?
        if (previewWrap)    previewWrap.hidden = false;
        // Change label on title
        const titleLabel = $('[data-abo-admin-banners-title-label]');
        if (titleLabel) titleLabel.textContent = isAnno ? 'Message' : 'Title';
        // Show / hide per-link fields
        const lt = (state.editing && state.editing.linkType) || 'category';
        setLinkType(lt);
        // Hero preview needs a frame switch — keep mobile preview visible.
    };

    const setLinkType = (lt) => {
        linkCategoryWrap.hidden = lt !== 'category';
        linkProductWrap.hidden  = lt !== 'product';
        linkUrlWrap.hidden      = lt !== 'url';
    };

    const renderPreviewImageInto = (target, url) => {
        if (!target) return;
        // Strip previous <img> inside the frame, keep the placeholder.
        const old = target.querySelector('img');
        if (old) old.remove();
        const placeholder = target.querySelector('.abo-admin-banners__image-placeholder');
        if (placeholder) placeholder.style.display = url ? 'none' : '';
        if (url) {
            const img = ce('img', { src: url, alt: '' });
            target.appendChild(img);
        }
    };

    const renderPreview = () => {
        if (!state.editing) return;
        const b = state.editing;
        const isAnno = b.placement === 'announcement';

        // Toggle device frame class
        if (previewFrame) {
            previewFrame.classList.toggle('abo-admin-banners__preview-frame--mobile', state.devicePreview === 'mobile');
        }
        if (previewDevice) {
            previewDevice.querySelectorAll('button[data-value]').forEach((btn) => {
                const active = btn.dataset.value === state.devicePreview;
                btn.classList.toggle('is-active', active);
                btn.setAttribute('aria-checked', String(active));
            });
        }

        if (isAnno) {
            // Render announcement as a thin lime-bordered bar.
            if (previewBanner) {
                previewBanner.style.display = 'none';
            }
            let bar = previewFrame && previewFrame.querySelector('.abo-admin-banners__preview-announcement');
            if (!bar && previewFrame) {
                bar = ce('div', { class: 'abo-admin-banners__preview-announcement' });
                previewFrame.appendChild(bar);
            }
            if (bar) {
                bar.textContent = (b.title || 'Announcement message').trim() || 'Announcement message';
            }
            return;
        }

        // Hero / promo preview
        if (previewFrame) {
            const bar = previewFrame.querySelector('.abo-admin-banners__preview-announcement');
            if (bar) bar.remove();
        }
        if (previewBanner) previewBanner.style.display = '';

        // Image: use mobile image when on mobile preview and available.
        const imgEl = previewBanner && previewBanner.querySelector('img');
        if (previewBanner) {
            if (imgEl) imgEl.remove();
            const desired = (state.devicePreview === 'mobile' && b.mobileImage) ? b.mobileImage : b.desktopImage;
            if (desired) {
                const img = ce('img', { src: desired, alt: '' });
                previewBanner.appendChild(img);
            }
        }

        // Overlay
        if (previewOverlay) {
            previewOverlay.classList.remove(
                'abo-admin-banners__preview-overlay--light',
                'abo-admin-banners__preview-overlay--dark'
            );
            if (b.overlay === 'light' || b.overlay === 'dark') {
                previewOverlay.classList.add('abo-admin-banners__preview-overlay--' + b.overlay);
            }
        }

        // Text position + color
        if (previewText) {
            previewText.classList.remove(
                'abo-admin-banners__preview-text-pos--left',
                'abo-admin-banners__preview-text-pos--center',
                'abo-admin-banners__preview-text-pos--right'
            );
            previewText.classList.add('abo-admin-banners__preview-text-pos--' + (b.textPosition || 'left'));
        }
        if (previewBanner) {
            const color = b.textColor === 'dark' ? '#0a0a0a' : '#F4F6F0';
            previewBanner.style.setProperty('--banners-text-color', color);
        }

        // Text content
        if (previewText) {
            previewText.replaceChildren();
            if (b.title) previewText.appendChild(ce('p', { class: 'abo-admin-banners__preview-title' }, b.title));
            if (b.subtitle) previewText.appendChild(ce('p', { class: 'abo-admin-banners__preview-subtitle' }, b.subtitle));
        }
        if (previewBtn) {
            const showBtn = (b.placement === 'hero' && (b.buttonLabel || '').trim());
            previewBtn.style.display = showBtn ? '' : 'none';
            if (showBtn) previewBtn.textContent = (b.buttonLabel || 'Shop now').trim();
            // Tint the button with the same lime so the preview reads
            // exactly like the live look.
            previewBtn.style.background = 'var(--abo-lime)';
            previewBtn.style.color = 'var(--abo-black, #050607)';
        }
    };

    const releaseObjectUrls = () => {
        for (const k of ['desktop', 'mobile']) {
            const u = state.imageObjectUrls[k];
            if (u) URL.revokeObjectURL(u);
            state.imageObjectUrls[k] = null;
        }
    };

    const hydrateEditPanel = () => {
        if (!state.editing) return;
        const b = state.editing;

        if (placementSel) placementSel.value = b.placement;
        applyPlacementVisibility(b.placement);
        if (panelTitle) panelTitle.textContent = state.isNew ? 'NEW BANNER' : 'EDIT BANNER';

        // Image previews
        renderPreviewImageInto(desktopPreviewEl, b.desktopImage);
        renderPreviewImageInto(mobilePreviewEl,  b.mobileImage);
        if (desktopRemoveBtn) desktopRemoveBtn.disabled = !b.desktopImage;
        if (mobileRemoveBtn)  mobileRemoveBtn.disabled  = !b.mobileImage;
        if (desktopErrorEl)   desktopErrorEl.hidden = true;
        if (mobileErrorEl)    mobileErrorEl.hidden  = true;

        // Alt (active language)
        const altVal = state.langTabs.alt === 'ar' ? b.altAr : b.alt;
        if (altInput) altInput.value = altVal || '';

        // Title + subtitle + button
        const titleVal = state.langTabs.title === 'ar' ? b.titleAr : b.title;
        if (titleInput)    titleInput.value    = titleVal || '';
        if (subtitleInput) subtitleInput.value = (state.langTabs.subtitle === 'ar' ? b.subtitleAr : b.subtitle) || '';
        if (buttonInput)   buttonInput.value   = b.buttonLabel || '';

        // Link
        if (linkTypeSel)     linkTypeSel.value = b.linkType || (CATEGORIES.length ? 'category' : 'url');
        if (linkCategorySel) linkCategorySel.value = b.linkCategory || (CATEGORIES.length ? CATEGORIES[0].slug : '');
        if (linkProductSel)  linkProductSel.value  = b.linkProduct  || (PRODUCTS.length ? PRODUCTS[0].slug : '');
        if (linkUrlInput)    linkUrlInput.value    = b.linkUrl      || '';
        setLinkType(b.linkType || (CATEGORIES.length ? 'category' : 'url'));

        // Look segmented controls
        setSegmented('[data-abo-admin-banners-text-color]',     b.textColor    || 'light');
        setSegmented('[data-abo-admin-banners-text-position]',  b.textPosition || 'left');
        setSegmented('[data-abo-admin-banners-overlay]',        b.overlay      || 'none');

        // Schedule
        if (startsInput) startsInput.value = b.starts || '';
        if (endsInput)   endsInput.value   = b.ends   || '';

        // Switch
        setSwitch('active', b.active);

        // Live preview
        renderPreview();
        showLangErrors();

        // Dirty dot
        const dot = $('[data-abo-admin-banners-dirty-dot]');
        if (dot) dot.classList.remove('is-visible');
        state.dirty = false;
    };

    /* ─── Edit panel event wiring ─────────────────────────────── */

    if (placementSel) placementSel.addEventListener('change', () => {
        if (!state.editing) return;
        const prev = state.editing.placement;
        const next = placementSel.value;
        if (prev === next) return;
        state.editing.placement = next;
        // Update the order list — move the id from old to new placement.
        // A banner that was never saved has no place in a list yet.
        if (state.editing.id) {
            const idx = state.filterIdsByPlacement[prev].indexOf(state.editing.id);
            if (idx >= 0) state.filterIdsByPlacement[prev].splice(idx, 1);
            state.filterIdsByPlacement[next] = state.filterIdsByPlacement[next] || [];
            state.filterIdsByPlacement[next].push(state.editing.id);
        }
        setPlacement(next);
        applyPlacementVisibility(next);
        renderPreview();
        markDirty();
    });

    // EN/AR switch
    $$('[data-abo-admin-banners-alt-lang] button[data-lang], [data-abo-admin-banners-title-lang] button[data-lang], [data-abo-admin-banners-subtitle-lang] button[data-lang]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const wrap = btn.parentElement;
            const which = wrap.matches('[data-abo-admin-banners-alt-lang]') ? 'alt'
                : wrap.matches('[data-abo-admin-banners-title-lang]') ? 'title'
                : 'subtitle';
            setLangTab(which, btn.dataset.lang);
        });
    });

    // Input listeners — commit to state.editing and refresh live bits.
    // The same input serves the English and the Arabic copy, so the
    // language tab decides which field the typing goes into.
    const fieldForKey = {
        'abo-admin-banners-alt':      { key: 'alt',        arKey: 'altAr',      lang: 'alt' },
        'abo-admin-banners-title':    { key: 'title',      arKey: 'titleAr',    lang: 'title' },
        'abo-admin-banners-subtitle': { key: 'subtitle',   arKey: 'subtitleAr', lang: 'subtitle' },
        'abo-admin-banners-button':   { key: 'buttonLabel', arKey: null,         lang: null },
        'abo-admin-banners-starts':   { key: 'starts',     arKey: null,         lang: null },
        'abo-admin-banners-ends':     { key: 'ends',       arKey: null,         lang: null },
    };

    Object.entries(fieldForKey).forEach(([dataKey, cfg]) => {
        const el = document.querySelector(`[data-${dataKey}]`);
        if (!el) return;
        el.addEventListener('input', () => {
            if (!state.editing) return;
            const language = cfg.lang ? state.langTabs[cfg.lang] : 'en';
            const key = (cfg.arKey && language === 'ar') ? cfg.arKey : cfg.key;
            state.editing[key] = el.value;
            markDirty();
            if (key === 'alt' || key === 'altAr') showLangErrors();
            if (key === 'starts' || key === 'ends') showLangErrors();
            // Re-render just the bits of preview that depend on these fields
            renderPreview();
        });
    });

    // Link type
    if (linkTypeSel) linkTypeSel.addEventListener('change', () => {
        if (!state.editing) return;
        state.editing.linkType = linkTypeSel.value;
        setLinkType(linkTypeSel.value);
        markDirty();
    });
    [linkCategorySel, linkProductSel].forEach((el) => {
        if (!el) return;
        el.addEventListener('change', () => {
            if (!state.editing) return;
            state.editing[el === linkCategorySel ? 'linkCategory' : 'linkProduct'] = el.value;
            markDirty();
        });
    });
    if (linkUrlInput) linkUrlInput.addEventListener('input', () => {
        if (!state.editing) return;
        state.editing.linkUrl = linkUrlInput.value;
        showLangErrors();
        markDirty();
    });

    // Segmented controls
    ['text-color', 'text-position', 'overlay'].forEach((which) => {
        const wrap = $(`[data-abo-admin-banners-${which}]`);
        if (!wrap) return;
        wrap.addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-value]');
            if (!btn) return;
            const map = { 'text-color': 'textColor', 'text-position': 'textPosition', 'overlay': 'overlay' };
            const key = map[which];
            if (!state.editing) return;
            state.editing[key] = btn.dataset.value;
            setSegmented(`[data-abo-admin-banners-${which}]`, btn.dataset.value);
            renderPreview();
            markDirty();
        });
    });

    // Active switch
    const activeSwitch = $('[data-abo-admin-banners-switch="active"] input');
    if (activeSwitch) {
        activeSwitch.addEventListener('change', () => {
            if (!state.editing) return;
            state.editing.active = activeSwitch.checked;
            markDirty();
        });
    }

    // Image pickers
    const MAX_BYTES = 2 * 1024 * 1024;
    const ALLOWED = /^image\/(jpeg|jpg|png|webp)$/i;

    const wireImagePicker = (which) => {
        const fileEl    = which === 'desktop' ? desktopFile    : mobileFile;
        const changeBtn = which === 'desktop' ? desktopChangeBtn : mobileChangeBtn;
        const removeBtn = which === 'desktop' ? desktopRemoveBtn : mobileRemoveBtn;
        const previewEl = which === 'desktop' ? desktopPreviewEl : mobilePreviewEl;
        const errorEl   = which === 'desktop' ? desktopErrorEl   : mobileErrorEl;

        const showError = (msg) => {
            if (!errorEl) return;
            errorEl.hidden = false;
            errorEl.textContent = msg || 'Use a JPG, PNG or WebP under 2 MB';
        };
        const clearError = () => {
            if (!errorEl) return;
            errorEl.hidden = true;
        };

        if (changeBtn && fileEl) {
            changeBtn.addEventListener('click', () => fileEl.click());
        }
        if (fileEl) {
            fileEl.addEventListener('change', () => {
                clearError();
                const f = fileEl.files && fileEl.files[0];
                if (!f) return;
                if (!ALLOWED.test(f.type)) { showError(); return; }
                if (f.size > MAX_BYTES)    { showError(); return; }
                // Revoke previous
                const prev = state.imageObjectUrls[which];
                if (prev) URL.revokeObjectURL(prev);
                const url = URL.createObjectURL(f);
                state.imageObjectUrls[which] = url;
                state.imageFiles[which] = f;
                state.removedImages[which] = false;
                if (state.editing) {
                    state.editing[which === 'desktop' ? 'desktopImage' : 'mobileImage'] = url;
                }
                renderPreviewImageInto(previewEl, url);
                if (removeBtn) removeBtn.disabled = false;
                markDirty();
                renderPreview();
                // Reset input so the same file can be re-picked.
                fileEl.value = '';
            });
        }
        if (removeBtn) {
            removeBtn.addEventListener('click', () => {
                const prev = state.imageObjectUrls[which];
                if (prev) URL.revokeObjectURL(prev);
                state.imageObjectUrls[which] = null;
                state.imageFiles[which] = null;
                state.removedImages[which] = true;
                if (state.editing) {
                    state.editing[which === 'desktop' ? 'desktopImage' : 'mobileImage'] = null;
                }
                renderPreviewImageInto(previewEl, null);
                removeBtn.disabled = true;
                clearError();
                markDirty();
                renderPreview();
            });
        }
    };
    wireImagePicker('desktop');
    wireImagePicker('mobile');

    // Preview device toggle
    if (previewDevice) {
        previewDevice.addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-value]');
            if (!btn) return;
            state.devicePreview = btn.dataset.value;
            renderPreview();
        });
    }

    const markDirty = () => {
        state.dirty = true;
        const dot = $('[data-abo-admin-banners-dirty-dot]');
        if (dot) dot.classList.add('is-visible');
    };

    /* ─── Save / Duplicate / Delete ───────────────────────────── */

    const saveBtn        = $('[data-abo-admin-banners-save]');
    const duplicateBtn   = $('[data-abo-admin-banners-duplicate]');
    const deleteBtn      = $('[data-abo-admin-banners-delete]');
    const deleteNote     = $('[data-abo-admin-banners-delete-note]');

    /** The position of a banner inside its placement. */
    const sortOrderFor = (banner) => {
        const ids = state.filterIdsByPlacement[banner.placement] || [];
        const idx = ids.indexOf(banner.id);

        return idx < 0 ? ids.length : idx;
    };

    /** Drop a banner from every placement list. */
    const removeFromGroups = (id) => {
        for (const placement of PLACEMENTS) {
            const ids = state.filterIdsByPlacement[placement];
            const idx = ids.indexOf(id);
            if (idx > -1) ids.splice(idx, 1);
        }
    };

    /**
     * The fields of a banner, in the names the server stores them in.
     *
     * Only what the banners table holds is sent: the placement, the
     * copy in both languages, the look, the schedule, the link and the
     * two pictures.
     */
    const buildPayload = (b) => {
        const payload = new FormData();

        payload.append('placement', b.placement || 'hero');
        payload.append('title', b.title || '');
        payload.append('title_ar', b.titleAr || '');
        payload.append('subtitle', b.subtitle || '');
        payload.append('subtitle_ar', b.subtitleAr || '');
        payload.append('button_text', b.buttonLabel || '');
        payload.append('alt', b.alt || '');
        payload.append('alt_ar', b.altAr || '');
        payload.append('text_color', b.textColor || 'light');
        payload.append('text_position', b.textPosition || 'left');
        payload.append('overlay', b.overlay || 'none');
        payload.append('link_type', b.linkType || 'none');
        payload.append('link_target', b.linkType === 'category'
            ? (b.linkCategory || '')
            : b.linkType === 'product' ? (b.linkProduct || '') : '');
        payload.append('link_url', b.linkType === 'url' ? (b.linkUrl || '') : '');
        payload.append('starts_at', b.starts || '');
        payload.append('ends_at', b.ends || '');
        payload.append('is_active', b.active ? '1' : '0');
        payload.append('sort_order', String(sortOrderFor(b)));

        if (state.imageFiles.desktop) {
            payload.append('image', state.imageFiles.desktop);
        } else if (state.removedImages.desktop) {
            payload.append('remove_image', '1');
        }

        if (state.imageFiles.mobile) {
            payload.append('mobile_image', state.imageFiles.mobile);
        } else if (state.removedImages.mobile) {
            payload.append('remove_mobile_image', '1');
        }

        return payload;
    };

    if (saveBtn) saveBtn.addEventListener('click', async () => {
        if (!state.editing || state.busy) return;
        // Alt text is only required for placements that have an
        // image (hero / promo). Announcements have no image and no
        // alt field, so skip the check there.
        if (state.editing.placement !== 'announcement') {
            const enAlt = (state.editing.alt || '').trim();
            const arAlt = (state.editing.altAr || '').trim();
            if (!enAlt && !arAlt) {
                if (altInput) altInput.focus();
                showLangErrors();
                showToast('Alt text is required', 'coral');
                return;
            }
        }
        // Validate dates
        if (state.editing.starts && state.editing.ends) {
            if (compareDate(state.editing.starts) >= compareDate(state.editing.ends)) {
                showToast('End date must be after the start date', 'coral');
                showLangErrors();
                return;
            }
        }
        // Validate URL when custom
        if (state.editing.linkType === 'url') {
            const v = state.editing.linkUrl || '';
            if (v && !v.startsWith('/') && !v.startsWith('https://')) {
                showLangErrors();
                showToast('Enter a valid link', 'coral');
                return;
            }
        }

        const editing = state.editing;
        const wasNew = state.isNew;

        state.busy = true;
        saveBtn.disabled = true;

        try {
            const payload = buildPayload(editing);
            let url = urlFor('store');

            if (!wasNew) {
                url = urlFor('update', editing.id);
                // The update route is a PUT, but a body that carries a
                // picture travels as a POST with the method spoofed.
                payload.append('_method', 'PUT');
            }

            const result = await request('POST', url, payload);
            const saved = result.banner ? normalize(result.banner) : null;

            if (!saved) {
                throw new Error('The server did not return the banner.');
            }

            if (wasNew) {
                BANNERS.push(saved);
                const group = state.filterIdsByPlacement[saved.placement]
                    || (state.filterIdsByPlacement[saved.placement] = []);
                group.push(saved.id);
            } else {
                const idx = BANNERS.findIndex((b) => b.id === editing.id);
                if (idx > -1) BANNERS[idx] = saved;
            }

            state.isNew = false;
            state.selectedId = saved.id;
            state.editing = JSON.parse(JSON.stringify(saved));
            state.imageFiles.desktop = null;
            state.imageFiles.mobile = null;
            state.removedImages.desktop = false;
            state.removedImages.mobile = false;
            releaseObjectUrls();

            renderList();
            hydrateEditPanel();
            showToast(result.message || 'Saved');
        } catch (err) {
            showToast(err.message || 'The banner could not be saved.', 'coral');
        } finally {
            state.busy = false;
            saveBtn.disabled = false;
        }
    });

    if (duplicateBtn) duplicateBtn.addEventListener('click', async () => {
        if (!state.editing || state.isNew || state.busy) return;

        const id = state.editing.id;

        state.busy = true;
        duplicateBtn.disabled = true;

        try {
            const result = await request('POST', urlFor('duplicate', id), null);
            const copy = result.banner ? normalize(result.banner) : null;

            if (!copy) {
                throw new Error('The server did not return the copy.');
            }

            BANNERS.push(copy);
            const group = state.filterIdsByPlacement[copy.placement]
                || (state.filterIdsByPlacement[copy.placement] = []);
            group.push(copy.id);

            renderList();
            showToast(result.message || 'Duplicated');
        } catch (err) {
            showToast(err.message || 'The banner could not be duplicated.', 'coral');
        } finally {
            state.busy = false;
            duplicateBtn.disabled = false;
        }
    });

    if (deleteBtn) deleteBtn.addEventListener('click', () => {
        if (!state.editing) return;
        openDeleteModal();
    });

    /* ─── Modal handlers ───────────────────────────────────────── */

    const modalEl       = $('[data-abo-admin-banners-modal]');
    const modalCloseEl  = $('[data-abo-admin-banners-modal-close]');
    const modalCancelEl = $('[data-abo-admin-banners-modal-cancel]');
    const modalConfirmEl= $('[data-abo-admin-banners-modal-confirm]');
    const modalBodyEl   = $('[data-abo-admin-banners-modal-body]');

    const openDeleteModal = () => {
        if (!modalEl) return;
        const title = state.editing && state.editing.title
            ? `"${state.editing.title.replace(/[<>&]/g, '·').slice(0, 60)}"`
            : 'this banner';
        if (modalBodyEl) modalBodyEl.textContent = `Delete ${title}? This can't be undone.`;
        modalEl.hidden = false;
        // Focus the confirm for keyboard users.
        setTimeout(() => { if (modalConfirmEl) modalConfirmEl.focus(); }, 0);
    };
    const closeDeleteModal = () => {
        if (!modalEl) return;
        modalEl.hidden = true;
        if (state.editing) state.editing = null;
        state.selectedId = null;
        renderList();
        // Reset panel on mobile; keep open on desktop so the empty state shows
        if (isMobile()) closePanel(); else {
            panelForm.hidden = true;
            panelEmpty.hidden = false;
        }
    };

    if (modalCloseEl)  modalCloseEl.addEventListener('click', closeDeleteModal);
    if (modalCancelEl) modalCancelEl.addEventListener('click', closeDeleteModal);
    if (modalConfirmEl) modalConfirmEl.addEventListener('click', async () => {
        if (!state.editing) { closeDeleteModal(); return; }
        const id = state.editing.id;

        state.busy = true;

        try {
            const result = await request('DELETE', urlFor('destroy', id), null);

            const idx = BANNERS.findIndex((b) => b.id === id);
            if (idx >= 0) BANNERS.splice(idx, 1);
            removeFromGroups(id);

            state.editing = null;
            state.selectedId = null;
            state.isNew = false;

            renderList();
            if (isMobile()) closePanel(); else {
                panelForm.hidden = true;
                panelEmpty.hidden = false;
            }
            modalEl.hidden = true;
            showToast(result.message || 'Banner deleted');
        } catch (err) {
            showToast(err.message || 'The banner could not be deleted.', 'coral');
        } finally {
            state.busy = false;
        }
    });

    /* ─── Helpers + initial render ────────────────────────────── */

    // Esc closes the modal or the slide-over on tablet/mobile.
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if (modalEl && !modalEl.hidden) {
            modalEl.hidden = true;
            return;
        }
        if (isMobile() && panelEl && panelEl.classList.contains('is-open')) {
            closePanel();
        }
    });

    // Expose a tiny helper for tests / browser console.
    window.__aboAdminBanners = { BANNERS, state, renderList };

    // The list starts in the order the server sent.
    state.filterIdsByPlacement = groupByPlacement();

    // Initial paint
    renderList();
})();
