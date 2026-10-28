/*
 * ABO Admin · Shipping — page-local script.
 *
 * DOM behaviour for the admin shipping manager:
 *   - renders the zones + governorates tree from server-supplied data
 *   - global settings card (free shipping switch, fees, note + EN/AR)
 *   - keeps the "selected" row in sync with the edit panel
 *   - search filter that keeps parent zones visible when a child
 *     governorate matches
 *   - expand / collapse zones (or all at once)
 *   - HTML5 drag-to-reorder for governorates between zones
 *     (zones themselves are NOT reorderable)
 *   - 28x14 active toggles per row + zone-level saved state
 *   - one shared delete-confirm modal + toast
 *
 * The zone tree and global settings are serialised by
 * Admin\ShippingController into window.__SHIPPING_ZONES__ /
 * window.__SHIPPING_SETTINGS__ before this (deferred) script runs.
 *
 * The database owns the tree. Every change — saving a zone or a
 * governorate, moving one between zones, switching one on or off,
 * deleting one and saving the global settings — is a request to
 * Admin\ShippingController, and the tree on screen is only redrawn
 * once the server has answered.
 *
 * Markup anchor points (every selector uses data-* attributes):
 *   [data-abo-admin-shipping]                       — page root
 *   [data-abo-admin-shipping-tree]                  — <ul role="tree">
 *   [data-abo-admin-shipping-search]                — toolbar search
 *   [data-abo-admin-shipping-expand-all]            — expand/collapse
 *   [data-abo-admin-shipping-skeleton]              — loading skeleton
 *   [data-abo-admin-shipping-state="empty|noresults"]
 *                                                   — mutually exclusive states
 *   [data-abo-admin-shipping-panel]                 — right column edit panel
 *   [data-abo-admin-shipping-panel-close]           — slide-over close
 *   [data-abo-admin-shipping-panel-overlay]         — slide-over backdrop
 *   [data-abo-admin-shipping-modal]                 — delete modal
 *   [data-abo-admin-shipping-toast]                 — single shared toast
 *
 * No external dependencies. Loaded only on /admin/shipping.
 */

(function () {
    'use strict';

    /* ─── Zone tree (server-supplied) ────────────────────────────
     *
     * Read from window.__SHIPPING_ZONES__, which
     * Admin\ShippingController builds from the shipping_zones and
     * governorates tables. Falls back to an empty array so the page
     * still renders (in its "no zones" state) if the global is absent.
     *
     * Shape per zone:
     *   { id, name, nameAr, fee, timeFrom, timeTo, active,
     *     governorates: [
     *        { id, name, fee | null,   // null = inherit zone fee
     *          timeFrom | null, timeTo | null,   // null = inherit
     *          cod: bool, active: bool }
     *     ]
     *   }
     */
    const ZONES = window.__SHIPPING_ZONES__ ?? [];

    // The endpoints this page talks to.
    const ROUTES = window.__SHIPPING_ROUTES__ ?? {};

    /* ─── Server requests ──────────────────────────────────────── */

    const csrfToken = () => {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    };

    /** The endpoint for a row, with the placeholder filled in. */
    const urlFor = (key, id) => {
        const url = ROUTES[key] || '';
        return url
            .replace('__ZONE__', encodeURIComponent(id))
            .replace('__GOVERNORATE__', encodeURIComponent(id));
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
     * Send a request to the shipping endpoints.
     */
    const request = async (method, url, body) => {
        const headers = {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        };

        const options = { method, headers, credentials: 'same-origin' };

        if (body !== undefined && body !== null) {
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

    /* ─── Local UI state (never persisted) ────────────────────── */

    const state = {
        selected: null,          // { kind: 'zone' | 'gov' | '__new', id, parentId? }
        expanded: new Set(),     // zone IDs expanded by default
        filter: '',
        editing: null,           // snapshot of the row being edited
        langTab: 'en',           // for zone name EN/AR
        noteLang: 'en',          // for global settings note EN/AR
        dirty: false,            // edit-panel dirty
        settingsDirty: false,    // global settings dirty
        allExpanded: false,
        deleteTarget: null,
        feeMode: 'inherit',      // governorate fee mode (zone panel doesn't use this)
        busy: false,             // a request is in flight
    };

    /* ─── Tiny helpers ─────────────────────────────────────────── */

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

    /* ─── Toast (single shared instance) ───────────────────────── */

    let toastTimer = null;
    const toastEls = () => ({
        wrap:  $('[data-abo-admin-shipping-toast]'),
        text:  $('[data-abo-admin-shipping-toast-text]'),
        dot:   $('[data-abo-admin-shipping-toast-dot]'),
    });
    const showToast = (message, tone = 'lime') => {
        const t = toastEls();
        if (!t.wrap) return;
        if (toastTimer) clearTimeout(toastTimer);
        t.text.textContent = message;
        if (t.dot) t.dot.className = 'abo-admin-shipping__toast-dot abo-admin-shipping__toast-dot--' + tone;
        t.wrap.hidden = false;
        toastTimer = setTimeout(() => { t.wrap.hidden = true; }, 2400);
    };

    /* ─── Tree rendering ───────────────────────────────────────── */

    const root = $('[data-abo-admin-shipping]');
    if (!root) return;

    const treeEl      = $('[data-abo-admin-shipping-tree]');
    const skeletonEl  = $('[data-abo-admin-shipping-skeleton]');
    const stateEmpty  = $('[data-abo-admin-shipping-state="empty"]');
    const stateNoRes  = $('[data-abo-admin-shipping-state="noresults"]');
    const expandBtn   = $('[data-abo-admin-shipping-expand-all]');
    const helperEl    = $('[data-abo-admin-shipping-helper]');

    const findZone = (id) => ZONES.find((z) => z.id === id);
    const findGov = (id) => {
        for (const z of ZONES) {
            const g = z.governorates.find((x) => x.id === id);
            if (g) return { zone: z, gov: g };
        }
        return null;
    };

    /** Filter: a zone matches if its name matches OR any of its
     *  governorates matches. */
    const zoneMatches = (zone, q) => {
        if (!q) return true;
        if (zone.name.toLowerCase().includes(q)) return true;
        if (zone.nameAr && zone.nameAr.toLowerCase().includes(q)) return true;
        return zone.governorates.some((g) => g.name.toLowerCase().includes(q));
    };
    const govMatches = (gov, q) => !q || gov.name.toLowerCase().includes(q);

    /* ─── Row builders ─────────────────────────────────────────── */

    /** Build a zone row. The caret expands/collapses the children
     *  group; the toggle changes the zone active state. */
    const buildZoneRow = (zone) => {
        const isExpanded = state.allExpanded || state.expanded.has(zone.id);
        const isSelected = state.selected && state.selected.kind === 'zone' && state.selected.id === zone.id;
        const govCount   = zone.governorates.length;

        const li = ce('li', {
            role: 'treeitem',
            'aria-level': 1,
            'aria-expanded': String(isExpanded),
            'aria-selected': String(isSelected),
            tabindex: '-1',
            dataset: { kind: 'zone', id: zone.id, aboAdminShippingItem: '' },
            class: 'abo-admin-shipping__row abo-admin-shipping__row--zone',
        });

        // caret
        const caret = ce('button', {
            type: 'button',
            class: 'abo-admin-shipping__caret',
            'aria-label': isExpanded ? 'Collapse zone' : 'Expand zone',
            'aria-expanded': String(isExpanded),
            dataset: { aboAdminShippingZoneCaret: zone.id },
            html: '<svg viewBox="0 0 12 12" aria-hidden="true"><path d="M4 2 8 6 4 10" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
        });
        li.appendChild(caret);

        // zone name + governorates count meta
        const name = ce('p', { class: 'abo-admin-shipping__row-name' }, zone.name);
        li.appendChild(name);
        li.appendChild(ce('p', {
            class: 'abo-admin-shipping__row-meta',
            'aria-label': `${govCount} governorates`,
        }, `${govCount} governorate${govCount === 1 ? '' : 's'}`));

        // fee
        li.appendChild(ce('p', {
            class: 'abo-admin-shipping__row-fee',
            'aria-label': `Fee ${zone.fee} Egyptian pounds`,
        }, `EGP ${zone.fee}`));

        // delivery time
        li.appendChild(ce('p', {
            class: 'abo-admin-shipping__row-time',
            'aria-label': `Delivery ${zone.timeFrom} to ${zone.timeTo} days`,
        }, `${zone.timeFrom} to ${zone.timeTo} days`));

        // toggle (zone active)
        const toggle = ce('label', {
            class: 'abo-admin-shipping__toggle',
            'aria-label': (zone.active ? 'Deactivate' : 'Activate') + ' ' + zone.name,
        });
        const input = ce('input', {
            type: 'checkbox',
            'aria-checked': String(zone.active),
            dataset: { aboAdminShippingZoneToggle: zone.id },
        });
        input.checked = zone.active;
        toggle.appendChild(input);
        toggle.appendChild(ce('span', { class: 'abo-admin-shipping__toggle-track' }));
        toggle.appendChild(ce('span', { class: 'abo-admin-shipping__toggle-thumb' }));
        li.appendChild(toggle);

        // Children list
        const childrenList = ce('ul', {
            role: 'group',
            class: 'abo-admin-shipping__group abo-admin-shipping__group--govs',
            hidden: !isExpanded,
        });
        for (const gov of zone.governorates) {
            childrenList.appendChild(buildGovRow(gov, zone));
        }
        li.appendChild(childrenList);

        bindRowHandlers(li, /* isZone */ true);
        return li;
    };

    /** Build a governorate row. Rows are draggable across zones. */
    const buildGovRow = (gov, zone) => {
        const isSelected = state.selected && state.selected.kind === 'gov' && state.selected.id === gov.id;
        const isMuted = !zone.active;

        const li = ce('li', {
            role: 'treeitem',
            'aria-level': 2,
            'aria-selected': String(isSelected),
            tabindex: '-1',
            dataset: {
                kind: 'gov',
                id: gov.id,
                zoneId: zone.id,
                aboAdminShippingItem: '',
                aboAdminShippingGovDrag: gov.id,
            },
            class: [
                'abo-admin-shipping__row',
                'abo-admin-shipping__row--gov',
                isMuted ? 'is-muted' : '',
            ].filter(Boolean).join(' '),
            draggable: true,
        });

        // indent spacer
        li.appendChild(ce('span', { class: 'abo-admin-shipping__caret abo-admin-shipping__caret--placeholder', 'aria-hidden': 'true' }));

        // name + "zone off" pill
        const nameWrap = ce('span', { class: 'abo-admin-shipping__row-name' },
            gov.name,
            ce('span', {
                class: 'abo-admin-shipping__row-zone-off',
                'aria-label': 'Parent zone is inactive',
            }, 'zone off'),
        );
        li.appendChild(nameWrap);

        // fee (custom or "Zone fee")
        const feeText = gov.fee != null ? `EGP ${gov.fee}` : 'Zone fee';
        li.appendChild(ce('p', {
            class: 'abo-admin-shipping__row-fee' + (gov.fee == null ? ' abo-admin-shipping__row-fee--inherit' : ''),
            'aria-label': gov.fee != null ? `Fee ${gov.fee} Egyptian pounds` : 'Inherits zone fee',
        }, feeText));

        // delivery time (custom or zone time)
        const from = gov.timeFrom != null ? gov.timeFrom : zone.timeFrom;
        const to   = gov.timeTo   != null ? gov.timeTo   : zone.timeTo;
        li.appendChild(ce('p', {
            class: 'abo-admin-shipping__row-time',
            'aria-label': `Delivery ${from} to ${to} days`,
        }, `${from} to ${to} days`));

        // COD pill
        li.appendChild(ce('p', {
            class: 'abo-admin-shipping__row-cod' + (gov.cod ? ' is-on' : ''),
            'aria-label': gov.cod ? 'Cash on delivery available' : 'Cash on delivery not available',
        },
            ce('span', { class: 'abo-admin-shipping__row-cod-dot', 'aria-hidden': 'true' }),
            gov.cod ? 'COD' : 'No COD',
        ));

        // Active toggle
        const toggle = ce('label', {
            class: 'abo-admin-shipping__toggle',
            'aria-label': (gov.active ? 'Deactivate' : 'Activate') + ' ' + gov.name,
        });
        const input = ce('input', {
            type: 'checkbox',
            'aria-checked': String(gov.active),
            dataset: { aboAdminShippingGovToggle: gov.id },
        });
        input.checked = gov.active;
        toggle.appendChild(input);
        toggle.appendChild(ce('span', { class: 'abo-admin-shipping__toggle-track' }));
        toggle.appendChild(ce('span', { class: 'abo-admin-shipping__toggle-thumb' }));
        li.appendChild(toggle);

        bindRowHandlers(li, /* isZone */ false);
        return li;
    };

    /* ─── Render the tree ──────────────────────────────────────── */

    const renderTree = () => {
        if (!treeEl) return;

        if (ZONES.length === 0) {
            treeEl.hidden = true;
            if (stateEmpty) stateEmpty.hidden = false;
            if (stateNoRes) stateNoRes.hidden = true;
            if (skeletonEl) skeletonEl.hidden = true;
            if (helperEl) helperEl.hidden = true;
            return;
        }

        const q = state.filter.toLowerCase();
        const matchedZones = ZONES.filter((z) => zoneMatches(z, q));
        const hasAnyMatch = matchedZones.length > 0;

        if (state.filter && !hasAnyMatch) {
            treeEl.hidden = true;
            if (stateEmpty) stateEmpty.hidden = true;
            if (stateNoRes) stateNoRes.hidden = false;
            if (helperEl) helperEl.hidden = true;
        } else {
            treeEl.hidden = false;
            if (stateEmpty) stateEmpty.hidden = true;
            if (stateNoRes) stateNoRes.hidden = true;
            if (helperEl) helperEl.hidden = false;
        }
        if (skeletonEl) skeletonEl.hidden = true;

        treeEl.innerHTML = '';
        for (const zone of matchedZones) {
            const li = buildZoneRow(zone);
            treeEl.appendChild(li);
        }
    };

    /* ─── Row click / keyboard / drag ──────────────────────────── */

    const onZoneActivate = (zone) => {
        state.selected = { kind: 'zone', id: zone.id };
        state.editing = snapshotZone(zone);
        state.langTab = 'en';
        state.dirty = false;
        renderTree();
        renderPanel();
        if (isMobile()) openPanel();
    };

    const onGovActivate = (zone, gov) => {
        state.selected = { kind: 'gov', id: gov.id, parentId: zone.id };
        state.editing = snapshotGov(zone, gov);
        state.feeMode = gov.fee != null ? 'custom' : 'inherit';
        state.dirty = false;
        renderTree();
        renderPanel();
        if (isMobile()) openPanel();
    };

    const snapshotZone = (zone) => ({
        id: zone.id,
        dbId: zone.dbId,
        name: zone.name,
        nameAr: zone.nameAr || '',
        fee: zone.fee,
        timeFrom: zone.timeFrom,
        timeTo: zone.timeTo,
        active: zone.active,
    });

    const snapshotGov = (zone, gov) => ({
        id: gov.id,
        dbId: gov.dbId,
        zoneId: zone.id,
        name: gov.name,
        nameAr: gov.nameAr || '',
        fee: gov.fee,
        timeFrom: gov.timeFrom,
        timeTo: gov.timeTo,
        cod: gov.cod,
        active: gov.active,
    });

    const bindRowHandlers = (li, isZone) => {
        const govId = isZone ? null : li.dataset.id;
        const zoneId = isZone ? li.dataset.id : li.dataset.zoneId;

        // Click — selects the row. Don't trigger when clicking
        // interactive children (caret, toggle).
        li.addEventListener('click', (e) => {
            if (e.target.closest('[data-abo-admin-shipping-zone-caret], '
                + '[data-abo-admin-shipping-zone-toggle], '
                + '[data-abo-admin-shipping-gov-toggle], '
                + 'label, input, button')) return;

            // A governorate row lives inside its zone row, so the
            // click bubbles up to the zone as well. The zone must
            // not steal it — the governorate is the row the admin
            // pointed at.
            if (isZone && e.target.closest('li[data-kind="gov"]')) return;

            if (isZone) {
                const zone = findZone(zoneId);
                if (zone) onZoneActivate(zone);
            } else {
                const found = findGov(govId);
                if (found) onGovActivate(found.zone, found.gov);
            }
        });

        // Drag-and-drop — only for governorates.
        if (!isZone) {
            li.addEventListener('dragstart', (e) => {
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', govId);
                li.classList.add('is-dragging');
            });
            li.addEventListener('dragend', () => {
                li.classList.remove('is-dragging');
                $$('.abo-admin-shipping__row').forEach((el) => {
                    el.classList.remove('is-drop-before', 'is-drop-after', 'is-drop-target');
                });
            });
        }

        // Drop targets — both zone rows (drop between governorates)
        // and governorate rows (drop on a specific child).
        li.addEventListener('dragover', (e) => {
            if (li.classList.contains('is-dragging')) return;
            // Accept drops on zone rows AND governorate rows.
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            const rect = li.getBoundingClientRect();
            const before = (e.clientY - rect.top) < rect.height / 2;
            $$('.abo-admin-shipping__row').forEach((el) => {
                el.classList.remove('is-drop-before', 'is-drop-after', 'is-drop-target');
            });
            li.classList.add(before ? 'is-drop-before' : 'is-drop-after');
        });

        li.addEventListener('drop', (e) => {
            e.preventDefault();
            const draggedId = e.dataTransfer.getData('text/plain');
            if (!draggedId) return;
            const found = findGov(draggedId);
            if (!found) return;

            const before = li.classList.contains('is-drop-before');
            const targetZoneId = isZone ? zoneId : li.dataset.zoneId;

            // Remove from current zone's array.
            const srcZone = found.zone;
            const srcIdx = srcZone.governorates.findIndex((g) => g.id === draggedId);
            if (srcIdx > -1) srcZone.governorates.splice(srcIdx, 1);

            // Insert into target zone at the right index.
            const targetZone = findZone(targetZoneId);
            if (!targetZone) {
                // Put it back at the end of the source.
                srcZone.governorates.push(found.gov);
                $$('.abo-admin-shipping__row').forEach((el) => {
                    el.classList.remove('is-drop-before', 'is-drop-after', 'is-drop-target');
                });
                return;
            }

            // Determine the index within the target zone's gov array.
            let targetIdx;
            if (isZone) {
                // Drop on the zone row — append at the end of its list
                // (or beginning if before).
                targetIdx = before ? 0 : targetZone.governorates.length;
            } else {
                const targetGovId = li.dataset.id;
                targetIdx = targetZone.governorates.findIndex((g) => g.id === targetGovId);
                if (targetIdx < 0) targetIdx = targetZone.governorates.length;
                else if (!before) targetIdx += 1;
            }

            targetZone.governorates.splice(targetIdx, 0, found.gov);

            $$('.abo-admin-shipping__row').forEach((el) => {
                el.classList.remove('is-drop-before', 'is-drop-after', 'is-drop-target');
            });
            renderTree();
            renderPanel();
            moveGovernorate(found.gov, targetZone, srcZone, srcIdx);
        });

        li.addEventListener('keydown', (e) => onTreeKeydown(e, li, isZone));
    };

    /* ─── Tree keyboard navigation ────────────────────────────── */

    const flattenRows = () => {
        const out = [];
        const root = $('[data-abo-admin-shipping-tree]');
        if (!root) return out;
        const walk = (ul) => {
            for (const li of ul.children) {
                if (li.tagName !== 'LI' || !li.dataset.id) continue;
                out.push(li);
                const kids = li.querySelector(':scope > .abo-admin-shipping__group--govs');
                if (kids && !kids.hidden) walk(kids);
            }
        };
        walk(root);
        return out;
    };

    const onTreeKeydown = (e, row, isZone) => {
        const rows = flattenRows();
        const idx = rows.indexOf(row);
        if (idx === -1) return;

        switch (e.key) {
            case 'ArrowDown': {
                e.preventDefault();
                const next = rows[Math.min(idx + 1, rows.length - 1)];
                if (next) next.focus();
                break;
            }
            case 'ArrowUp': {
                e.preventDefault();
                const prev = rows[Math.max(idx - 1, 0)];
                if (prev) prev.focus();
                break;
            }
            case 'ArrowRight': {
                if (isZone) {
                    e.preventDefault();
                    if (row.getAttribute('aria-expanded') !== 'true') {
                        state.expanded.add(row.dataset.id);
                        renderTree();
                        const target = $('[data-abo-admin-shipping-tree] li[data-id="' + row.dataset.id + '"]');
                        if (target) target.focus();
                    }
                }
                break;
            }
            case 'ArrowLeft': {
                if (isZone && row.getAttribute('aria-expanded') === 'true') {
                    e.preventDefault();
                    state.expanded.delete(row.dataset.id);
                    renderTree();
                    const target = $('[data-abo-admin-shipping-tree] li[data-id="' + row.dataset.id + '"]');
                    if (target) target.focus();
                } else if (!isZone) {
                    e.preventDefault();
                    const flat = flattenRows();
                    const prev = flat[idx - 1];
                    if (prev) prev.focus();
                }
                break;
            }
            case 'Enter': {
                e.preventDefault();
                if (isZone) {
                    const zone = findZone(row.dataset.id);
                    if (zone) onZoneActivate(zone);
                } else {
                    const found = findGov(row.dataset.id);
                    if (found) onGovActivate(found.zone, found.gov);
                }
                break;
            }
            case ' ': {
                e.preventDefault();
                if (isZone) {
                    const open = row.getAttribute('aria-expanded') === 'true';
                    if (open) state.expanded.delete(row.dataset.id);
                    else state.expanded.add(row.dataset.id);
                    renderTree();
                    const target = $('[data-abo-admin-shipping-tree] li[data-id="' + row.dataset.id + '"]');
                    if (target) target.focus();
                } else {
                    // Toggle the governorate active state.
                    const input = row.querySelector('[data-abo-admin-shipping-gov-toggle]');
                    if (input) {
                        input.checked = !input.checked;
                        input.setAttribute('aria-checked', String(input.checked));
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
                break;
            }
            case 'Home': {
                e.preventDefault();
                if (rows[0]) rows[0].focus();
                break;
            }
            case 'End': {
                e.preventDefault();
                if (rows.length) rows[rows.length - 1].focus();
                break;
            }
        }
    };

    /**
     * Switch a zone on or off.
     *
     * The server owns the flag: the tree is redrawn from the zone the
     * server answered with, and a refusal leaves it as it was.
     */
    const toggleZone = async (zone, next) => {
        try {
            const result = await request('PUT', urlFor('zoneUpdate', zone.dbId), { is_active: next });
            const saved = result.zone;

            if (saved) {
                const idx = ZONES.findIndex((z) => z.id === saved.id);
                if (idx > -1) ZONES[idx] = saved;
            } else {
                zone.active = next;
            }

            renderTree();
            // If the toggled zone is the selected one, sync panel.
            if (state.selected && state.selected.kind === 'zone' && state.selected.id === zone.id) {
                const current = findZone(zone.id);
                state.editing.active = current ? current.active : next;
                renderPanel();
            }
            showToast(result.message || (next ? 'Zone activated' : 'Zone deactivated'), 'lime');
        } catch (err) {
            renderTree();
            showToast(err.message || 'The zone could not be switched.', 'coral');
        }
    };

    /**
     * Switch a governorate on or off.
     */
    const toggleGovernorate = async (governorate, next) => {
        try {
            const result = await request('PUT', urlFor('governorateUpdate', governorate.dbId), {
                is_active: next,
            });
            const saved = result.governorate;

            if (saved) {
                for (const z of ZONES) {
                    const idx = z.governorates.findIndex((g) => g.id === saved.id);
                    if (idx > -1) z.governorates[idx] = saved;
                }
            } else {
                governorate.active = next;
            }

            renderTree();
            if (state.selected && state.selected.kind === 'gov' && state.selected.id === governorate.id) {
                const found = findGov(governorate.id);
                state.editing.active = found ? found.gov.active : next;
                renderPanel();
            }
            showToast(result.message || (next ? 'Activated' : 'Deactivated'), 'lime');
        } catch (err) {
            renderTree();
            showToast(err.message || 'The governorate could not be switched.', 'coral');
        }
    };

    /**
     * Write a moved governorate back: the zone it belongs to, and the
     * order of the governorates inside that zone.
     */
    const moveGovernorate = async (governorate, zone, sourceZone, sourceIndex) => {
        try {
            await request('PUT', urlFor('governorateUpdate', governorate.dbId), {
                shipping_zone_id: zone.dbId,
            });

            const result = await request('POST', urlFor('zoneReorder', zone.dbId), {
                governorates: zone.governorates.map((g) => g.dbId),
            });

            const saved = result.zone;
            if (saved) {
                const idx = ZONES.findIndex((z) => z.id === saved.id);
                if (idx > -1) ZONES[idx] = saved;
            }

            renderTree();
            renderPanel();
            showToast(result.message || 'Updated', 'lime');
        } catch (err) {
            // Put the governorate back where it was.
            const at = zone.governorates.findIndex((g) => g.id === governorate.id);
            if (at > -1) zone.governorates.splice(at, 1);
            sourceZone.governorates.splice(
                sourceIndex < 0 ? sourceZone.governorates.length : sourceIndex,
                0,
                governorate,
            );

            renderTree();
            renderPanel();
            showToast(err.message || 'The move could not be saved.', 'coral');
        }
    };

    /* ─── Tree-level delegated events (caret / toggle) ────────── */

    const wireTreeGlobal = () => {
        treeEl.addEventListener('click', (e) => {
            const caret = e.target.closest('[data-abo-admin-shipping-zone-caret]');
            if (caret) {
                e.stopPropagation();
                const id = caret.dataset.aboAdminShippingZoneCaret;
                const open = state.expanded.has(id);
                if (open) state.expanded.delete(id); else state.expanded.add(id);
                renderTree();
                const newCaret = $('[data-abo-admin-shipping-zone-caret="' + id + '"]');
                if (newCaret) newCaret.focus();
                return;
            }

            // Zone active toggle
            const zoneToggle = e.target.closest('[data-abo-admin-shipping-zone-toggle]');
            if (zoneToggle) {
                e.stopPropagation();
                const id = zoneToggle.dataset.aboAdminShippingZoneToggle;
                const zone = findZone(id);
                if (zone) {
                    toggleZone(zone, zoneToggle.checked);
                }
                return;
            }

            // Governorate active toggle
            const govToggle = e.target.closest('[data-abo-admin-shipping-gov-toggle]');
            if (govToggle) {
                e.stopPropagation();
                const id = govToggle.dataset.aboAdminShippingGovToggle;
                const found = findGov(id);
                if (found) {
                    toggleGovernorate(found.gov, govToggle.checked);
                }
                return;
            }
        });

        treeEl.addEventListener('focusin', (e) => {
            const li = e.target.closest('li[data-id]');
            if (!li) return;
            $$('[data-abo-admin-shipping-tree] li[data-id]').forEach((r) => r.tabIndex = -1);
            li.tabIndex = 0;
        });
    };

    /* ─── Toolbar: search + expand/collapse ───────────────────── */

    const wireToolbar = () => {
        const search = $('[data-abo-admin-shipping-search]');
        if (search) {
            search.addEventListener('input', () => {
                state.filter = search.value.trim();
                renderTree();
            });
        }
        if (expandBtn) {
            expandBtn.addEventListener('click', () => {
                state.allExpanded = !state.allExpanded;
                expandBtn.textContent = state.allExpanded ? 'COLLAPSE ALL' : 'EXPAND ALL';
                expandBtn.setAttribute('aria-pressed', String(state.allExpanded));
                renderTree();
            });
        }
    };

    /* ─── Right panel (edit form) ──────────────────────────────── */

    const panelEl          = $('[data-abo-admin-shipping-panel]');
    const panelCloseBtn    = $('[data-abo-admin-shipping-panel-close]');
    const panelOverlayEl   = $('[data-abo-admin-shipping-panel-overlay]');
    const panelFormEl      = $('[data-abo-admin-shipping-panel-form]');
    const panelEmptyEl     = $('[data-abo-admin-shipping-panel-no-selection]');
    const panelTitleEl     = $('[data-abo-admin-shipping-panel-title-text]');
    const dirtyDotEl       = $('[data-abo-admin-shipping-dirty-dot]');
    const saveBtn          = $('[data-abo-admin-shipping-save]');
    const deleteBtn        = $('[data-abo-admin-shipping-delete]');
    const deleteNoteEl     = $('[data-abo-admin-shipping-delete-note]');

    // Field references.
    const fZoneName        = $$('[data-abo-admin-shipping-zone-name]');
    const fZoneFee         = $('[data-abo-admin-shipping-zone-fee]');
    const fTimeFrom        = $('[data-abo-admin-shipping-time-from]');
    const fTimeTo          = $('[data-abo-admin-shipping-time-to]');
    const fZoneActive      = $('[data-abo-admin-shipping-zone-active]');
    const fZoneChips       = $('[data-abo-admin-shipping-zone-chips]');

    const fGovName         = $('[data-abo-admin-shipping-gov-name]');
    const fGovZone         = $('[data-abo-admin-shipping-gov-zone]');
    const fGovFeeMode      = $$('[data-abo-admin-shipping-gov-fee-mode]');
    const fGovFee          = $('[data-abo-admin-shipping-gov-fee]');
    const fGovFeeInherit   = $('[data-abo-admin-shipping-gov-fee-inherit]');
    const fGovFeeWrap      = $('[data-abo-admin-shipping-gov-fee-wrap]');
    const fGovTimeFrom     = $('[data-abo-admin-shipping-gov-time-from]');
    const fGovTimeTo       = $('[data-abo-admin-shipping-gov-time-to]');
    const fGovCod          = $('[data-abo-admin-shipping-gov-cod]');
    const fGovActive       = $('[data-abo-admin-shipping-gov-active]');

    // Field groups (we toggle these based on mode).
    const groupZoneName    = $('[data-abo-admin-shipping-field="zone-name"]');
    const groupZoneFee     = $('[data-abo-admin-shipping-field="zone-fee"]');
    const groupZoneTime    = $('[data-abo-admin-shipping-field="zone-time"]');
    const groupZoneChips   = $('[data-abo-admin-shipping-field="zone-chips"]');
    const groupGovHeader   = $('[data-abo-admin-shipping-field="gov-header"]');
    const groupGovZone     = $('[data-abo-admin-shipping-field="gov-zone"]');
    const groupGovFee      = $('[data-abo-admin-shipping-field="gov-fee"]');
    const groupGovTime     = $('[data-abo-admin-shipping-field="gov-time"]');
    const switchGovCod     = $('[data-abo-admin-shipping-switch="gov-cod"]');
    const switchGovActive  = $('[data-abo-admin-shipping-switch="gov-active"]');
    const switchZoneActive = $('[data-abo-admin-shipping-switch="zone-active"]');

    const setGroup = (el, visible) => { if (el) el.hidden = !visible; };

    const renderPanel = () => {
        if (!panelEl || !panelFormEl) return;
        if (!state.editing) {
            panelFormEl.hidden = true;
            if (panelEmptyEl) panelEmptyEl.hidden = false;
            if (dirtyDotEl) dirtyDotEl.classList.remove('is-visible');
            return;
        }
        if (panelEmptyEl) panelEmptyEl.hidden = true;
        panelFormEl.hidden = false;

        const e = state.editing;
        const isZone = state.selected.kind === 'zone';
        const isGov  = state.selected.kind === 'gov';
        const isNew  = state.selected.kind === '__new';

        // Title.
        if (panelTitleEl) {
            panelTitleEl.textContent = isNew ? 'NEW ZONE' : (isZone ? 'EDIT ZONE' : 'EDIT GOVERNORATE');
        }

        // Show / hide groups.
        const showZoneFields = isZone || isNew;
        const showGovFields  = isGov;
        setGroup(groupZoneName,    showZoneFields);
        setGroup(groupZoneFee,     showZoneFields);
        setGroup(groupZoneTime,    showZoneFields);
        setGroup(groupZoneChips,   isZone && !isNew);
        setGroup(switchZoneActive, showZoneFields);
        setGroup(groupGovHeader,   showGovFields);
        setGroup(groupGovZone,     showGovFields);
        setGroup(groupGovFee,      showGovFields);
        setGroup(groupGovTime,     showGovFields);
        setGroup(switchGovCod,     showGovFields);
        setGroup(switchGovActive,  showGovFields);

        // Apply the zone-mode editing snapshot.
        if (showZoneFields) {
            applyLangTab();
            if (fZoneFee)  fZoneFee.value  = e.fee;
            if (fTimeFrom) fTimeFrom.value = e.timeFrom;
            if (fTimeTo)   fTimeTo.value   = e.timeTo;
            if (fZoneActive) {
                fZoneActive.checked = !!e.active;
                fZoneActive.setAttribute('aria-checked', String(fZoneActive.checked));
            }
            renderZoneChips();
        }

        // Apply the governorate-mode editing snapshot.
        if (showGovFields) {
            if (fGovName) fGovName.textContent = e.name;

            // Zone select
            if (fGovZone) {
                fGovZone.innerHTML = '';
                for (const z of ZONES) {
                    const opt = ce('option', { value: z.id }, z.name);
                    if (z.id === e.zoneId) opt.selected = true;
                    fGovZone.appendChild(opt);
                }
            }

            // Fee mode + value
            const zoneOfThis = findZone(e.zoneId);
            if (fGovFeeInherit) {
                fGovFeeInherit.textContent = zoneOfThis
                    ? `Use zone fee (EGP ${zoneOfThis.fee})`
                    : 'Use zone fee';
            }
            const useCustom = state.feeMode === 'custom' || e.fee != null;
            state.feeMode = useCustom ? 'custom' : 'inherit';
            for (const radio of fGovFeeMode) {
                radio.checked = radio.value === state.feeMode;
            }
            if (fGovFeeWrap) fGovFeeWrap.hidden = !useCustom;
            if (fGovFee) {
                fGovFee.value = useCustom && e.fee != null ? String(e.fee) : '';
                fGovFee.disabled = !useCustom;
            }

            // Delivery time override
            if (fGovTimeFrom) fGovTimeFrom.value = e.timeFrom != null ? String(e.timeFrom) : '';
            if (fGovTimeTo)   fGovTimeTo.value   = e.timeTo   != null ? String(e.timeTo)   : '';

            // COD + active
            if (fGovCod) {
                fGovCod.checked = !!e.cod;
                fGovCod.setAttribute('aria-checked', String(fGovCod.checked));
            }
            if (fGovActive) {
                fGovActive.checked = !!e.active;
                fGovActive.setAttribute('aria-checked', String(fGovActive.checked));
            }
        }

        // Save is always available.
        // Delete is only shown for existing zones (and not for governorates).
        if (deleteBtn) {
            const showDelete = (isZone && !isNew) || isGov;
            deleteBtn.hidden = !showDelete;

            if (showDelete) {
                const z = findZone(e.id);
                if (z && z.governorates.length > 0) {
                    deleteBtn.disabled = true;
                    if (deleteNoteEl) {
                        deleteNoteEl.textContent = 'Move its governorates to another zone first';
                        deleteNoteEl.hidden = false;
                    }
                } else {
                    deleteBtn.disabled = false;
                    if (deleteNoteEl) deleteNoteEl.hidden = true;
                }
            } else if (deleteNoteEl) {
                deleteNoteEl.hidden = true;
            }
        }

        // Dirty dot.
        if (dirtyDotEl) dirtyDotEl.classList.toggle('is-visible', state.dirty);
    };

    const renderZoneChips = () => {
        if (!fZoneChips || !state.editing) return;
        fZoneChips.innerHTML = '';
        const zone = findZone(state.editing.id);
        if (!zone) return;
        if (zone.governorates.length === 0) {
            const li = ce('li', { class: 'abo-admin-shipping__chip abo-admin-shipping__chip--off' }, 'No governorates yet');
            fZoneChips.appendChild(li);
            return;
        }
        for (const g of zone.governorates) {
            const li = ce('li', {
                class: 'abo-admin-shipping__chip' + (!zone.active || !g.active ? ' abo-admin-shipping__chip--off' : ''),
            }, g.name);
            fZoneChips.appendChild(li);
        }
    };

    const applyLangTab = () => {
        const btns = $$('[data-abo-admin-shipping-zone-lang] button');
        for (const btn of btns) {
            const active = btn.dataset.lang === state.langTab;
            btn.classList.toggle('is-active', active);
            btn.setAttribute('aria-pressed', String(active));
            btn.setAttribute('aria-selected', String(active));
        }
        for (const input of fZoneName) {
            // Show only the active language input.
            input.hidden = input.dataset.lang !== state.langTab;
            input.value = state.langTab === 'ar'
                ? (state.editing ? (state.editing.nameAr || '') : '')
                : (state.editing ? (state.editing.name || '') : '');
        }
    };

    const openPanel = () => {
        if (!panelEl) return;
        if (isMobile()) {
            panelEl.classList.add('is-open');
            panelEl.setAttribute('role', 'dialog');
            panelEl.setAttribute('aria-modal', 'true');
            if (panelCloseBtn) panelCloseBtn.focus();
            if (panelOverlayEl) panelOverlayEl.hidden = false;
        }
    };

    const closePanel = () => {
        if (!panelEl) return;
        if (isMobile()) {
            panelEl.classList.remove('is-open');
            panelEl.removeAttribute('role');
            panelEl.removeAttribute('aria-modal');
            if (panelOverlayEl) panelOverlayEl.hidden = true;
        }
    };

    const markDirty = () => {
        if (!state.dirty) {
            state.dirty = true;
            if (dirtyDotEl) dirtyDotEl.classList.add('is-visible');
        }
    };

    const wirePanel = () => {
        // Language tabs for zone name.
        for (const btn of $$('[data-abo-admin-shipping-zone-lang] button')) {
            btn.addEventListener('click', () => {
                state.langTab = btn.dataset.lang;
                applyLangTab();
            });
        }

        // Close button + overlay.
        if (panelCloseBtn) panelCloseBtn.addEventListener('click', () => closePanel());
        if (panelOverlayEl) panelOverlayEl.addEventListener('click', () => closePanel());

        // Zone name inputs.
        for (const input of fZoneName) {
            input.addEventListener('input', () => {
                if (!state.editing) return;
                if (input.dataset.lang === 'ar') state.editing.nameAr = input.value;
                else state.editing.name = input.value;
                markDirty();
            });
        }

        // Zone fee.
        if (fZoneFee) {
            fZoneFee.addEventListener('input', () => {
                if (!state.editing) return;
                state.editing.fee = parseInt(fZoneFee.value.replace(/\D+/g, ''), 10) || 0;
                markDirty();
            });
        }

        // Delivery time (From / To).
        if (fTimeFrom) {
            fTimeFrom.addEventListener('input', () => {
                if (!state.editing) return;
                state.editing.timeFrom = parseInt(fTimeFrom.value.replace(/\D+/g, ''), 10) || 0;
                markDirty();
            });
        }
        if (fTimeTo) {
            fTimeTo.addEventListener('input', () => {
                if (!state.editing) return;
                state.editing.timeTo = parseInt(fTimeTo.value.replace(/\D+/g, ''), 10) || 0;
                markDirty();
            });
        }

        // Zone active.
        if (fZoneActive) {
            fZoneActive.addEventListener('change', () => {
                if (!state.editing) return;
                state.editing.active = fZoneActive.checked;
                fZoneActive.setAttribute('aria-checked', String(fZoneActive.checked));
                markDirty();
            });
        }

        // Governorate zone select.
        if (fGovZone) {
            fGovZone.addEventListener('change', () => {
                if (!state.editing) return;
                state.editing.zoneId = fGovZone.value;
                markDirty();
            });
        }

        // Governorate fee mode.
        for (const radio of fGovFeeMode) {
            radio.addEventListener('change', () => {
                if (!state.editing) return;
                state.feeMode = radio.value;
                if (radio.value === 'inherit') {
                    state.editing.fee = null;
                    if (fGovFeeWrap) fGovFeeWrap.hidden = true;
                    if (fGovFee) fGovFee.disabled = true;
                } else {
                    if (fGovFeeWrap) fGovFeeWrap.hidden = false;
                    if (fGovFee) fGovFee.disabled = false;
                    state.editing.fee = parseInt((fGovFee && fGovFee.value || '0').replace(/\D+/g, ''), 10) || 0;
                }
                markDirty();
            });
        }

        // Governorate fee custom value.
        if (fGovFee) {
            fGovFee.addEventListener('input', () => {
                if (!state.editing) return;
                state.editing.fee = parseInt(fGovFee.value.replace(/\D+/g, ''), 10) || 0;
                markDirty();
            });
        }

        // Governorate time override (empty = inherit).
        if (fGovTimeFrom) {
            fGovTimeFrom.addEventListener('input', () => {
                if (!state.editing) return;
                const v = fGovTimeFrom.value.trim();
                state.editing.timeFrom = v === '' ? null : (parseInt(v.replace(/\D+/g, ''), 10) || 0);
                markDirty();
            });
        }
        if (fGovTimeTo) {
            fGovTimeTo.addEventListener('input', () => {
                if (!state.editing) return;
                const v = fGovTimeTo.value.trim();
                state.editing.timeTo = v === '' ? null : (parseInt(v.replace(/\D+/g, ''), 10) || 0);
                markDirty();
            });
        }

        // COD + active (governorate).
        if (fGovCod) {
            fGovCod.addEventListener('change', () => {
                if (!state.editing) return;
                state.editing.cod = fGovCod.checked;
                fGovCod.setAttribute('aria-checked', String(fGovCod.checked));
                markDirty();
            });
        }
        if (fGovActive) {
            fGovActive.addEventListener('change', () => {
                if (!state.editing) return;
                state.editing.active = fGovActive.checked;
                fGovActive.setAttribute('aria-checked', String(fGovActive.checked));
                markDirty();
            });
        }

        // Save.
        if (saveBtn) {
            saveBtn.addEventListener('click', async () => {
                if (!state.editing || state.busy) return;
                const e = state.editing;
                const isZone = state.selected.kind === 'zone' || state.selected.kind === '__new';
                const isNew = state.selected.kind === '__new';

                state.busy = true;
                saveBtn.disabled = true;

                try {
                    if (isZone) {
                        // Only the fields the shipping_zones table holds.
                        const body = {
                            name: e.name || '',
                            name_ar: e.nameAr || null,
                            fee: e.fee || 0,
                            time_from: e.timeFrom || 1,
                            time_to: e.timeTo || 1,
                            is_active: e.active !== false,
                        };

                        const url = isNew ? urlFor('zoneStore') : urlFor('zoneUpdate', e.dbId);
                        const method = isNew ? 'POST' : 'PUT';

                        const result = await request(method, url, body);
                        const zone = result.zone;

                        if (!zone) {
                            throw new Error('The server did not return the zone.');
                        }

                        if (isNew) {
                            ZONES.push(zone);
                        } else {
                            const idx = ZONES.findIndex((z) => z.id === zone.id);
                            if (idx > -1) ZONES[idx] = zone;
                        }

                        state.selected = { kind: 'zone', id: zone.id };
                        state.editing = snapshotZone(zone);
                    } else {
                        const found = findGov(e.id);
                        const zone = findZone(e.zoneId || (found ? found.zone.id : null));

                        if (!zone) {
                            throw new Error('The governorate has no zone.');
                        }

                        const body = {
                            shipping_zone_id: zone.dbId,
                            name: e.name || '',
                            name_ar: e.nameAr || null,
                            fee: e.fee == null ? null : e.fee,
                            time_from: e.timeFrom == null ? null : e.timeFrom,
                            time_to: e.timeTo == null ? null : e.timeTo,
                            allows_cod: !!e.cod,
                            is_active: !!e.active,
                        };

                        const result = await request('PUT', urlFor('governorateUpdate', e.dbId), body);
                        const governorate = result.governorate;

                        if (!governorate) {
                            throw new Error('The server did not return the governorate.');
                        }

                        // Put the governorate where the server put it.
                        for (const z of ZONES) {
                            const idx = z.governorates.findIndex((g) => g.id === governorate.id);
                            if (idx > -1) z.governorates.splice(idx, 1);
                        }

                        const target = findZone(governorate.parentId) || zone;
                        target.governorates.push(governorate);

                        state.selected = { kind: 'gov', id: governorate.id, parentId: target.id };
                        state.editing = snapshotGov(target, governorate);
                    }

                    state.dirty = false;
                    if (dirtyDotEl) dirtyDotEl.classList.remove('is-visible');
                    renderTree();
                    renderPanel();
                    showToast(result.message || 'Saved', 'lime');
                } catch (err) {
                    showToast(err.message || 'The change could not be saved.', 'coral');
                } finally {
                    state.busy = false;
                    saveBtn.disabled = false;
                }
            });
        }

        // Delete (opens modal).
        if (deleteBtn) {
            deleteBtn.addEventListener('click', () => {
                if (deleteBtn.disabled) return;
                if (state.selected.kind === 'zone') {
                    const z = findZone(state.editing.id);
                    if (!z) return;
                    if (z.governorates.length > 0) return;
                }
                openDeleteModal();
            });
        }
    };

    /* ─── Delete confirmation modal ────────────────────────────── */

    const modalEl    = $('[data-abo-admin-shipping-modal]');
    const modalBody  = $('[data-abo-admin-shipping-modal-body]');
    const modalConfirm = $('[data-abo-admin-shipping-modal-confirm]');
    const modalCancel  = $('[data-abo-admin-shipping-modal-cancel]');
    const modalCloseEls = () => $$('[data-abo-admin-shipping-modal-close]');

    const openDeleteModal = () => {
        if (!modalEl || !state.editing) return;

        const isGovernorate = state.selected.kind === 'gov';
        const name = isGovernorate
            ? state.editing.name
            : (state.editing.name || 'this zone');

        if (modalBody) {
            modalBody.textContent = isGovernorate
                ? `Remove "${name}" from the zone? This action cannot be undone.`
                : `Delete "${name}"? This action cannot be undone.`;
        }

        modalEl.hidden = false;
        modalEl.setAttribute('aria-modal', 'true');
        if (modalConfirm) modalConfirm.focus();
    };

    const closeDeleteModal = () => {
        if (!modalEl) return;
        modalEl.hidden = true;
    };

    const wireModal = () => {
        for (const el of modalCloseEls()) el.addEventListener('click', closeDeleteModal);
        if (modalCancel) modalCancel.addEventListener('click', closeDeleteModal);
        if (modalConfirm) modalConfirm.addEventListener('click', async () => {
            if (!state.editing || state.busy) return;

            const isGovernorate = state.selected.kind === 'gov';
            const id = state.editing.id;
            const dbId = state.editing.dbId;

            state.busy = true;

            try {
                const url = isGovernorate
                    ? urlFor('governorateDestroy', dbId)
                    : urlFor('zoneDestroy', dbId);

                const result = await request('DELETE', url, null);

                if (isGovernorate) {
                    for (const z of ZONES) {
                        const idx = z.governorates.findIndex((g) => g.id === id);
                        if (idx > -1) z.governorates.splice(idx, 1);
                    }
                } else {
                    const idx = ZONES.findIndex((z) => z.id === id);
                    if (idx > -1) ZONES.splice(idx, 1);
                }

                state.selected = null;
                state.editing = null;
                state.dirty = false;
                closeDeleteModal();
                renderTree();
                renderPanel();
                showToast(result.message || 'Deleted', 'coral');
            } catch (err) {
                showToast(err.message || 'The row could not be deleted.', 'coral');
            } finally {
                state.busy = false;
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modalEl && !modalEl.hidden) closeDeleteModal();
        });
    };

    /* ─── Add zone buttons ─────────────────────────────────────── */

    const onAddZone = () => {
        state.selected = { kind: '__new' };
        state.editing = {
            id: '__new',
            name: '',
            nameAr: '',
            fee: 50,
            timeFrom: 1,
            timeTo: 2,
            active: true,
        };
        state.langTab = 'en';
        state.dirty = false;
        renderPanel();
        if (isMobile()) openPanel();
    };

    const wireAddButtons = () => {
        for (const btn of $$('[data-abo-admin-shipping-add], [data-abo-admin-shipping-add-empty]')) {
            btn.addEventListener('click', onAddZone);
        }
    };

    /* ─── Global settings card ─────────────────────────────────── */

    const settingsEl     = $('[data-abo-admin-shipping-settings]');
    const settingsDirty  = $('[data-abo-admin-shipping-settings-dirty-dot]');
    const settingsSave   = $('[data-abo-admin-shipping-settings-save]');

    // Settings fields.
    const sFree       = $('[data-abo-admin-shipping-free-shipping]');
    const sThreshold  = $('[data-abo-admin-shipping-threshold]');
    const sThresholdErr = $('[data-abo-admin-shipping-threshold-error]');
    const sThresholdWrap = $('[data-abo-admin-shipping-threshold-wrap]');
    const sCod        = $('[data-abo-admin-shipping-cod-fee]');
    const sCodErr     = $('[data-abo-admin-shipping-cod-fee-error]');
    const sDefault    = $('[data-abo-admin-shipping-default-fee]');
    const sDefaultErr = $('[data-abo-admin-shipping-default-fee-error]');
    const sNote       = $('[data-abo-admin-shipping-note]');
    const sNoteLangBtns = $$('[data-abo-admin-shipping-note-lang] button');
    const sNoteHint   = $('[data-abo-admin-shipping-note-hint]');

    /** Settings state (kept in module scope so it's not reset on every render).
     *  Seeded from window.__SHIPPING_SETTINGS__ (ShippingSetting table) and
     *  mapped from the server's snake_case keys onto the shape used here. */
    const serverSettings = window.__SHIPPING_SETTINGS__ ?? {};

    const settings = {
        freeShipping: !!serverSettings.free_shipping_enabled,
        threshold: parseInt(serverSettings.free_shipping_threshold, 10) || 0,
        codFee: parseFloat(serverSettings.cod_fee) || 0,
        defaultFee: parseFloat(serverSettings.default_fee) || 0,
        note: { en: serverSettings.note_en || '', ar: serverSettings.note_ar || '' },
    };

    /** Validate that a settings number is a valid digit string. */
    const validateNumber = (raw) => {
        const v = (raw || '').toString().trim();
        if (v === '') return null;
        if (!/^\d+$/.test(v)) return null;
        const n = parseInt(v, 10);
        if (!isFinite(n) || n < 0) return null;
        return n;
    };

    /** Generic handler for digits-only number inputs (with validation). */
    const wireNumberInput = (input, errEl, onChange) => {
        if (!input) return;
        input.addEventListener('input', () => {
            // Strip non-digits on the fly.
            const cleaned = input.value.replace(/\D+/g, '');
            if (cleaned !== input.value) input.value = cleaned;
            const valid = validateNumber(input.value);
            if (errEl) errEl.hidden = valid !== null;
            markSettingsDirty();
            if (typeof onChange === 'function') onChange(valid);
        });
    };

    const markSettingsDirty = () => {
        if (!settingsDirty) return;
        state.settingsDirty = true;
        settingsDirty.classList.add('is-visible');
    };

    const applyNoteLang = () => {
        for (const btn of sNoteLangBtns) {
            const active = btn.dataset.lang === state.noteLang;
            btn.classList.toggle('is-active', active);
            btn.setAttribute('aria-pressed', String(active));
            btn.setAttribute('aria-selected', String(active));
        }
        if (sNote) sNote.value = settings.note[state.noteLang] || '';
        if (sNoteHint) {
            sNoteHint.textContent = state.noteLang === 'ar'
                ? 'Showing the Arabic text at checkout.'
                : 'Showing the English text at checkout.';
        }
    };

    const syncThresholdDisabled = () => {
        if (!sThreshold || !sFree) return;
        const disabled = !sFree.checked;
        sThreshold.disabled = disabled;
        sThreshold.setAttribute('aria-disabled', String(disabled));
        if (sThresholdWrap) sThresholdWrap.dataset.disabled = String(disabled);
        const cell = sThreshold.closest('.abo-admin-shipping__currency');
        if (cell) cell.dataset.disabled = String(disabled);
    };

    const wireSettings = () => {
        if (!settingsEl) return;

        // Free shipping switch.
        if (sFree) {
            sFree.addEventListener('change', () => {
                settings.freeShipping = sFree.checked;
                sFree.setAttribute('aria-checked', String(sFree.checked));
                syncThresholdDisabled();
                markSettingsDirty();
            });
        }

        // Threshold input.
        wireNumberInput(sThreshold, sThresholdErr, (n) => {
            if (n != null) settings.threshold = n;
        });
        // COD fee.
        wireNumberInput(sCod, sCodErr, (n) => {
            if (n != null) settings.codFee = n;
        });
        // Default fee.
        wireNumberInput(sDefault, sDefaultErr, (n) => {
            if (n != null) settings.defaultFee = n;
        });

        // Note + EN/AR.
        for (const btn of sNoteLangBtns) {
            btn.addEventListener('click', () => {
                // Commit current note value before switching.
                if (sNote) settings.note[state.noteLang] = sNote.value;
                state.noteLang = btn.dataset.lang;
                applyNoteLang();
                markSettingsDirty();
            });
        }
        if (sNote) {
            sNote.addEventListener('input', () => {
                settings.note[state.noteLang] = sNote.value;
                markSettingsDirty();
            });
        }

        // Save.
        if (settingsSave) {
            settingsSave.addEventListener('click', async () => {
                // Final validation: a hidden error means invalid.
                const errs = [sThresholdErr, sCodErr, sDefaultErr];
                for (const e of errs) {
                    if (e && !e.hidden) return; // don't save while invalid
                }
                if (sNote) settings.note[state.noteLang] = sNote.value;

                state.busy = true;
                settingsSave.disabled = true;

                try {
                    const result = await request('POST', urlFor('settings'), {
                        free_shipping_enabled: settings.freeShipping,
                        free_shipping_threshold: settings.threshold,
                        cod_fee: settings.codFee,
                        default_fee: settings.defaultFee,
                        note_en: settings.note.en,
                        note_ar: settings.note.ar,
                    });

                    state.settingsDirty = false;
                    if (settingsDirty) settingsDirty.classList.remove('is-visible');
                    showToast(result.message || 'Settings saved', 'lime');
                } catch (err) {
                    showToast(err.message || 'The settings could not be saved.', 'coral');
                } finally {
                    state.busy = false;
                    settingsSave.disabled = false;
                }
            });
        }

        // Initial state — push the server-supplied settings into the form
        // so the inputs mirror the shipping_settings rows on first paint.
        if (sFree) {
            sFree.checked = settings.freeShipping;
            sFree.setAttribute('aria-checked', String(settings.freeShipping));
        }
        if (sThreshold) sThreshold.value = settings.threshold;
        if (sCod) sCod.value = settings.codFee;
        if (sDefault) sDefault.value = settings.defaultFee;

        syncThresholdDisabled();
        applyNoteLang();
    };

    /* ─── Initial render ─────────────────────────────────────────
     *
     * The zone tree arrives already hydrated from the server, so
     * this only paces the reveal: the skeleton is shown briefly
     * before renderTree() swaps in the real rows. */

    const skeletonDelayMs = 600;

    setTimeout(() => {
        renderTree();
        wireTreeGlobal();
        wireToolbar();
        wirePanel();
        wireModal();
        wireAddButtons();
        wireSettings();
        if (isMobile() && panelEl) panelEl.classList.remove('is-open');
    }, skeletonDelayMs);

    // Render immediately so the empty state shows if there are no zones.
    if (ZONES.length === 0) {
        if (skeletonEl) skeletonEl.hidden = true;
        renderTree();
        wireTreeGlobal();
        wireToolbar();
        wirePanel();
        wireModal();
        wireAddButtons();
        wireSettings();
    }

    // Re-render when the viewport crosses the mobile breakpoint so the
    // panel state stays in sync.
    window.addEventListener('resize', () => {
        if (!panelEl) return;
        if (!isMobile()) {
            panelEl.classList.remove('is-open');
            panelEl.removeAttribute('role');
            panelEl.removeAttribute('aria-modal');
            if (panelOverlayEl) panelOverlayEl.hidden = true;
        }
    });
})();