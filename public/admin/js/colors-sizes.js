/*
 * ABO Admin · Colors & Sizes — page-local script (front-end only).
 *
 * Pure DOM behaviour for the static demo:
 *   - COLORS: list of swatches with drag-to-reorder, search filter,
 *     active toggle, sticky-right edit panel with live preview, native
 *     color picker linked both ways with the hex text input, delete
 *     confirmation, unsaved-changes dot.
 *   - SIZES: groups of chips with drag-to-reorder inside a group,
 *     hidden chips use a dashed border + "hidden" tag, group-level
 *     visible toggle, per-chip modal to edit/add/delete, + ADD SIZE
 *     modal with a Group select, per-group + icon that preselects the
 *     group.
 *   - One shared delete-confirm modal + toast.
 *   - Keyboard: arrows + Enter on rows and chips; Delete does nothing
 *     (deletes only happen through the modal, per spec).
 *
 * No fetch / XHR / storage. The only persistence is the optional
 * `state` object that holds transient UI (selected color ID, dirty
 * flag, current editing size, etc.) — it is reset on every page load.
 *
 * Markup anchor points (every selector uses data-* attributes):
 *   [data-abo-admin-cs]                      — page root
 *   [data-abo-admin-cs-colors]               — colors section
 *   [data-abo-admin-cs-list]                 — color list <ul>
 *   [data-abo-admin-cs-search]               — toolbar search input
 *   [data-abo-admin-cs-skeleton]             — loading skeleton block
 *   [data-abo-admin-cs-state="empty|noresults"]
 *                                            — mutually exclusive states
 *   [data-abo-admin-cs-panel]                — right column edit panel
 *   [data-abo-admin-cs-panel-close]          — slide-over close button
 *   [data-abo-admin-cs-panel-overlay]        — slide-over backdrop
 *   [data-abo-admin-cs-sizes]                — sizes section
 *   [data-abo-admin-cs-modal]                — shared delete modal
 *   [data-abo-admin-cs-size-modal]           — size edit modal
 *   [data-abo-admin-cs-toast]                — single shared toast
 *
 * No external dependencies. Loaded only on /admin/colors-sizes.
 */

(function () {
    'use strict';

    /* ─── Live data from the server ──────────────────────────────
     *
     * Initialised from window globals emitted by the Blade view:
     *   window.__COLORS__       — flat list ordered by sort_order / name
     *   window.__SIZE_GROUPS__  — { tops: [...], bottoms: [...], general: [...] }
     *
     * Shapes:
     *   Color:   { id, name, nameAr, hex, products, active }
     *   Size:    { id, label, products, visible }
     *   Group:   { id, name, note, visible, sizes: [Size, ...] }
     *
     * Persistence is now driven by fetch() calls to the protected
     * /admin/colors and /admin/sizes routes; create / update / delete
     * buttons no longer mutate state in place.
     */

    const GROUP_META = {
        tops:    { name: 'Letter sizes',  note: 'Tops, hoodies, jackets, sweaters.' },
        bottoms: { name: 'Pants sizes',   note: 'Pants, joggers, shorts.' },
        general: { name: 'Free size',     note: 'Accessories, one-size items, scarves, bags.' },
    };

    const COLORS = (Array.isArray(window.__COLORS__) ? window.__COLORS__ : []).map((c) => ({
        id: String(c.id),
        name: c.name || '',
        nameAr: c.nameAr || '',
        hex: (c.hex || '#000000').toUpperCase(),
        products: c.products || 0,
        active: !!c.active,
    }));

    const buildSizeGroupsFromWindow = () => {
        const raw = window.__SIZE_GROUPS__ || {};
        const groupIds = ['tops', 'bottoms', 'general'];
        return groupIds.map((gid) => {
            const list = Array.isArray(raw[gid]) ? raw[gid] : [];
            return {
                id: gid,
                name: GROUP_META[gid]?.name || gid,
                note: GROUP_META[gid]?.note || '',
                // The group is shown when every size in it is shown.
                visible: list.length > 0 && list.every((s) => !!s.active),
                sizes: list.map((s) => ({
                    id: String(s.id),
                    label: s.name || '',
                    products: s.products || 0,
                    visible: !!s.active,
                })),
            };
        });
    };

    const SIZE_GROUPS = buildSizeGroupsFromWindow();

    /* ─── Local UI state (never persisted) ────────────────────── */

    const state = {
        // Colors
        colorFilter: '',
        selectedColorId: null,
        editingColor: null,    // { id, name, nameAr, hex, active }
        colorDirty: false,
        // Sizes
        sizeModal: null,       // { mode: 'edit'|'add', sizeId?, groupId, label, visible }
        deleteSizeTarget: null,// { groupId, sizeId }
        // Panels
        allExpanded: false,    // (kept for parity; no accordion on this page)
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

    const isHex = (raw = '') => /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(String(raw).trim());
    const expandHex = (raw = '') => {
        const t = String(raw).trim();
        const m = t.match(/^#([0-9a-f]{3})$/i);
        return m ? '#' + m[1].split('').map((c) => c + c).join('') : t;
    };

    const isMobile = () => window.matchMedia('(max-width: 1100px)').matches;

    const productCountText = (n) => n === 0 ? '0 products' : (n === 1 ? '1 product' : `${n} products`);

    /* ─── Toast (single shared instance) ───────────────────────── */

    let toastTimer = null;
    const toastEls = () => ({
        wrap:  $('[data-abo-admin-cs-toast]'),
        text:  $('[data-abo-admin-cs-toast-text]'),
        dot:   $('[data-abo-admin-cs-toast-dot]'),
    });
    const showToast = (message, tone = 'lime') => {
        const t = toastEls();
        if (!t.wrap) return;
        if (toastTimer) clearTimeout(toastTimer);
        t.text.textContent = message;
        if (t.dot) t.dot.className = 'abo-admin-colors-sizes__toast-dot abo-admin-colors-sizes__toast-dot--' + tone;
        t.wrap.hidden = false;
        toastTimer = setTimeout(() => { t.wrap.hidden = true; }, 2400);
    };

    /* ─── Page mount + skeleton ────────────────────────────────── */

    const root = $('[data-abo-admin-cs]');
    if (!root) return;

    const colorsListEl     = $('[data-abo-admin-cs-list]');
    const colorSkeletonEl  = $('[data-abo-admin-cs-skeleton]');
    const colorStateEmpty  = $('[data-abo-admin-cs-state="empty"]');
    const colorStateNoRes  = $('[data-abo-admin-cs-state="noresults"]');
    const colorSearchEl    = $('[data-abo-admin-cs-search]');
    const searchClearEl    = root.querySelector('.abo-admin-colors-sizes__search-clear');

    /* ─── Color rendering ──────────────────────────────────────── */

    const colorById = (id) => COLORS.find((c) => c.id === id);
    const orderIndex = (id) => COLORS.findIndex((c) => c.id === id);

    /* ─── Talking to the server ────────────────────────────────── */

    const csrfToken = () =>
        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    /** The colors as the server last confirmed them. A refused change
     *  puts the list back the way the database holds it. */
    let serverColors = COLORS.map((color) => ({ ...color }));

    const restoreServerColors = () => {
        COLORS.length = 0;
        serverColors.forEach((color) => COLORS.push({ ...color }));
    };

    /** Put a color the server answered with back into the list. */
    const replaceColor = (saved) => {
        for (const list of [COLORS, serverColors]) {
            const idx = list.findIndex((c) => String(c.id) === String(saved.id));
            if (idx > -1) {
                list[idx] = saved;
            } else {
                list.push(saved);
            }
        }
    };

    /** The color as the server sends it. */
    const colorFromPayload = (payload) => ({
        id: String(payload.id),
        name: payload.name || '',
        nameAr: payload.nameAr || '',
        hex: (payload.hex || '#000000').toUpperCase(),
        products: payload.products || 0,
        active: !!payload.active,
    });

    /** Write the new order of the colors to the server. */
    const persistColorOrder = async () => {
        try {
            const res = await fetch('/admin/colors/reorder', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ colors: COLORS.map((c) => Number(c.id)) }),
            });

            const json = await res.json();

            if (!res.ok) {
                restoreServerColors();
                renderColors();
                showToast(json.message || 'The order could not be saved.', 'coral');

                return;
            }

            // The server owns the order — draw what it answered.
            if (Array.isArray(json.colors)) {
                serverColors = json.colors.map(colorFromPayload);
                COLORS.length = 0;
                serverColors.forEach((color) => COLORS.push({ ...color }));
                renderColors();
            }

            showToast(json.message || 'Order updated.', 'lime');
        } catch (err) {
            restoreServerColors();
            renderColors();
            showToast('The order could not be saved.', 'coral');
        }
    };

    /** Switch a color on or off. The server decides. */
    const toggleColor = async (color, next) => {
        try {
            const res = await fetch(`/admin/colors/${color.id}/toggle`, {
                method: 'PATCH',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
            });

            const json = await res.json();

            if (!res.ok) {
                restoreServerColors();
                renderColors();
                showToast(json.message || 'The color could not be switched.', 'coral');

                return;
            }

            if (json.color) {
                replaceColor(colorFromPayload(json.color));
            }

            renderColors();

            // If the toggled row is also the selected one, sync the
            // panel switch.
            if (json.color && state.selectedColorId === String(color.id) && state.editingColor) {
                state.editingColor.active = json.color.active;
                renderColorPanel();
            }

            showToast(json.message || (next ? 'Activated' : 'Deactivated'), 'lime');
        } catch (err) {
            restoreServerColors();
            renderColors();
            showToast('The color could not be switched.', 'coral');
        }
    };

    const filterColorMatches = (c) => {
        if (!state.colorFilter) return true;
        const q = state.colorFilter.toLowerCase();
        return (
            c.name.toLowerCase().includes(q) ||
            (c.nameAr && c.nameAr.toLowerCase().includes(q)) ||
            c.hex.toLowerCase().includes(q)
        );
    };

    const buildColorRow = (c) => {
        const li = ce('li', {
            role: 'option',
            tabindex: '-1',
            'aria-selected': String(state.selectedColorId === c.id),
            dataset: { id: c.id, aboAdminCsItem: '' },
            class: 'abo-admin-colors-sizes__row' + (state.selectedColorId === c.id ? ' is-selected' : ''),
        });

        // Drag handle
        const handle = ce('span', {
            class: 'abo-admin-colors-sizes__handle',
            'aria-label': 'Drag to reorder',
            role: 'button',
            tabindex: '0',
            draggable: 'true',
            dataset: { aboAdminCsHandle: c.id },
            html: '<svg viewBox="0 0 6 10" aria-hidden="true"><circle cx="1.5" cy="1.5" r="1"/><circle cx="4.5" cy="1.5" r="1"/><circle cx="1.5" cy="5" r="1"/><circle cx="4.5" cy="5" r="1"/><circle cx="1.5" cy="8.5" r="1"/><circle cx="4.5" cy="8.5" r="1"/></svg>',
        });
        li.appendChild(handle);

        // Color swatch (CSS variable `swatch` is set inline so the
        // background reflects the chosen hex)
        const swatch = ce('div', {
            class: 'abo-admin-colors-sizes__swatch',
            role: 'img',
            'aria-label': 'Color swatch ' + c.hex,
            style: { ['--swatch']: c.hex },
        });
        li.appendChild(swatch);

        // Meta: name + (Arabic name · hex)
        const meta = ce('div', { class: 'abo-admin-colors-sizes__meta' },
            ce('p', { class: 'abo-admin-colors-sizes__name' }, c.name),
            ce('p', { class: 'abo-admin-colors-sizes__sub' },
                ce('span', { class: 'abo-admin-colors-sizes__sub-ar' }, c.nameAr || ''),
                ce('span', { class: 'abo-admin-colors-sizes__hex' }, c.hex),
            ),
        );
        li.appendChild(meta);

        // Count
        const countClass = [
            'abo-admin-colors-sizes__count',
            c.products === 0 ? 'abo-admin-colors-sizes__count--empty' : '',
            c.active === false ? 'abo-admin-colors-sizes__count--inactive' : '',
        ].filter(Boolean).join(' ');
        li.appendChild(ce('p', { class: countClass }, productCountText(c.products)));

        // 28x14 active toggle
        const toggle = ce('label', {
            class: 'abo-admin-colors-sizes__toggle',
            'aria-label': (c.active ? 'Deactivate' : 'Activate') + ' ' + c.name,
        });
        const input = ce('input', {
            type: 'checkbox',
            'aria-checked': String(c.active),
            dataset: { aboAdminCsToggle: c.id },
        });
        input.checked = c.active;
        toggle.appendChild(input);
        toggle.appendChild(ce('span', { class: 'abo-admin-colors-sizes__toggle-track' }));
        toggle.appendChild(ce('span', { class: 'abo-admin-colors-sizes__toggle-thumb' }));
        li.appendChild(toggle);

        return li;
    };

    const renderColors = () => {
        if (!colorsListEl) return;
        if (COLORS.length === 0) {
            colorsListEl.hidden = true;
            if (colorStateEmpty) colorStateEmpty.hidden = false;
            if (colorStateNoRes) colorStateNoRes.hidden = true;
            if (colorSkeletonEl) colorSkeletonEl.hidden = true;
            return;
        }
        const matches = COLORS.filter(filterColorMatches);
        if (state.colorFilter && matches.length === 0) {
            colorsListEl.hidden = true;
            if (colorStateEmpty) colorStateEmpty.hidden = true;
            if (colorStateNoRes) colorStateNoRes.hidden = false;
        } else {
            colorsListEl.hidden = false;
            if (colorStateEmpty) colorStateEmpty.hidden = true;
            if (colorStateNoRes) colorStateNoRes.hidden = true;
        }
        if (colorSkeletonEl) colorSkeletonEl.hidden = true;

        colorsListEl.innerHTML = '';
        for (const c of matches) {
            const li = buildColorRow(c);
            bindColorRow(li);
            colorsListEl.appendChild(li);
        }
    };

    /* ─── Color row interactions ───────────────────────────────── */

    const onColorRowActivate = (li) => {
        const id = li.dataset.id;
        const c = colorById(id);
        if (!c) return;
        state.selectedColorId = id;
        state.editingColor = {
            id: c.id,
            name: c.name,
            nameAr: c.nameAr || '',
            hex: c.hex,
            active: c.active,
        };
        state.colorDirty = false;
        renderColors();
        renderColorPanel();
        if (isMobile()) openColorPanel();
    };

    const bindColorRow = (li) => {
        const handle = li.querySelector('[data-abo-admin-cs-handle]');
        if (handle) {
            handle.addEventListener('dragstart', (e) => {
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', handle.dataset.aboAdminCsHandle);
                li.classList.add('is-dragging');
            });
            handle.addEventListener('dragend', () => {
                li.classList.remove('is-dragging');
                $$('.abo-admin-colors-sizes__row').forEach((el) => {
                    el.classList.remove('is-drop-before', 'is-drop-after');
                });
            });
        }

        li.addEventListener('click', (e) => {
            // Don't activate when clicking interactive inner elements.
            if (e.target.closest('[data-abo-admin-cs-toggle],' +
                '[data-abo-admin-cs-handle],' +
                'label, input, button, .abo-admin-colors-sizes__handle')) return;
            onColorRowActivate(li);
        });

        li.addEventListener('dragover', (e) => {
            if (!li.classList.contains('is-dragging')) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                const rect = li.getBoundingClientRect();
                const before = (e.clientY - rect.top) < rect.height / 2;
                $$('.abo-admin-colors-sizes__row').forEach((el) => {
                    el.classList.remove('is-drop-before', 'is-drop-after');
                });
                li.classList.add(before ? 'is-drop-before' : 'is-drop-after');
            }
        });

        li.addEventListener('drop', (e) => {
            e.preventDefault();
            const draggedId = e.dataTransfer.getData('text/plain');
            if (!draggedId || draggedId === li.dataset.id) return;
            const before = li.classList.contains('is-drop-before');

            // The color is taken out of the list before it is put back
            // in its new place — it has to be held on the way.
            const draggedIdx = orderIndex(draggedId);
            const dragged = draggedIdx > -1 ? COLORS[draggedIdx] : null;
            if (!dragged) return;

            COLORS.splice(draggedIdx, 1);
            const targetIdx = orderIndex(li.dataset.id);
            COLORS.splice(before ? targetIdx : targetIdx + 1, 0, dragged);

            $$('.abo-admin-colors-sizes__row').forEach((el) => {
                el.classList.remove('is-drop-before', 'is-drop-after');
            });
            renderColors();
            persistColorOrder();
        });

        li.addEventListener('keydown', (e) => onColorKeydown(e, li));
    };

    const flattenColorRows = () => $$('[data-abo-admin-cs-list] li[data-id]');

    const onColorKeydown = (e, row) => {
        const rows = flattenColorRows();
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
            case 'Enter':
            case ' ': {
                e.preventDefault();
                onColorRowActivate(row);
                break;
            }
            case 'Delete': {
                // Per spec: do nothing. Delete only via the modal.
                e.preventDefault();
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

    const wireColorListGlobal = () => {
        // Delegated toggle click → keep row count style in sync.
        colorsListEl.addEventListener('click', (e) => {
            const toggleInput = e.target.closest('[data-abo-admin-cs-toggle]');
            if (toggleInput) {
                e.stopPropagation();
                const id = toggleInput.dataset.aboAdminCsToggle;
                const c = colorById(id);
                if (!c) return;

                // The switch moves right away; the server has the last
                // word and the list is redrawn from what it answers.
                c.active = toggleInput.checked;
                toggleInput.setAttribute('aria-checked', String(toggleInput.checked));

                const row = toggleInput.closest('li[data-id]');
                const countEl = row && row.querySelector('.abo-admin-colors-sizes__count');
                if (countEl) {
                    countEl.classList.toggle('abo-admin-colors-sizes__count--inactive', c.active === false);
                    countEl.textContent = productCountText(c.products);
                }
                // Sync the panel switch if this row is the selected one.
                if (state.selectedColorId === id && state.editingColor) {
                    state.editingColor.active = toggleInput.checked;
                    renderColorPanel();
                }

                toggleColor(c, toggleInput.checked);
                return;
            }
        });

        colorsListEl.addEventListener('focusin', (e) => {
            const li = e.target.closest('li[data-id]');
            if (!li) return;
            $$('[data-abo-admin-cs-list] li[data-id]').forEach((r) => r.tabIndex = -1);
            li.tabIndex = 0;
        });
    };

    /* ─── Color toolbar (search + add) ─────────────────────────── */

    const wireColorToolbar = () => {
        if (colorSearchEl) {
            colorSearchEl.addEventListener('input', () => {
                state.colorFilter = colorSearchEl.value.trim();
                renderColors();
            });
        }
        if (searchClearEl && colorSearchEl) {
            searchClearEl.addEventListener('click', () => {
                colorSearchEl.value = '';
                state.colorFilter = '';
                renderColors();
                colorSearchEl.focus();
            });
        }
        const addBtn = $('[data-abo-admin-cs-add-color]');
        if (addBtn) addBtn.addEventListener('click', () => openColorAdd());
        const addBtnEmpty = $('[data-abo-admin-cs-add-color-empty]');
        if (addBtnEmpty) addBtnEmpty.addEventListener('click', () => openColorAdd());
    };

    const openColorAdd = () => {
        state.selectedColorId = '__new';
        state.editingColor = { id: '__new', name: '', nameAr: '', hex: '#000000', active: true };
        state.colorDirty = false;
        renderColors();
        renderColorPanel();
        if (isMobile()) openColorPanel();
    };

    /* ─── Color edit panel ─────────────────────────────────────── */

    const panelEl       = $('[data-abo-admin-cs-panel]');
    const panelOverlay  = $('[data-abo-admin-cs-panel-overlay]');
    const panelTitleEl  = $('[data-abo-admin-cs-panel-title-text]');
    const dirtyDotEl    = $('[data-abo-admin-cs-dirty-dot]');
    const panelFormEl   = $('[data-abo-admin-cs-panel-form]');
    const panelEmptyEl  = $('[data-abo-admin-cs-panel-no-selection]');
    const panelCloseEl  = $('[data-abo-admin-cs-panel-close]');
    const nameInput     = $('[data-abo-admin-cs-name]');
    const nameArInput   = $('[data-abo-admin-cs-name-ar]');
    const hexInput      = $('[data-abo-admin-cs-hex]');
    const hexPicker     = $('[data-abo-admin-cs-hex-pick]');
    const hexErrorEl    = $('[data-abo-admin-cs-hex-error]');
    const previewEl     = $('[data-abo-admin-cs-preview]');
    const previewImgEl  = $('[data-abo-admin-cs-preview-img]');
    const previewDotEl  = $('[data-abo-admin-cs-preview-dot]');
    const activeInput   = $('[data-abo-admin-cs-active]');
    const saveBtn       = $('[data-abo-admin-cs-save]');
    const deleteBtn     = $('[data-abo-admin-cs-delete]');
    const deleteNoteEl  = $('[data-abo-admin-cs-delete-note]');

    const renderColorPanel = () => {
        if (!panelEl) return;

        if (!state.editingColor) {
            if (panelFormEl)  panelFormEl.hidden = true;
            if (panelEmptyEl) panelEmptyEl.hidden = false;
            return;
        }
        if (panelFormEl)  panelFormEl.hidden = false;
        if (panelEmptyEl) panelEmptyEl.hidden = true;

        const e = state.editingColor;
        const existing = colorById(e.id);

        if (panelTitleEl) {
            panelTitleEl.textContent = existing ? 'EDIT COLOR' : 'NEW COLOR';
        }

        if (nameInput)   nameInput.value = e.name || '';
        if (nameArInput) nameArInput.value = e.nameAr || '';
        if (hexInput)    hexInput.value = e.hex || '';
        if (hexPicker) {
            hexPicker.value = isHex(e.hex) ? expandHex(e.hex) : '#000000';
        }
        renderPreview(e.hex);

        // Toggle "Active (visible in store)"
        if (activeInput) {
            activeInput.checked = !!e.active;
            activeInput.setAttribute('aria-checked', String(activeInput.checked));
        }

        // Delete rules: disabled if the color is used by products.
        const hasProducts = existing && existing.products > 0;
        if (deleteBtn) deleteBtn.disabled = hasProducts;
        if (deleteNoteEl) {
            if (hasProducts) {
                deleteNoteEl.textContent = 'Remove it from its products first.';
                deleteNoteEl.hidden = false;
            } else {
                deleteNoteEl.hidden = true;
            }
        }

        if (dirtyDotEl) dirtyDotEl.classList.toggle('is-visible', state.colorDirty);
        // Clear any pending error indicator until the user retypes.
        applyHexValidation(hexInput ? hexInput.value : e.hex);
    };

    const renderPreview = (hex) => {
        const safe = isHex(hex) ? expandHex(hex) : '#808080';
        if (previewEl) {
            previewEl.style.setProperty('--swatch', safe);
        }
        if (previewImgEl) {
            previewImgEl.style.setProperty('--swatch', safe);
        }
        if (previewDotEl) {
            previewDotEl.style.setProperty('--swatch', safe);
        }
    };

    const applyHexValidation = (raw) => {
        const valid = isHex(raw);
        if (hexErrorEl) hexErrorEl.hidden = valid;
        if (hexInput) {
            hexInput.closest('.abo-admin-colors-sizes__field').classList.toggle(
                'abo-admin-colors-sizes__field--error',
                !valid && (raw || '').length > 0
            );
        }
    };

    const markColorDirty = () => {
        if (!state.colorDirty) {
            state.colorDirty = true;
            if (dirtyDotEl) dirtyDotEl.classList.add('is-visible');
        }
    };

    const openColorPanel = () => {
        if (!panelEl) return;
        if (isMobile()) {
            panelEl.classList.add('is-open');
            panelEl.setAttribute('role', 'dialog');
            panelEl.setAttribute('aria-modal', 'true');
            if (panelCloseEl) panelCloseEl.focus();
            if (panelOverlay) panelOverlay.hidden = false;
        }
    };

    const closeColorPanel = () => {
        if (!panelEl) return;
        if (isMobile()) {
            panelEl.classList.remove('is-open');
            if (panelOverlay) panelOverlay.hidden = true;
        }
    };

    const wireColorPanel = () => {
        if (panelCloseEl) {
            panelCloseEl.addEventListener('click', closeColorPanel);
        }
        if (panelOverlay) {
            panelOverlay.addEventListener('click', closeColorPanel);
        }

        if (nameInput) {
            nameInput.addEventListener('input', () => {
                if (!state.editingColor) return;
                state.editingColor.name = nameInput.value;
                markColorDirty();
            });
        }
        if (nameArInput) {
            nameArInput.addEventListener('input', () => {
                if (!state.editingColor) return;
                state.editingColor.nameAr = nameArInput.value;
                markColorDirty();
            });
        }
        if (hexInput) {
            hexInput.addEventListener('input', () => {
                if (!state.editingColor) return;
                const raw = hexInput.value;
                state.editingColor.hex = raw;
                applyHexValidation(raw);
                if (isHex(raw) && hexPicker) {
                    hexPicker.value = expandHex(raw);
                    renderPreview(raw);
                }
                markColorDirty();
            });
        }
        if (hexPicker) {
            hexPicker.addEventListener('input', () => {
                if (!state.editingColor) return;
                const v = (hexPicker.value || '#000000').toUpperCase();
                state.editingColor.hex = v;
                if (hexInput) hexInput.value = v;
                applyHexValidation(v);
                renderPreview(v);
                markColorDirty();
            });
        }
        if (activeInput) {
            activeInput.addEventListener('change', () => {
                if (!state.editingColor) return;
                state.editingColor.active = activeInput.checked;
                activeInput.setAttribute('aria-checked', String(activeInput.checked));
                markColorDirty();
            });
        }

        if (saveBtn) {
            saveBtn.addEventListener('click', async () => {
                if (!state.editingColor) return;
                const e = state.editingColor;
                if (!isHex(e.hex)) {
                    applyHexValidation(e.hex);
                    if (hexInput) hexInput.focus();
                    return;
                }
                const existing = colorById(e.id);
                const normalised = expandHex(e.hex).toUpperCase();
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                const fd = new FormData();
                const isNew = !existing || String(e.id).startsWith('co-new-');
                if (!isNew) {
                    fd.append('_method', 'PUT');
                }
                fd.append('name', e.name || '');
                fd.append('name_ar', e.nameAr || '');
                fd.append('hex_code', normalised);
                fd.append('is_active', e.active ? '1' : '0');

                saveBtn.disabled = true;
                const originalText = saveBtn.textContent;
                saveBtn.textContent = 'SAVING...';

                try {
                    const url = isNew ? '/admin/colors' : `/admin/colors/${existing.id}`;
                    const res = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'Accept': 'application/json',
                        },
                        body: fd,
                    });
                    const json = await res.json();
                    if (!res.ok) {
                        const errMsg = json.message || Object.values(json.errors || {})[0]?.[0] || 'Validation error';
                        showToast(errMsg, 'coral');
                        return;
                    }
                    const saved = json.color;
                    const nodeData = {
                        id: String(saved.id),
                        name: saved.name,
                        nameAr: saved.nameAr || '',
                        hex: (saved.hex || '').toUpperCase(),
                        products: saved.products || 0,
                        active: !!saved.active,
                    };
                    if (!isNew) {
                        const idx = COLORS.findIndex((c) => String(c.id) === String(existing.id));
                        if (idx > -1) COLORS[idx] = nodeData;
                    } else {
                        COLORS.push(nodeData);
                        e.id = nodeData.id;
                        state.selectedColorId = nodeData.id;
                    }
                    state.colorDirty = false;
                    renderColors();
                    renderColorPanel();
                    showToast(json.message || 'Color saved.', 'lime');
                } catch (err) {
                    showToast('Failed to save color.', 'coral');
                } finally {
                    saveBtn.disabled = false;
                    saveBtn.textContent = originalText;
                }
            });
        }

        if (deleteBtn) {
            deleteBtn.addEventListener('click', () => {
                if (deleteBtn.disabled) return;
                if (!state.editingColor) return;
                if (state.editingColor.id === '__new') return;
                openDeleteModal();
            });
        }
    };

    /* ─── Sizes rendering ──────────────────────────────────────── */

    const sizesRoot = $('[data-abo-admin-cs-sizes]');
    const groupById = (id) => SIZE_GROUPS.find((g) => g.id === id);
    const sizeById = (gid, sid) => {
        const g = groupById(gid);
        if (!g) return null;
        return g.sizes.find((s) => s.id === sid) || null;
    };

    /** Put the sizes the server answered with back into their groups. */
    const applySizesFromServer = (sizes) => {
        const byGroup = new Map();

        for (const size of sizes) {
            const list = byGroup.get(size.group) || [];
            list.push({
                id: String(size.id),
                label: size.name || '',
                products: size.products || 0,
                visible: !!size.active,
            });
            byGroup.set(size.group, list);
        }

        for (const group of SIZE_GROUPS) {
            const list = byGroup.get(group.id) || [];
            group.sizes = list;
            group.visible = list.length > 0 && list.every((s) => s.visible);
        }
    };

    /** Write the new order of the sizes in a group to the server. */
    const persistSizeOrder = async (group) => {
        try {
            const res = await fetch('/admin/sizes/reorder', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ sizes: group.sizes.map((s) => Number(s.id)) }),
            });

            const json = await res.json();

            if (!res.ok) {
                showToast(json.message || 'The order could not be saved.', 'coral');

                return;
            }

            // The server owns the order — draw what it answered.
            if (Array.isArray(json.sizes)) {
                applySizesFromServer(json.sizes);
            }

            renderSizes();
            showToast(json.message || 'Order updated.', 'lime');
        } catch (err) {
            showToast('The order could not be saved.', 'coral');
        }
    };

    /** Show or hide every size of a group at once. */
    const toggleSizeGroup = async (group, next) => {
        try {
            const res = await fetch(`/admin/sizes/group/${group.id}/toggle`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ is_active: next }),
            });

            const json = await res.json();

            if (!res.ok) {
                renderSizes();
                showToast(json.message || 'The group could not be switched.', 'coral');

                return;
            }

            if (Array.isArray(json.sizes)) {
                applySizesFromServer(json.sizes);
            }

            renderSizes();
            showToast(json.message || (next ? 'Group visible in store' : 'Group hidden in store'), 'lime');
        } catch (err) {
            showToast('The group could not be switched.', 'coral');
        }
    };

    const buildSizeGroup = (g) => {
        const card = ce('div', {
            class: 'abo-admin-colors-sizes__group',
            dataset: { id: g.id, aboAdminCsGroup: '' },
        });

        // Head: title + visible toggle + add button
        const head = ce('div', { class: 'abo-admin-colors-sizes__group-head' },
            ce('h3', { class: 'abo-admin-colors-sizes__group-title' }, g.name),
        );
        // Group-level "Show group in store" toggle
        const toggle = ce('label', {
            class: 'abo-admin-colors-sizes__toggle',
            'aria-label': (g.visible ? 'Hide' : 'Show') + ' ' + g.name + ' in store',
        });
        const toggleInput = ce('input', {
            type: 'checkbox',
            'aria-checked': String(g.visible),
            dataset: { aboAdminCsGroupToggle: g.id },
        });
        toggleInput.checked = g.visible;
        toggle.appendChild(toggleInput);
        toggle.appendChild(ce('span', { class: 'abo-admin-colors-sizes__toggle-track' }));
        toggle.appendChild(ce('span', { class: 'abo-admin-colors-sizes__toggle-thumb' }));
        const wrap = ce('span', {
            class: 'abo-admin-colors-sizes__group-toggle',
            'aria-label': 'Show group in store',
        },
            ce('span', { class: 'abo-admin-colors-sizes__group-toggle-label' }, 'SHOW'),
            toggle,
        );
        head.appendChild(wrap);

        // Add-size-to-this-group icon button
        head.appendChild(ce('button', {
            type: 'button',
            class: 'abo-admin-colors-sizes__group-add',
            'aria-label': 'Add size to ' + g.name,
            dataset: { aboAdminCsGroupAdd: g.id },
            html: '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linecap="round"/></svg>',
        }));

        card.appendChild(head);
        card.appendChild(ce('p', { class: 'abo-admin-colors-sizes__group-note' }, g.note));

        // Chips
        const chips = ce('div', { class: 'abo-admin-colors-sizes__chips' });
        for (const s of g.sizes) chips.appendChild(buildChip(g, s));
        // Empty state inside the group
        if (g.sizes.length === 0) {
            chips.appendChild(ce('p', { class: 'abo-admin-colors-sizes__group-empty' },
                'No sizes in this group yet.'));
        }
        card.appendChild(chips);

        return card;
    };

    const buildChip = (g, s) => {
        const btn = ce('button', {
            type: 'button',
            class: 'abo-admin-colors-sizes__chip' + (s.visible ? '' : ' abo-admin-colors-sizes__chip--hidden'),
            dataset: { groupId: g.id, sizeId: s.id, aboAdminCsChip: '' },
            'aria-label': (s.visible ? 'Edit size' : 'Edit hidden size') + ' ' + s.label,
        });
        if (!s.visible) {
            btn.appendChild(ce('span', {
                class: 'abo-admin-colors-sizes__chip-hidden-tag',
                'aria-hidden': 'true',
            }, 'HIDDEN'));
        }
        btn.appendChild(ce('span', { class: 'abo-admin-colors-sizes__chip-label' }, s.label));
        btn.appendChild(ce('p', { class: 'abo-admin-colors-sizes__chip-count' },
            s.products === 0 ? '—' : (s.products === 1 ? '1 product' : `${s.products} products`)));
        return btn;
    };

    const renderSizes = () => {
        if (!sizesRoot) return;
        sizesRoot.innerHTML = '';
        for (const g of SIZE_GROUPS) sizesRoot.appendChild(buildSizeGroup(g));
    };

    /* ─── Size chip interactions ───────────────────────────────── */

    const wireSizeChipsGlobal = () => {
        // Delegated click on chips → open edit modal
        sizesRoot.addEventListener('click', (e) => {
            const chip = e.target.closest('[data-abo-admin-cs-chip]');
            if (chip) {
                openSizeModal({
                    mode: 'edit',
                    groupId: chip.dataset.groupId,
                    sizeId: chip.dataset.sizeId,
                });
                return;
            }
            const groupAdd = e.target.closest('[data-abo-admin-cs-group-add]');
            if (groupAdd) {
                openSizeModal({
                    mode: 'add',
                    groupId: groupAdd.dataset.aboAdminCsGroupAdd,
                });
                return;
            }
            const groupToggle = e.target.closest('[data-abo-admin-cs-group-toggle]');
            if (groupToggle) {
                e.stopPropagation();
                const id = groupToggle.dataset.aboAdminCsGroupToggle;
                const g = groupById(id);
                if (g) {
                    // The switch moves right away; the server writes
                    // every size of the group.
                    g.visible = groupToggle.checked;
                    groupToggle.setAttribute('aria-checked', String(groupToggle.checked));
                    toggleSizeGroup(g, groupToggle.checked);
                }
                return;
            }
        });

        // Drag-and-drop on chips: chip → chip within a group.
        let dragSize = null;
        sizesRoot.addEventListener('dragstart', (e) => {
            const chip = e.target.closest('[data-abo-admin-cs-chip]');
            if (!chip) return;
            // Only the chip itself is the drag handle (cursor: grab on hover).
            // We allow drag of the whole chip.
            dragSize = { groupId: chip.dataset.groupId, sizeId: chip.dataset.sizeId };
            chip.classList.add('is-dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', chip.dataset.sizeId);
        });
        sizesRoot.addEventListener('dragend', (e) => {
            const chip = e.target.closest('[data-abo-admin-cs-chip]');
            if (chip) chip.classList.remove('is-dragging');
            $$('.abo-admin-colors-sizes__chip').forEach((el) => {
                el.classList.remove('is-drop-before', 'is-drop-after');
            });
            dragSize = null;
        });
        sizesRoot.addEventListener('dragover', (e) => {
            const chip = e.target.closest('[data-abo-admin-cs-chip]');
            if (!chip || !dragSize) return;
            if (chip.dataset.groupId !== dragSize.groupId) return; // restrict to same group
            if (chip.classList.contains('is-dragging')) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            const rect = chip.getBoundingClientRect();
            const before = (e.clientX - rect.left) < rect.width / 2;
            $$('.abo-admin-colors-sizes__chip').forEach((el) => {
                el.classList.remove('is-drop-before', 'is-drop-after');
            });
            chip.classList.add(before ? 'is-drop-before' : 'is-drop-after');
        });
        sizesRoot.addEventListener('drop', (e) => {
            const chip = e.target.closest('[data-abo-admin-cs-chip]');
            if (!chip || !dragSize) return;
            if (chip.dataset.groupId !== dragSize.groupId) return;
            if (chip.dataset.sizeId === dragSize.sizeId) return;
            e.preventDefault();
            const before = chip.classList.contains('is-drop-before');
            const g = groupById(dragSize.groupId);
            if (!g) return;
            const draggedIdx = g.sizes.findIndex((s) => s.id === dragSize.sizeId);
            const dragged = draggedIdx > -1 ? g.sizes[draggedIdx] : null;
            if (!dragged) return;
            g.sizes.splice(draggedIdx, 1);
            const targetIdx = g.sizes.findIndex((s) => s.id === chip.dataset.sizeId);
            g.sizes.splice(before ? targetIdx : targetIdx + 1, 0, dragged);
            $$('.abo-admin-colors-sizes__chip').forEach((el) => {
                el.classList.remove('is-drop-before', 'is-drop-after');
            });
            renderSizes();
            persistSizeOrder(g);
        });

        // Keyboard: arrow keys move between chips in the same group.
        sizesRoot.addEventListener('keydown', (e) => {
            const chip = e.target.closest('[data-abo-admin-cs-chip]');
            if (!chip) return;
            const g = groupById(chip.dataset.groupId);
            if (!g) return;
            const idx = g.sizes.findIndex((s) => s.id === chip.dataset.sizeId);
            if (idx === -1) return;
            switch (e.key) {
                case 'ArrowRight':
                case 'ArrowDown': {
                    e.preventDefault();
                    const next = g.sizes[idx + 1];
                    if (next) {
                        const el = sizesRoot.querySelector('[data-group-id="' + g.id + '"][data-size-id="' + next.id + '"]');
                        if (el) el.focus();
                    }
                    break;
                }
                case 'ArrowLeft':
                case 'ArrowUp': {
                    e.preventDefault();
                    const prev = g.sizes[idx - 1];
                    if (prev) {
                        const el = sizesRoot.querySelector('[data-group-id="' + g.id + '"][data-size-id="' + prev.id + '"]');
                        if (el) el.focus();
                    }
                    break;
                }
                case 'Enter':
                case ' ': {
                    e.preventDefault();
                    openSizeModal({
                        mode: 'edit',
                        groupId: g.id,
                        sizeId: chip.dataset.sizeId,
                    });
                    break;
                }
                case 'Delete': {
                    e.preventDefault();
                    break;
                }
                case 'Home': {
                    e.preventDefault();
                    const first = g.sizes[0];
                    if (first) {
                        const el = sizesRoot.querySelector('[data-group-id="' + g.id + '"][data-size-id="' + first.id + '"]');
                        if (el) el.focus();
                    }
                    break;
                }
                case 'End': {
                    e.preventDefault();
                    const last = g.sizes[g.sizes.length - 1];
                    if (last) {
                        const el = sizesRoot.querySelector('[data-group-id="' + g.id + '"][data-size-id="' + last.id + '"]');
                        if (el) el.focus();
                    }
                    break;
                }
            }
        });
    };

    /* ─── Size edit modal ──────────────────────────────────────── */

    const sizeModalEl    = $('[data-abo-admin-cs-size-modal]');
    const sizeModalTitle = $('[data-abo-admin-cs-size-modal-title-text]');
    const sizeModalBody  = $('[data-abo-admin-cs-size-modal-body]');
    const sizeLabelInput = $('[data-abo-admin-cs-size-label]');
    const sizeGroupInput = $('[data-abo-admin-cs-size-group]');
    const sizeVisInput   = $('[data-abo-admin-cs-size-visible]');
    const sizeDeleteBtn  = $('[data-abo-admin-cs-size-delete]');
    const sizeDeleteNote = $('[data-abo-admin-cs-size-delete-note]');
    const sizeSaveBtn    = $('[data-abo-admin-cs-size-save]');
    const sizeCloseEls   = () => $$('[data-abo-admin-cs-size-modal-close]');

    const openSizeModal = ({ mode, groupId, sizeId }) => {
        if (!sizeModalEl) return;
        const g = groupById(groupId);
        if (!g) return;

        if (mode === 'edit') {
            const s = g.sizes.find((x) => x.id === sizeId);
            if (!s) return;
            state.sizeModal = {
                mode: 'edit',
                groupId, sizeId,
                label: s.label,
                visible: s.visible,
                products: s.products,
            };
        } else {
            state.sizeModal = {
                mode: 'add',
                groupId,
                sizeId: 's-new-' + Date.now(),
                label: '',
                visible: true,
                products: 0,
            };
        }
        renderSizeModal();
        sizeModalEl.hidden = false;
        sizeModalEl.setAttribute('aria-modal', 'true');
        if (sizeLabelInput) sizeLabelInput.focus();
    };

    const renderSizeModal = () => {
        if (!state.sizeModal) return;
        const m = state.sizeModal;
        const g = groupById(m.groupId);
        if (!g) return;

        if (sizeModalTitle) {
            sizeModalTitle.textContent = m.mode === 'add' ? 'NEW SIZE' : 'EDIT SIZE';
        }
        if (sizeModalBody) {
            sizeModalBody.textContent = m.mode === 'add'
                ? 'Add a new size to ' + g.name + '.'
                : 'Editing “' + (g.sizes.find((s) => s.id === m.sizeId)?.label || m.label) + '”.';
        }

        if (sizeLabelInput) sizeLabelInput.value = m.label || '';

        // Group select: only visible in add mode. In edit mode the group is
        // read-only (we don't support cross-group moves in the modal).
        if (sizeGroupInput) {
            sizeGroupInput.innerHTML = '';
            for (const grp of SIZE_GROUPS) {
                const opt = ce('option', { value: grp.id }, grp.name);
                if (grp.id === m.groupId) opt.selected = true;
                sizeGroupInput.appendChild(opt);
            }
            sizeGroupInput.disabled = m.mode === 'edit';
        }

        if (sizeVisInput) {
            sizeVisInput.checked = !!m.visible;
            sizeVisInput.setAttribute('aria-checked', String(sizeVisInput.checked));
        }

        // Delete: disabled if the size is used by products.
        const hasProducts = m.mode === 'edit' && m.products > 0;
        if (sizeDeleteBtn) sizeDeleteBtn.disabled = hasProducts;
        if (sizeDeleteNote) {
            if (hasProducts) {
                sizeDeleteNote.textContent = 'Remove it from its products first.';
                sizeDeleteNote.hidden = false;
            } else {
                sizeDeleteNote.hidden = true;
            }
        }
    };

    const closeSizeModal = () => {
        if (!sizeModalEl) return;
        sizeModalEl.hidden = true;
        state.sizeModal = null;
    };

    const wireSizeModal = () => {
        for (const el of sizeCloseEls()) el.addEventListener('click', closeSizeModal);
        if (sizeLabelInput) {
            sizeLabelInput.addEventListener('input', () => {
                if (!state.sizeModal) return;
                state.sizeModal.label = sizeLabelInput.value;
            });
        }
        if (sizeGroupInput) {
            sizeGroupInput.addEventListener('change', () => {
                if (!state.sizeModal || state.sizeModal.mode !== 'add') return;
                state.sizeModal.groupId = sizeGroupInput.value;
            });
        }
        if (sizeVisInput) {
            sizeVisInput.addEventListener('change', () => {
                if (!state.sizeModal) return;
                state.sizeModal.visible = sizeVisInput.checked;
                sizeVisInput.setAttribute('aria-checked', String(sizeVisInput.checked));
            });
        }
        if (sizeSaveBtn) {
            sizeSaveBtn.addEventListener('click', async () => {
                if (!state.sizeModal) return;
                const m = state.sizeModal;
                const label = (m.label || '').trim();
                if (!label) {
                    if (sizeLabelInput) sizeLabelInput.focus();
                    return;
                }
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                const fd = new FormData();
                const isNew = m.mode === 'add';
                if (!isNew) {
                    fd.append('_method', 'PATCH');
                }
                fd.append('name', label);
                fd.append('group', m.groupId);
                fd.append('is_active', m.visible ? '1' : '0');

                sizeSaveBtn.disabled = true;
                const originalText = sizeSaveBtn.textContent;
                sizeSaveBtn.textContent = 'SAVING...';

                try {
                    const url = isNew ? '/admin/sizes' : `/admin/sizes/${m.sizeId}`;
                    const res = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'Accept': 'application/json',
                        },
                        body: fd,
                    });
                    const json = await res.json();
                    if (!res.ok) {
                        const errMsg = json.message || Object.values(json.errors || {})[0]?.[0] || 'Validation error';
                        showToast(errMsg, 'coral');
                        return;
                    }
                    const saved = json.size;
                    const nodeData = {
                        id: String(saved.id),
                        label: saved.name,
                        products: saved.products || 0,
                        visible: !!saved.active,
                    };
                    const grp = groupById(saved.group || m.groupId);
                    if (grp) {
                        if (isNew) {
                            grp.sizes.push(nodeData);
                        } else {
                            const idx = grp.sizes.findIndex((s) => String(s.id) === String(m.sizeId));
                            if (idx > -1) grp.sizes[idx] = nodeData;
                        }
                    }
                    closeSizeModal();
                    renderSizes();
                    showToast(json.message || 'Size saved.', 'lime');
                } catch (err) {
                    showToast('Failed to save size.', 'coral');
                } finally {
                    sizeSaveBtn.disabled = false;
                    sizeSaveBtn.textContent = originalText;
                }
            });
        }
        if (sizeDeleteBtn) {
            sizeDeleteBtn.addEventListener('click', () => {
                if (sizeDeleteBtn.disabled) return;
                if (!state.sizeModal || state.sizeModal.mode !== 'edit') return;
                state.deleteSizeTarget = {
                    groupId: state.sizeModal.groupId,
                    sizeId: state.sizeModal.sizeId,
                };
                openDeleteModal();
            });
        }

        // ESC closes the modal.
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && sizeModalEl && !sizeModalEl.hidden) closeSizeModal();
        });
    };

    /* ─── Sizes section: + ADD SIZE button ────────────────────── */

    const addSizeBtn = $('[data-abo-admin-cs-add-size]');
    if (addSizeBtn) {
        addSizeBtn.addEventListener('click', () => {
            const first = SIZE_GROUPS[0];
            if (!first) return;
            openSizeModal({ mode: 'add', groupId: first.id });
        });
    }

    /* ─── Shared delete-confirm modal ──────────────────────────── */

    const modalEl       = $('[data-abo-admin-cs-modal]');
    const modalBody     = $('[data-abo-admin-cs-modal-body]');
    const modalConfirm  = $('[data-abo-admin-cs-modal-confirm]');
    const modalCancel   = $('[data-abo-admin-cs-modal-cancel]');
    const modalCloseEls = () => $$('[data-abo-admin-cs-modal-close]');
    let modalContext    = 'color'; // 'color' | 'size'

    const openDeleteModal = () => {
        if (!modalEl) return;
        // Decide what we're deleting based on the active context.
        if (state.deleteSizeTarget) {
            modalContext = 'size';
            const g = groupById(state.deleteSizeTarget.groupId);
            const s = g && g.sizes.find((x) => x.id === state.deleteSizeTarget.sizeId);
            if (modalBody) {
                modalBody.textContent = 'Delete “' + (s ? s.label : 'this size') + '”? This action cannot be undone.';
            }
        } else if (state.editingColor && state.editingColor.id !== '__new') {
            modalContext = 'color';
            const c = colorById(state.editingColor.id);
            if (modalBody) {
                modalBody.textContent = 'Delete “' + (c ? c.name : 'this color') + '”? This action cannot be undone.';
            }
        } else {
            return;
        }
        modalEl.hidden = false;
        modalEl.setAttribute('aria-modal', 'true');
        if (modalConfirm) modalConfirm.focus();
    };

    const closeDeleteModal = () => {
        if (!modalEl) return;
        modalEl.hidden = true;
    };

    const wireDeleteModal = () => {
        for (const el of modalCloseEls()) el.addEventListener('click', closeDeleteModal);
        if (modalCancel) modalCancel.addEventListener('click', closeDeleteModal);
        if (modalConfirm) {
            modalConfirm.addEventListener('click', async () => {
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                modalConfirm.disabled = true;

                try {
                    if (modalContext === 'color') {
                        const e = state.editingColor;
                        if (e && e.id !== '__new') {
                            const res = await fetch(`/admin/colors/${e.id}`, {
                                method: 'DELETE',
                                headers: {
                                    'X-CSRF-TOKEN': csrfToken,
                                    'Accept': 'application/json',
                                },
                            });
                            const json = await res.json();
                            if (!res.ok) {
                                showToast(json.message || 'Failed to delete color', 'coral');
                                return;
                            }
                            const idx = COLORS.findIndex((c) => String(c.id) === String(e.id));
                            if (idx > -1) COLORS.splice(idx, 1);

                            const serverIdx = serverColors.findIndex((c) => String(c.id) === String(e.id));
                            if (serverIdx > -1) serverColors.splice(serverIdx, 1);

                            state.selectedColorId = null;
                            state.editingColor = null;
                            state.colorDirty = false;
                            renderColors();
                            renderColorPanel();
                            showToast(json.message || 'Color deleted successfully.', 'lime');
                        }
                    } else if (modalContext === 'size' && state.deleteSizeTarget) {
                        const target = state.deleteSizeTarget;
                        const res = await fetch(`/admin/sizes/${target.sizeId}`, {
                            method: 'DELETE',
                            headers: {
                                'X-CSRF-TOKEN': csrfToken,
                                'Accept': 'application/json',
                            },
                        });
                        const json = await res.json();
                        if (!res.ok) {
                            showToast(json.message || 'Failed to delete size', 'coral');
                            return;
                        }
                        const g = groupById(target.groupId);
                        if (g) {
                            const idx = g.sizes.findIndex((s) => String(s.id) === String(target.sizeId));
                            if (idx > -1) g.sizes.splice(idx, 1);
                        }
                        state.deleteSizeTarget = null;
                        closeSizeModal();
                        renderSizes();
                        showToast(json.message || 'Size deleted successfully.', 'lime');
                    }
                } catch (err) {
                    showToast('Network error.', 'coral');
                } finally {
                    state.deleteSizeTarget = null;
                    modalContext = 'color';
                    modalConfirm.disabled = false;
                    closeDeleteModal();
                }
            });
        }

        // ESC closes the modal.
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modalEl && !modalEl.hidden) closeDeleteModal();
        });
    };

    /* ─── Initial render ─────────────────────────────────────────
     *
     * Show the skeleton briefly so the loading state is visible even
     * on a static page; then reveal the lists. This is a demo
     * affordance — in the real backend version, the skeleton is
     * shown until the fetch resolves. */

    const skeletonDelayMs = 500;

    setTimeout(() => {
        renderColors();
        renderSizes();
        wireColorListGlobal();
        wireColorToolbar();
        wireColorPanel();
        wireSizeChipsGlobal();
        wireSizeModal();
        wireDeleteModal();
    }, skeletonDelayMs);

    // Render immediately when the static arrays are empty so the
    // empty state shows up at once.
    if (COLORS.length === 0) {
        if (colorSkeletonEl) colorSkeletonEl.hidden = true;
        renderColors();
        renderSizes();
        wireColorListGlobal();
        wireColorToolbar();
        wireColorPanel();
        wireSizeChipsGlobal();
        wireSizeModal();
        wireDeleteModal();
    }

    // Re-render the panel when the viewport crosses the mobile
    // breakpoint so the slide-over state stays in sync.
    window.addEventListener('resize', () => {
        if (!panelEl) return;
        if (!isMobile()) {
            panelEl.classList.remove('is-open');
            if (panelOverlay) panelOverlay.hidden = true;
        }
    });
})();
