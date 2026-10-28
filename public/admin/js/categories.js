/*
 * ABO Admin · Categories — page-local script (front-end only).
 *
 * DOM behaviour for the categories screen:
 *   - renders the tree from the categories the server sent
 *   - keeps the "selected" row in sync with the edit panel
 *   - search filter that keeps parents of matches visible
 *   - expand / collapse parent nodes (or all at once)
 *   - HTML5 drag-to-reorder (with a lime drop indicator)
 *   - Arabic / English name switch, slug auto-generation
 *   - 28x14 active toggles per row (saved to local state)
 *   - cover-image local preview (URL.createObjectURL, no upload)
 *   - one shared delete-confirm modal + toast
 *
 * The tree comes from Admin\CategoryController (the `categories`
 * table) and every change — reorder, rename, toggle, delete — goes
 * back to the server. A refused change puts the tree back the way
 * the database still sees it.
 *
 * The only local state is the `state` object that holds transient UI
 * (selected ID, expanded set, dirty flag) — it is reset on every
 * page load.
 *
 * Markup anchor points (every selector uses data-* attributes):
 *   [data-abo-admin-categories]                — page root
 *   [data-abo-admin-categories-tree]           — <ul role="tree">
 *   [data-abo-admin-categories-search]         — toolbar search input
 *   [data-abo-admin-categories-expand-all]     — expand/collapse button
 *   [data-abo-admin-categories-skeleton]       — loading skeleton block
 *   [data-abo-admin-categories-state="empty|noresults"]
 *                                              — mutually exclusive states
 *   [data-abo-admin-categories-panel]          — right column edit panel
 *   [data-abo-admin-categories-panel-close]    — slide-over close button
 *   [data-abo-admin-categories-modal]          — delete modal root
 *   [data-abo-admin-categories-toast]          — single shared toast
 *
 * No external dependencies. Loaded only on /admin/categories.
 */

(function () {
    'use strict';

    /* ─── The categories the server sent ────────────────────────
     *
     * Admin\CategoryController reads the tree out of the `categories`
     * table and publishes it as `window.__CATEGORIES__` in the page.
     * There is no fallback array: when the payload is missing the
     * screen shows its empty state, because a tree of invented
     * categories is worse than no tree at all — the operator would
     * be editing rows the database does not hold.
     *
     * Shape per node:
     *   { id, parentId | null, name, nameAr, slug, products, active,
     *     cover | null }
     */
    const CATEGORIES = (typeof window !== 'undefined' && Array.isArray(window.__CATEGORIES__))
        ? window.__CATEGORIES__
        : [];

    /* ─── Local UI state (never persisted) ────────────────────── */

    const state = {
        selectedId: null,
        expanded: new Set(),
        filter: '',
        editing: null,        // { id, parentId, name, nameAr, slug, description, active, visibleInStore, showInHomepage, cover (object URL or null) }
        langTab: 'en',
        coverObjectUrl: null, // URL.createObjectURL handle, released on remove / unmount
        dirty: false,
        allExpanded: false,
        deleteTarget: null,
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

    const slugify = (s = '') => s
        .toString()
        .trim()
        .toLowerCase()
        .replace(/[^\w\s-]/g, '')
        .replace(/\s+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-+|-+$/g, '');

    const isMobile = () => window.matchMedia('(max-width: 1100px)').matches;

    /* ─── Toast (single shared instance) ───────────────────────── */

    let toastTimer = null;
    const toastEls = () => ({
        wrap:  $('[data-abo-admin-categories-toast]'),
        text:  $('[data-abo-admin-categories-toast-text]'),
        dot:   $('[data-abo-admin-categories-toast-dot]'),
    });
    const showToast = (message, tone = 'lime') => {
        const t = toastEls();
        if (!t.wrap) return;
        if (toastTimer) clearTimeout(toastTimer);
        t.text.textContent = message;
        if (t.dot) t.dot.className = 'abo-admin-categories__toast-dot abo-admin-categories__toast-dot--' + tone;
        t.wrap.hidden = false;
        toastTimer = setTimeout(() => { t.wrap.hidden = true; }, 2400);
    };

    /* ─── Tree rendering ───────────────────────────────────────── */

    const root = $('[data-abo-admin-categories]');
    if (!root) return;

    const treeEl     = $('[data-abo-admin-categories-tree]');
    const skeletonEl = $('[data-abo-admin-categories-skeleton]');
    const stateEmpty = $('[data-abo-admin-categories-state="empty"]');
    const stateNoRes = $('[data-abo-admin-categories-state="noresults"]');
    const expandBtn  = $('[data-abo-admin-categories-expand-all]');

    const node = (id) => CATEGORIES.find((c) => c.id === id);
    const childrenOf = (parentId) => CATEGORIES
        .filter((c) => c.parentId === parentId)
        .sort((a, b) => orderIndex(a.id) - orderIndex(b.id));
    // Stable manual order: respect the original sequence in CATEGORIES.
    const orderIndex = (id) => CATEGORIES.findIndex((c) => c.id === id);

    /* ─── Talking to the server ────────────────────────────────── */

    const csrfToken = () =>
        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    /** The tree as the server last confirmed it. A refused change puts
     *  it back, so the screen never shows an order the database does
     *  not hold. */
    let serverCategories = CATEGORIES.map((cat) => ({ ...cat }));

    const restoreServerTree = () => {
        CATEGORIES.length = 0;
        serverCategories.forEach((cat) => CATEGORIES.push({ ...cat }));
    };

    /** Put a category the server answered with back into the tree. */
    const replaceCategory = (saved) => {
        for (const list of [CATEGORIES, serverCategories]) {
            const idx = list.findIndex((c) => String(c.id) === String(saved.id));
            if (idx > -1) {
                list[idx] = saved;
            } else {
                list.push(saved);
            }
        }
    };

    /** The tree the way the server reads it: every category with the
     *  parent it sits under and its place among its siblings. */
    const orderPayload = () => {
        const counters = new Map();
        const payload = [];

        for (const cat of CATEGORIES) {
            const parent = cat.parentId === null || cat.parentId === undefined
                ? ''
                : String(cat.parentId);
            const sortOrder = counters.get(parent) || 0;
            counters.set(parent, sortOrder + 1);

            payload.push({
                id: String(cat.id),
                parent_id: parent === '' ? null : parent,
                sort_order: sortOrder,
            });
        }

        return payload;
    };

    /** Whether a category sits under another one. */
    const isUnder = (parentId, categoryId) => {
        let current = parentId === null || parentId === undefined ? null : node(parentId);

        while (current) {
            if (String(current.id) === String(categoryId)) return true;

            current = current.parentId === null || current.parentId === undefined
                ? null
                : node(current.parentId);
        }

        return false;
    };

    /** Write the new order to the server and draw what it answers. */
    const persistOrder = async () => {
        try {
            const res = await fetch('/admin/categories/reorder', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ categories: orderPayload() }),
            });

            const json = await res.json();

            if (!res.ok) {
                restoreServerTree();
                renderTree();
                showToast(json.message || 'The order could not be saved.', 'coral');

                return;
            }

            // The server owns the tree — draw what it answered.
            if (Array.isArray(json.categories)) {
                serverCategories = json.categories.map((cat) => ({ ...cat }));
                CATEGORIES.length = 0;
                serverCategories.forEach((cat) => CATEGORIES.push({ ...cat }));
                renderTree();
            }

            showToast(json.message || 'Order updated.', 'lime');
        } catch (err) {
            restoreServerTree();
            renderTree();
            showToast('The order could not be saved.', 'coral');
        }
    };

    /** Switch a category on or off. The server decides. */
    const toggleCategory = async (cat, next) => {
        try {
            const res = await fetch(`/admin/categories/${cat.id}/toggle`, {
                method: 'PATCH',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
            });

            const json = await res.json();

            if (!res.ok) {
                restoreServerTree();
                renderTree();
                showToast(json.message || 'The category could not be switched.', 'coral');

                return;
            }

            const saved = json.category;
            if (saved) {
                replaceCategory(saved);
            }

            renderTree();

            // If the toggled row is also the selected one, sync the
            // panel switch.
            if (saved && state.selectedId === String(cat.id)) {
                state.editing = { ...(state.editing || {}), active: saved.active };
                renderPanel();
            }

            showToast(json.message || (saved && saved.active ? 'Activated' : 'Deactivated'), 'lime');
        } catch (err) {
            restoreServerTree();
            renderTree();
            showToast('The category could not be switched.', 'coral');
        }
    };

    /** Names + slug rendered as plain text by the panel — no HTML injection. */
    const productCountText = (n) => n === 0 ? '0 products' : (n === 1 ? '1 product' : `${n} products`);

    /** The HTML for the thumbnail / initial-letter cell. Cover URLs are
     *  escaped; we never trust string interpolation here. */
    const thumbHtml = (cat) => {
        if (cat.cover) {
            return `<img src="${escapeHtml(cat.cover)}" alt="">`;
        }
        const letter = (cat.name || '?').trim().charAt(0).toUpperCase();
        return `<span class="abo-admin-categories__thumb-letter">${escapeHtml(letter)}</span>`;
    };

    /** Build a single <li role="treeitem">. The data-id lets us bind
     *  events later. Children are appended into a nested <ul> so they
     *  inherit the aria-expanded state of their parent. */
    const buildRow = (cat, depth) => {
        const kids = childrenOf(cat.id);
        const hasKids = kids.length > 0;
        const isExpanded = state.expanded.has(cat.id);
        const isSelected = state.selectedId === cat.id;
        const isChild = depth > 0;

        const li = ce('li', {
            role: 'treeitem',
            'aria-level': depth + 1,
            'aria-expanded': hasKids ? String(isExpanded) : null,
            'aria-selected': String(isSelected),
            tabindex: '-1',
            dataset: { id: cat.id, depth: String(depth), aboAdminCategoriesItem: '' },
            class: [
                'abo-admin-categories__row',
                isChild ? 'abo-admin-categories__row--child' : '',
            ].filter(Boolean).join(' '),
        });

        // drag handle — every row can be picked up, so a sub-category
        // can be moved as well as a top-level one.
        const handle = ce('span', {
            class: 'abo-admin-categories__handle',
            'aria-label': 'Drag to reorder',
            role: 'button',
            tabindex: '0',
            dataset: { aboAdminCategoriesHandle: cat.id },
            html: '<svg viewBox="0 0 6 10" aria-hidden="true"><circle cx="1.5" cy="1.5" r="1"/><circle cx="4.5" cy="1.5" r="1"/><circle cx="1.5" cy="5" r="1"/><circle cx="4.5" cy="5" r="1"/><circle cx="1.5" cy="8.5" r="1"/><circle cx="4.5" cy="8.5" r="1"/></svg>',
        });
        handle.draggable = true;
        li.appendChild(handle);

        // caret (only for parents with children)
        if (hasKids) {
            const caret = ce('button', {
                type: 'button',
                class: 'abo-admin-categories__caret',
                'aria-label': isExpanded ? 'Collapse' : 'Expand',
                'aria-expanded': String(isExpanded),
                dataset: { aboAdminCategoriesCaret: cat.id },
                html: '<svg viewBox="0 0 12 12" aria-hidden="true"><path d="M4 2 8 6 4 10" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>',
            });
            li.appendChild(caret);
        } else {
            li.appendChild(ce('span', {
                class: 'abo-admin-categories__caret abo-admin-categories__caret--placeholder',
                'aria-hidden': 'true',
            }));
        }

        // thumb
        const thumb = ce('div', {
            class: 'abo-admin-categories__thumb',
            'aria-hidden': 'true',
            html: thumbHtml(cat),
        });
        li.appendChild(thumb);

        // meta (name + slug)
        li.appendChild(ce('div', { class: 'abo-admin-categories__meta' },
            ce('p', { class: 'abo-admin-categories__name' }, cat.name),
            ce('p', { class: 'abo-admin-categories__slug' }, cat.slug),
        ));

        // count
        const countClass = [
            'abo-admin-categories__count',
            cat.products === 0 ? 'abo-admin-categories__count--empty' : '',
            cat.active === false ? 'abo-admin-categories__count--inactive' : '',
        ].filter(Boolean).join(' ');
        li.appendChild(ce('p', { class: countClass }, productCountText(cat.products)));

        // toggle (28x14 lime-on when active). aria-checked so AT users hear it.
        const toggle = ce('label', {
            class: 'abo-admin-categories__toggle',
            'aria-label': (cat.active ? 'Deactivate' : 'Activate') + ' ' + cat.name,
        });
        const input = ce('input', {
            type: 'checkbox',
            'aria-checked': String(cat.active),
            dataset: { aboAdminCategoriesToggle: cat.id },
        });
        input.checked = cat.active;
        toggle.appendChild(input);
        toggle.appendChild(ce('span', { class: 'abo-admin-categories__toggle-track' }));
        toggle.appendChild(ce('span', { class: 'abo-admin-categories__toggle-thumb' }));
        li.appendChild(toggle);

        // Children list
        const childrenList = hasKids ? ce('ul', {
            role: 'group',
            class: 'abo-admin-categories__group abo-admin-categories__group--children',
            hidden: !isExpanded,
        }) : null;
        if (hasKids) {
            for (const child of kids) childrenList.appendChild(buildRow(child, depth + 1));
            li.appendChild(childrenList);
        }

        // Every row takes part in the drag: a row can be dropped on, and
        // a row can be picked up.
        bindRowDragHandlers(li, /* isParent */ hasKids);

        return li;
    };

    /** A group of top-level parents (one UL). Drop zones are wired here
     *  so we can reorder top-level categories among themselves. */
    const buildParentGroup = (cat) => {
        const li = buildRow(cat, 0);
        return li;
    };

    /* The flat list of <li> that wrap each top-level category. We
     * expose drag/drop events against this list (and against each
     * nested children group). */

    /** Walk CATEGORIES preserving the order set by drag operations. */
    const topLevelOrdered = () => {
        // First, identify parent groups (parentId == null) in the live
        // DOM order. We re-read this from the tree every render so
        // drag/drop changes show up immediately.
        const ids = $$('[data-abo-admin-categories-tree] > li[data-id]').map((li) => li.dataset.id);
        const parents = ids.map(node).filter(Boolean);
        for (const p of parents) {
            const childIds = $$('[data-id="' + p.id + '"] > .abo-admin-categories__group--children > li[data-id]')
                .map((li) => li.dataset.id);
            // Append the children to the live order so subsequent reads
            // also get them in the dragged order.
            for (const cid of childIds) parents.push(node(cid));
        }
        return parents;
    };

    /* ─── Render the tree from CATEGORIES + state ─────────────── */

    const filterMatches = (cat) => {
        if (!state.filter) return true;
        const q = state.filter.toLowerCase();
        if (cat.name.toLowerCase().includes(q)) return true;
        if (cat.slug.toLowerCase().includes(q)) return true;
        return childrenOf(cat.id).some(filterMatches);
    };

    const renderTree = () => {
        if (!treeEl) return;

        // Empty state (no categories at all).
        if (CATEGORIES.length === 0) {
            treeEl.hidden = true;
            if (stateEmpty) stateEmpty.hidden = false;
            if (stateNoRes) stateNoRes.hidden = true;
            if (skeletonEl) skeletonEl.hidden = true;
            return;
        }

        const matchedParents = CATEGORIES
            .filter((c) => c.parentId === null)
            .filter(filterMatches);

        // No-results state (search yielded nothing).
        const hasAnyMatch = matchedParents.length > 0;
        if (state.filter && !hasAnyMatch) {
            treeEl.hidden = true;
            if (stateEmpty) stateEmpty.hidden = true;
            if (stateNoRes) stateNoRes.hidden = false;
        } else {
            treeEl.hidden = false;
            if (stateEmpty) stateEmpty.hidden = true;
            if (stateNoRes) stateNoRes.hidden = true;
        }
        if (skeletonEl) skeletonEl.hidden = true;

        treeEl.innerHTML = '';
        for (const parent of matchedParents) {
            // Apply the "expand all" override when the toolbar button is on.
            if (state.allExpanded) state.expanded.add(parent.id);

            const li = buildParentGroup(parent);
            treeEl.appendChild(li);
        }
    };

    /* ─── Row click / caret click / toggle click ──────────────── */

    const onRowActivate = (li) => {
        const id = li.dataset.id;
        if (!id) return;

        // Clicking a row selects it (highlight + loads it in the panel).
        state.selectedId = id;
        // Initialise the editing snapshot from the static array.
        const cat = node(id);
        if (!cat) return;

        state.editing = {
            id: cat.id,
            parentId: cat.parentId,
            name: cat.name,
            nameAr: cat.nameAr || '',
            slug: cat.slug,
            description: cat.description || '',
            active: cat.active,
            visibleInStore: cat.visibleInStore !== false,
            showInHomepage: !!cat.showInHomepage,
            cover: cat.cover || null,
        };
        state.dirty = false;

        renderTree();         // re-render the tree so the lime border appears
        renderPanel();

        if (isMobile()) openPanel();
    };

    const bindRowDragHandlers = (rowLi, isParent) => {
        // The drag handle is the only "draggable" element. The whole row
        // listens for `dragover` / `drop` to show the indicator line +
        // accept the move.
        const handle = rowLi.querySelector('[data-abo-admin-categories-handle]');
        if (handle) {
            handle.addEventListener('dragstart', (e) => {
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', handle.dataset.aboAdminCategoriesHandle);
                rowLi.classList.add('is-dragging');
            });
            handle.addEventListener('dragend', () => {
                rowLi.classList.remove('is-dragging');
                $$('.abo-admin-categories__row').forEach((el) => {
                    el.classList.remove('is-drop-before', 'is-drop-after', 'is-drop-target');
                });
            });
        }

        rowLi.addEventListener('click', (e) => {
            // Don't trigger activation when clicking an interactive
            // element (caret, toggle, handle).
            if (e.target.closest('[data-abo-admin-categories-caret],' +
                '[data-abo-admin-categories-toggle],' +
                '[data-abo-admin-categories-handle],' +
                'label, input, button, .abo-admin-categories__handle')) return;
            onRowActivate(rowLi);
        });

        rowLi.addEventListener('dragover', (e) => {
            if (!rowLi.classList.contains('is-dragging')) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                const rect = rowLi.getBoundingClientRect();
                const before = (e.clientY - rect.top) < rect.height / 2;
                $$('.abo-admin-categories__row').forEach((el) => {
                    el.classList.remove('is-drop-before', 'is-drop-after', 'is-drop-target');
                });
                rowLi.classList.add(before ? 'is-drop-before' : 'is-drop-after');
            }
        });

        rowLi.addEventListener('drop', (e) => {
            e.preventDefault();

            // The row the pointer was over is the one that handles the
            // drop — a child row must not be handled by its parent.
            const hitRow = e.target && e.target.closest
                ? e.target.closest('li[data-id]')
                : null;
            if (hitRow && hitRow !== rowLi) return;

            const draggedId = e.dataTransfer.getData('text/plain');
            if (!draggedId || draggedId === rowLi.dataset.id) return;

            const dragged = node(draggedId);
            const target  = node(rowLi.dataset.id);
            if (!dragged || !target) return;

            // Decide the target depth — depth 0 = top level. A drop on
            // a child row joins that row's group; a drop on a top-level
            // row moves the dragged row to the top level.
            const targetDepth = parseInt(rowLi.dataset.depth, 10) || 0;
            const before = rowLi.classList.contains('is-drop-before');
            const newParentId = targetDepth > 0 ? target.parentId : null;

            // A category cannot sit under one of its own children.
            if (newParentId !== null && isUnder(newParentId, dragged.id)) {
                showToast('A category cannot be moved under itself.', 'coral');
                return;
            }

            // Reorder in CATEGORIES: remove dragged from its slot, insert
            // before/after target. The tree is drawn from the order of
            // the rows that share a parent, so the row lands next to
            // the one it was dropped on.
            const draggedIdx = orderIndex(draggedId);
            if (draggedIdx > -1) CATEGORIES.splice(draggedIdx, 1);
            dragged.parentId = newParentId;
            const targetIdx = orderIndex(target.id);
            CATEGORIES.splice(before ? targetIdx : targetIdx + 1, 0, dragged);

            if (newParentId !== null && state.expanded.has(newParentId)) {
                state.expanded.add(newParentId);
            }

            // Clear any lingering drop indicators.
            $$('.abo-admin-categories__row').forEach((el) => {
                el.classList.remove('is-drop-before', 'is-drop-after', 'is-drop-target');
            });
            renderTree();
            persistOrder();
        });

        rowLi.addEventListener('keydown', (e) => onTreeKeydown(e, rowLi, isParent));
    };

    /* ─── Tree keyboard navigation ────────────────────────────── */

    const focusableTreeRows = () => $$('[data-abo-admin-categories-tree] li[data-id]');

    /** Find the next/previous visible row in the linearised order. */
    const flattenRows = () => {
        const out = [];
        const root = $('[data-abo-admin-categories-tree]');
        if (!root) return out;
        const walk = (ul, parents) => {
            for (const li of ul.children) {
                if (li.tagName !== 'LI' || !li.dataset.id) continue;
                out.push(li);
                const kids = li.querySelector(':scope > .abo-admin-categories__group--children');
                if (kids && !kids.hidden) walk(kids, parents);
            }
        };
        walk(root);
        return out;
    };

    const onTreeKeydown = (e, row, isParent) => {
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
                if (isParent && row.getAttribute('aria-expanded') !== null) {
                    e.preventDefault();
                    if (row.getAttribute('aria-expanded') !== 'true') {
                        state.expanded.add(row.dataset.id);
                        renderTree();
                        // Refocus the same row after re-render.
                        const target = $('[data-abo-admin-categories-tree] li[data-id="' + row.dataset.id + '"]');
                        if (target) target.focus();
                    }
                }
                break;
            }
            case 'ArrowLeft': {
                if (isParent && row.getAttribute('aria-expanded') === 'true') {
                    e.preventDefault();
                    state.expanded.delete(row.dataset.id);
                    renderTree();
                    const target = $('[data-abo-admin-categories-tree] li[data-id="' + row.dataset.id + '"]');
                    if (target) target.focus();
                } else {
                    // Move focus to the parent if this is a child.
                    const depth = parseInt(row.dataset.depth, 10) || 0;
                    if (depth > 0) {
                        e.preventDefault();
                        const flat = flattenRows();
                        const prev = flat[idx - 1];
                        if (prev && parseInt(prev.dataset.depth, 10) === depth - 1) prev.focus();
                    }
                }
                break;
            }
            case 'Enter':
            case ' ': {
                // Toggle the parent expansion OR activate the row.
                e.preventDefault();
                if (isParent && row.getAttribute('aria-expanded') !== null) {
                    const open = row.getAttribute('aria-expanded') === 'true';
                    if (open) state.expanded.delete(row.dataset.id);
                    else     state.expanded.add(row.dataset.id);
                    renderTree();
                    const target = $('[data-abo-admin-categories-tree] li[data-id="' + row.dataset.id + '"]');
                    if (target) target.focus();
                } else {
                    // Active toggle.
                    const toggleInput = row.querySelector('[data-abo-admin-categories-toggle]');
                    if (toggleInput) {
                        toggleInput.checked = !toggleInput.checked;
                        toggleInput.setAttribute('aria-checked', String(toggleInput.checked));
                        toggleInput.dispatchEvent(new Event('change', { bubbles: true }));
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

    /** Focus a row (when called from a tree click / keyboard event). */
    const focusRow = (li) => {
        if (!li) return;
        $$('[data-abo-admin-categories-tree] li[data-id]').forEach((r) => r.tabIndex = -1);
        li.tabIndex = 0;
        li.focus();
    };

    /* ─── Wire the tree after first render ────────────────────── */

    const wireTreeGlobal = () => {
        // Use a delegated listener so re-renders don't lose handlers.
        treeEl.addEventListener('click', (e) => {
            const caret = e.target.closest('[data-abo-admin-categories-caret]');
            if (caret) {
                e.stopPropagation();
                const id = caret.dataset.aboAdminCategoriesCaret;
                const open = state.expanded.has(id);
                if (open) state.expanded.delete(id); else state.expanded.add(id);
                renderTree();
                // Restore focus on the (newly rendered) caret button.
                const newCaret = $('[data-abo-admin-categories-caret="' + id + '"]');
                if (newCaret) newCaret.focus();
                return;
            }

            const toggleInput = e.target.closest('[data-abo-admin-categories-toggle]');
            if (toggleInput) {
                e.stopPropagation();
                // Stop the row from also being selected on the same click.
                const id = toggleInput.dataset.aboAdminCategoriesToggle;
                const cat = node(id);
                if (!cat) return;

                // The switch moves right away; the server has the last
                // word and the tree is redrawn from what it answers.
                cat.active = toggleInput.checked;
                toggleInput.setAttribute('aria-checked', String(toggleInput.checked));

                // Update count style without a full re-render.
                const row = toggleInput.closest('li[data-id]');
                const countEl = row && row.querySelector('.abo-admin-categories__count');
                if (countEl) {
                    countEl.classList.toggle('abo-admin-categories__count--inactive', cat.active === false);
                    countEl.textContent = productCountText(cat.products);
                }
                // If the toggled row is also the selected one, sync the
                // panel switch.
                if (state.selectedId === id) {
                    state.editing = state.editing || {};
                    state.editing.active = toggleInput.checked;
                    renderPanel();
                }

                toggleCategory(cat, toggleInput.checked);
                return;
            }
        });

        treeEl.addEventListener('focusin', (e) => {
            const li = e.target.closest('li[data-id]');
            if (!li) return;
            $$('[data-abo-admin-categories-tree] li[data-id]').forEach((r) => r.tabIndex = -1);
            li.tabIndex = 0;
        });
    };

    /* ─── Toolbar: search + expand/collapse ───────────────────── */

    const wireToolbar = () => {
        const search = $('[data-abo-admin-categories-search]');
        if (search) {
            search.addEventListener('input', () => {
                state.filter = search.value.trim();
                renderTree();
            });
        }
        if (expandBtn) {
            expandBtn.addEventListener('click', () => {
                state.allExpanded = !state.allExpanded;
                if (state.allExpanded) {
                    for (const c of CATEGORIES) {
                        if (c.parentId !== null) state.expanded.add(c.parentId);
                    }
                }
                expandBtn.textContent = state.allExpanded ? 'COLLAPSE ALL' : 'EXPAND ALL';
                expandBtn.setAttribute('aria-pressed', String(state.allExpanded));
                renderTree();
            });
        }

        // Clear-search button (×) inside the search pill.
        const clearBtn = root.querySelector('.abo-admin-categories__search-clear');
        const searchInput = root.querySelector('[data-abo-admin-categories-search]');
        if (clearBtn && searchInput) {
            clearBtn.addEventListener('click', () => {
                searchInput.value = '';
                state.filter = '';
                renderTree();
                searchInput.focus();
            });
        }
    };

    /* ─── Right panel (edit form) ──────────────────────────────── */

    const panelEl = $('[data-abo-admin-categories-panel]');
    const panelCloseBtn = $('[data-abo-admin-categories-panel-close]');
    const panelModalOverlay = $('[data-abo-admin-categories-panel-overlay]');
    const panelTitleEl = $('[data-abo-admin-categories-panel-title-text]');
    const dirtyDotEl = $('[data-abo-admin-categories-dirty-dot]');
    const coverPreviewEl = $('[data-abo-admin-categories-cover-preview]');
    const coverFileInput = $('[data-abo-admin-categories-cover-file]');
    const coverChangeBtn = $('[data-abo-admin-categories-cover-change]');
    const coverRemoveBtn = $('[data-abo-admin-categories-cover-remove]');
    const nameInput = $('[data-abo-admin-categories-name]');
    const slugInput = $('[data-abo-admin-categories-slug]');
    const parentSelect = $('[data-abo-admin-categories-parent]');
    const descTextarea = $('[data-abo-admin-categories-description]');
    const visibleSwitch = $('[data-abo-admin-categories-visible]');
    const homepageSwitch = $('[data-abo-admin-categories-homepage]');
    const saveBtn = $('[data-abo-admin-categories-save]');
    const deleteBtn = $('[data-abo-admin-categories-delete]');
    const deleteNoteEl = $('[data-abo-admin-categories-delete-note]');
    const langBtns = $$('[data-abo-admin-categories-lang] button');

    const renderPanel = () => {
        if (!panelEl) return;
        if (!state.editing) {
            // No selection state — only on initial load. Hide the form.
            panelEl.querySelector('[data-abo-admin-categories-panel-form]').hidden = true;
            const empty = panelEl.querySelector('[data-abo-admin-categories-panel-no-selection]');
            if (empty) empty.hidden = false;
            return;
        }
        const f = panelEl.querySelector('[data-abo-admin-categories-panel-form]');
        const empty = panelEl.querySelector('[data-abo-admin-categories-panel-no-selection]');
        if (empty) empty.hidden = true;
        if (f) f.hidden = false;

        const e = state.editing;
        const existing = node(e.id);

        // Title: edit vs add
        if (panelTitleEl) {
            panelTitleEl.textContent = existing
                ? 'EDIT CATEGORY'
                : 'NEW CATEGORY';
        }

        // Language tab default — EN is open when adding a new category.
        applyLangTab();

        // Populate parent select.
        if (parentSelect) {
            parentSelect.innerHTML = '';
            parentSelect.appendChild(ce('option', { value: '' }, 'None (top level)'));
            for (const c of CATEGORIES) {
                if (c.parentId !== null) continue;
                if (existing && c.id === existing.id) continue; // can't be own parent
                const opt = ce('option', { value: c.id }, c.name);
                if (existing && existing.parentId === c.id) opt.selected = true;
                if (!existing && e.parentId === c.id) opt.selected = true;
                parentSelect.appendChild(opt);
            }
            parentSelect.value = existing ? (existing.parentId || '') : (e.parentId || '');
        }

        // Fields.
        if (nameInput) {
            nameInput.value = state.langTab === 'ar' ? (e.nameAr || '') : (e.name || '');
        }
        if (slugInput) {
            slugInput.value = e.slug || '';
            slugInput.readOnly = false;
        }
        if (descTextarea) descTextarea.value = e.description || '';
        if (visibleSwitch) visibleSwitch.checked = !!e.visibleInStore;
        if (homepageSwitch) homepageSwitch.checked = !!e.showInHomepage;

        // Cover preview.
        renderCover(e.cover);

        // Delete rules: disabled if it has products or sub-categories.
        const hasProducts = e.id !== '__new' && existing && existing.products > 0;
        const hasChildren = e.id !== '__new' && existing && childrenOf(existing.id).length > 0;
        if (deleteBtn) {
            deleteBtn.disabled = hasProducts || hasChildren;
        }
        if (deleteNoteEl) {
            if (hasProducts) {
                deleteNoteEl.textContent = 'Move or remove its products first.';
                deleteNoteEl.hidden = false;
            } else if (hasChildren) {
                deleteNoteEl.textContent = 'Delete or move its sub-categories first.';
                deleteNoteEl.hidden = false;
            } else {
                deleteNoteEl.hidden = true;
            }
        }

        // Dirty dot.
        if (dirtyDotEl) dirtyDotEl.classList.toggle('is-visible', state.dirty);

        // Toggle the bottom switch visual to reflect `active`.
        const activeInput = $('[data-abo-admin-categories-switch-active] input');
        if (activeInput) {
            activeInput.checked = !!e.active;
            activeInput.setAttribute('aria-checked', String(activeInput.checked));
        }
    };

    const renderCover = (url) => {
        if (!coverPreviewEl) return;
        if (url) {
            coverPreviewEl.innerHTML = `<img src="${escapeHtml(url)}" alt="">`;
        } else {
            // Use a placeholder letter from the current name.
            const letter = (state.editing && state.editing.name
                ? state.editing.name.trim().charAt(0)
                : '?').toUpperCase();
            coverPreviewEl.innerHTML = `<span class="abo-admin-categories__cover-letter">${escapeHtml(letter)}</span>`;
        }
        if (coverRemoveBtn) coverRemoveBtn.disabled = !url;
    };

    const applyLangTab = () => {
        for (const btn of langBtns) {
            const active = btn.dataset.lang === state.langTab;
            btn.classList.toggle('is-active', active);
            btn.setAttribute('aria-pressed', String(active));
        }
        if (nameInput) {
            nameInput.value = state.langTab === 'ar'
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
            if (panelModalOverlay) panelModalOverlay.hidden = false;
        }
    };

    const closePanel = () => {
        if (!panelEl) return;
        if (isMobile()) {
            panelEl.classList.remove('is-open');
            if (panelModalOverlay) panelModalOverlay.hidden = true;
        }
    };

    const markDirty = () => {
        if (!state.dirty) {
            state.dirty = true;
            if (dirtyDotEl) dirtyDotEl.classList.add('is-visible');
        }
    };

    const wirePanel = () => {
        // Language tabs.
        for (const btn of langBtns) {
            btn.addEventListener('click', () => {
                state.langTab = btn.dataset.lang;
                applyLangTab();
            });
        }

        // Close button.
        if (panelCloseBtn) {
            panelCloseBtn.addEventListener('click', () => closePanel());
        }
        if (panelModalOverlay) {
            panelModalOverlay.addEventListener('click', () => closePanel());
        }

        // Cover change / remove.
        if (coverChangeBtn && coverFileInput) {
            coverChangeBtn.addEventListener('click', () => coverFileInput.click());
        }
        if (coverFileInput) {
            coverFileInput.addEventListener('change', () => {
                const f = coverFileInput.files && coverFileInput.files[0];
                if (!f) return;
                if (state.coverObjectUrl) URL.revokeObjectURL(state.coverObjectUrl);
                state.coverObjectUrl = URL.createObjectURL(f);
                if (state.editing) state.editing.cover = state.coverObjectUrl;
                renderCover(state.coverObjectUrl);
                markDirty();
            });
        }
        if (coverRemoveBtn) {
            coverRemoveBtn.addEventListener('click', () => {
                if (state.coverObjectUrl) {
                    URL.revokeObjectURL(state.coverObjectUrl);
                    state.coverObjectUrl = null;
                }
                if (state.editing) state.editing.cover = null;
                renderCover(null);
                markDirty();
            });
        }

        // Name input → keep parent slug in sync when adding a new row,
        // or when the user hasn't manually edited the slug yet.
        if (nameInput) {
            nameInput.addEventListener('input', () => {
                if (!state.editing) return;
                if (state.langTab === 'ar') {
                    state.editing.nameAr = nameInput.value;
                } else {
                    state.editing.name = nameInput.value;
                    // Auto-generate slug only if the user hasn't typed one
                    // by hand yet (slug === slugify(name) at selection time).
                    if (!state.editing.slug || state.editing.slug === slugify(state.editing._previousName || '')) {
                        state.editing.slug = slugify(nameInput.value);
                        if (slugInput) slugInput.value = state.editing.slug;
                    }
                    state.editing._previousName = nameInput.value;
                }
                markDirty();
            });
        }
        if (slugInput) {
            slugInput.addEventListener('input', () => {
                if (!state.editing) return;
                state.editing.slug = slugInput.value;
                markDirty();
            });
        }
        if (parentSelect) {
            parentSelect.addEventListener('change', () => {
                if (!state.editing) return;
                state.editing.parentId = parentSelect.value || null;
                markDirty();
            });
        }
        if (descTextarea) {
            descTextarea.addEventListener('input', () => {
                if (!state.editing) return;
                state.editing.description = descTextarea.value;
                markDirty();
            });
        }
        if (visibleSwitch) {
            visibleSwitch.addEventListener('change', () => {
                if (!state.editing) return;
                state.editing.visibleInStore = visibleSwitch.checked;
                markDirty();
            });
        }
        if (homepageSwitch) {
            homepageSwitch.addEventListener('change', () => {
                if (!state.editing) return;
                state.editing.showInHomepage = homepageSwitch.checked;
                markDirty();
            });
        }
        const activeSwitch = $('[data-abo-admin-categories-switch-active] input');
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
            saveBtn.addEventListener('click', async () => {
                if (!state.editing) return;
                const e = state.editing;
                const existing = node(e.id);
                const isNew = !existing || String(e.id).startsWith('c-new-') || e.id === '__new';

                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
                const fd = new FormData();

                if (!isNew) {
                    fd.append('_method', 'PUT');
                }

                fd.append('name', e.name || '');
                fd.append('name_ar', e.nameAr || '');
                fd.append('slug', e.slug || '');
                if (e.parentId) {
                    fd.append('parent_id', e.parentId);
                }
                if (e.description) {
                    fd.append('description', e.description);
                }
                fd.append('is_active', e.active ? '1' : '0');
                fd.append('is_visible', e.visibleInStore ? '1' : '0');
                fd.append('show_in_homepage', e.showInHomepage ? '1' : '0');

                if (coverFileInput && coverFileInput.files && coverFileInput.files[0]) {
                    fd.append('image', coverFileInput.files[0]);
                } else if (e.cover === null && existing && existing.cover) {
                    fd.append('remove_image', '1');
                }

                saveBtn.disabled = true;
                const originalText = saveBtn.textContent;
                saveBtn.textContent = 'SAVING...';

                try {
                    const url = isNew ? '/admin/categories' : `/admin/categories/${existing.id}`;
                    const res = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken,
                            'Accept': 'application/json'
                        },
                        body: fd
                    });

                    const json = await res.json();

                    if (!res.ok) {
                        const errMsg = json.message || Object.values(json.errors || {})[0]?.[0] || 'Validation error';
                        showToast(errMsg, 'coral');
                        return;
                    }

                    const savedCat = json.category;
                    const nodeData = { ...savedCat, id: String(savedCat.id) };

                    if (!isNew) {
                        const idx = CATEGORIES.findIndex(c => String(c.id) === String(existing.id));
                        if (idx > -1) CATEGORIES[idx] = nodeData;
                    } else {
                        CATEGORIES.push(nodeData);
                        e.id = nodeData.id;
                    }
                    replaceCategory(nodeData);

                    state.dirty = false;
                    if (dirtyDotEl) dirtyDotEl.classList.remove('is-visible');
                    renderTree();
                    renderPanel();
                    showToast(json.message || 'Category saved successfully', 'lime');
                } catch (err) {
                    showToast('Failed to save category. Please try again.', 'coral');
                } finally {
                    saveBtn.disabled = false;
                    saveBtn.textContent = originalText;
                }
            });
        }

        // Delete (opens modal).
        if (deleteBtn) {
            deleteBtn.addEventListener('click', () => {
                if (deleteBtn.disabled) return;
                const e = state.editing;
                if (!e || e.id === '__new') return;
                openDeleteModal();
            });
        }
    };

    /* ─── Delete confirmation modal ────────────────────────────── */

    const modalEl = $('[data-abo-admin-categories-modal]');
    const modalBody = $('[data-abo-admin-categories-modal-body]');
    const modalConfirm = $('[data-abo-admin-categories-modal-confirm]');
    const modalCancel  = $('[data-abo-admin-categories-modal-cancel]');
    const modalCloseEls = () => $$('[data-abo-admin-categories-modal-close]');

    const openDeleteModal = () => {
        if (!modalEl) return;
        const e = state.editing;
        if (!e) return;
        const cat = node(e.id);
        if (!cat) return;
        const label = cat.name;
        if (modalBody) {
            modalBody.textContent = `Delete “${label}”? This action cannot be undone.`;
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
            const e = state.editing;
            if (!e) return;
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

            modalConfirm.disabled = true;
            try {
                const res = await fetch(`/admin/categories/${e.id}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json'
                    }
                });

                const json = await res.json();
                if (!res.ok) {
                    showToast(json.message || 'Failed to delete category', 'coral');
                    return;
                }

                const idx = CATEGORIES.findIndex((c) => String(c.id) === String(e.id));
                if (idx > -1) CATEGORIES.splice(idx, 1);

                const serverIdx = serverCategories.findIndex((c) => String(c.id) === String(e.id));
                if (serverIdx > -1) serverCategories.splice(serverIdx, 1);

                state.selectedId = null;
                state.editing = null;
                state.dirty = false;
                closeDeleteModal();
                renderTree();
                renderPanel();
                showToast(json.message || 'Category deleted successfully', 'lime');
            } catch (err) {
                showToast('Failed to delete category.', 'coral');
            } finally {
                modalConfirm.disabled = false;
            }
        });

        // ESC closes the modal.
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modalEl && !modalEl.hidden) closeDeleteModal();
        });
    };

    /* ─── Add-category button ──────────────────────────────────── */

    const addBtn = $('[data-abo-admin-categories-add]');
    if (addBtn) {
        addBtn.addEventListener('click', () => {
            state.selectedId = '__new';
            state.editing = {
                id: '__new',
                parentId: null,
                name: '',
                nameAr: '',
                slug: '',
                description: '',
                active: true,
                visibleInStore: true,
                showInHomepage: false,
                cover: null,
            };
            state.dirty = false;
            renderPanel();
            if (isMobile()) openPanel();
        });
    }

    /* ─── Initial render ─────────────────────────────────────────
     *
     * The categories arrive with the page — Admin\CategoryController
     * publishes them as `window.__CATEGORIES__` before this script
     * runs — so the tree is drawn straight away. The skeleton used to
     * sit on screen for 600 ms to simulate a fetch that does not
     * exist; there is nothing to wait for any more. */

    renderTree();
    wireTreeGlobal();
    wireToolbar();
    wirePanel();
    wireModal();

    // The panel starts in "no selection" mode — show it on mobile
    // only after the user clicks a row.
    if (isMobile() && panelEl) panelEl.classList.remove('is-open');

    // Re-render when the viewport crosses the mobile breakpoint so the
    // panel state stays in sync.
    window.addEventListener('resize', () => {
        if (!panelEl) return;
        if (!isMobile()) {
            panelEl.classList.remove('is-open');
            if (panelModalOverlay) panelModalOverlay.hidden = true;
        }
    });

    // Expose for debugging only — read-only access; mutation would
    // require a render.
    void topLevelOrdered;
    void focusRow;
})();
