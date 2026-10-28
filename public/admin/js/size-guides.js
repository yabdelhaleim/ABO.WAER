/*
 * ABO Admin · Size Guides — page-local script.
 *
 * Self-gating: init() only runs if the [data-abo-admin-size-guides]
 * is present (i.e. the size-guides page). It hydrates the list,
 * the edit panel, the preview modal and the toast from the payload
 * Admin\SizeGuideController builds out of the database, and the size
 * palette the shop sells.
 *
 * Every change goes back to the server and the screen is replaced by
 * the answer: the guide on screen is the guide the database holds.
 *
 * Sections:
 *   initTodayDate  — header date
 *   initList       — list rows, search, toggle
 *   initPanel      — select a row, edit, unit convert, add/remove
 *                    sizes & measurements, drag-reorder rows, save
 *                    / duplicate / delete, preview modal, dirty dot
 */

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
                 k === 'autocomplete' || k === 'inputmode' || k === 'dir' ||
                 k === 'src' || k === 'alt' || k === 'id') {
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

function debounce(fn, ms = 200) {
    let t;
    return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
}

let toastTimer = null;
function showToast(message, tone = 'lime') {
    const toast = $('[data-abo-admin-size-guides-toast]');
    if (!toast) return;
    const text = $('[data-abo-admin-size-guides-toast-text]', toast);
    const dot  = $('[data-abo-admin-size-guides-toast-dot]', toast);
    if (text) text.textContent = message;
    if (dot) {
        dot.classList.toggle('abo-admin-size-guides__toast-dot--lime',  tone === 'lime');
        dot.classList.toggle('abo-admin-size-guides__toast-dot--coral', tone === 'coral');
    }
    toast.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toast.hidden = true; }, 2200);
}

/* ──────────────────────────────────────────────────────────────
   Today date
   ────────────────────────────────────────────────────────────── */

function initTodayDate() {
    const node = document.getElementById('abo-admin-today-date');
    if (!node) return;
    const now = new Date();
    const opts = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
    node.textContent = now.toLocaleDateString('en-GB', opts);
}

/* ──────────────────────────────────────────────────────────────
   State
   ────────────────────────────────────────────────────────────── */

const STATE = {
    guides: [],
    palette: [],
    routes: {},
    filter: '',
    selectedId: null,    // id of the guide currently in the panel
    panelMode: 'edit',   // 'edit' | 'new' | null
    dirty: false,        // unsaved changes
    displayUnit: 'cm',   // 'cm' or 'inch' (display only, not data)
};

let editor = null;       // working copy of the currently edited guide

function getGuide(id) { return STATE.guides.find(g => g.id === id) || null; }
function nextId() { return 'sg-' + Date.now().toString(36); }

function cloneGuide(g) { return JSON.parse(JSON.stringify(g)); }

/* ──────────────────────────────────────────────────────────────
   The server boundary

   The database owns the guide: the screen hydrates from the payload
   Admin\SizeGuideController builds, and every change goes back to it
   and is replaced by the answer. The browser never keeps a copy the
   server has not confirmed.
   ────────────────────────────────────────────────────────────── */

function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

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
 * Run one request against the server.
 *
 * Rejects when the call fails, so the caller can put the row back the
 * way the database still sees it.
 */
async function api(method, url, body) {
    const response = await fetch(url, {
        method: method || 'GET',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: body === undefined ? undefined : JSON.stringify(body),
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

/**
 * Upload the how-to image of a guide.
 *
 * The image is its own request: the guide has to exist before it can
 * carry a file.
 */
async function uploadImage(guideId, file) {
    const route = STATE.routes.image;
    if (!route || !route.url || !file) return null;

    const form = new FormData();
    form.append('image_file', file);

    const response = await fetch(route.url.replace('__GUIDE__', encodeURIComponent(guideId)), {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: form,
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

/** The URL of one of the guide endpoints. */
function guideUrl(key, guideId) {
    const route = STATE.routes[key];
    if (!route || !route.url) return null;
    return route.url.replace('__GUIDE__', encodeURIComponent(guideId));
}

/**
 * The guide the server sent, in the shape the editor draws.
 *
 * The server sends the chart as rows of named measurements; the
 * editor draws a grid, so the values are laid out in the guide's
 * column order here.
 */
function guideFromPayload(payload) {
    const columns = Array.isArray(payload.measurements) ? payload.measurements : [];
    const sizes = Array.isArray(payload.sizes) ? payload.sizes : [];
    const rows = Array.isArray(payload.rows) ? payload.rows : [];

    return {
        id: String(payload.id),
        name: payload.name || '',
        name_ar: payload.name_ar || '',
        description: payload.description || '',
        note: payload.note || '',
        note_ar: payload.note_ar || '',
        unit: payload.unit === 'inch' ? 'inch' : 'cm',
        how_to: payload.how_to || '',
        how_to_ar: payload.how_to_ar || '',
        image: payload.image || null,
        image_path: payload.image_path || '',
        category_id: payload.category_id || null,
        product_count: Number(payload.product_count || 0),
        active: !!payload.active,
        measurements: columns.slice(),
        sizes: sizes.slice(),
        rows: rows.map((row) => columns.map((column) => {
            const values = row.measurements || {};
            const value = values[column];
            return value === null || value === undefined ? '' : String(value);
        })),
        // The ids of the stored rows, so saving a guide updates its
        // rows instead of replacing all of them.
        rowIds: rows.map((row) => (row.id ? String(row.id) : null)),
    };
}

/**
 * The editor's guide, in the shape the server stores.
 */
function payloadFromEditor(working) {
    const rows = working.sizes.map((size, index) => {
        const values = {};

        working.measurements.forEach((column, mi) => {
            const value = working.rows[index] ? working.rows[index][mi] : '';
            if (value !== '' && value !== null && value !== undefined) {
                values[column] = value;
            }
        });

        const row = { size_name: size, measurements: values };
        const id = working.rowIds ? working.rowIds[index] : null;
        if (id) row.id = id;

        return row;
    });

    return {
        name: working.name,
        name_ar: working.name_ar,
        description: working.description,
        note: working.note,
        note_ar: working.note_ar,
        unit: working.unit,
        how_to: working.how_to,
        how_to_ar: working.how_to_ar,
        image: working.image_path || '',
        category_id: working.category_id || null,
        is_active: !!working.active,
        measurements: working.measurements.slice(),
        rows: rows,
    };
}

/* ──────────────────────────────────────────────────────────────
   Unit conversion (display only, the data stays in cm)
   ────────────────────────────────────────────────────────────── */

function formatValue(raw, unit) {
    if (raw == null || raw === '' || isNaN(+raw)) return '';
    const n = +raw;
    if (unit === 'inch') return (n / 2.54).toFixed(1);
    // cm: drop the trailing .0 so the table stays clean
    return Number.isInteger(n) ? String(n) : n.toFixed(1);
}

function reverseValue(display, unit) {
    if (display == null || display === '' || isNaN(+display)) return '';
    const n = +display;
    if (unit === 'inch') return (n * 2.54).toFixed(1);
    return n.toFixed(1);
}

/* ──────────────────────────────────────────────────────────────
   List rendering
   ────────────────────────────────────────────────────────────── */

function updateStats() {
    // No stats on this page; categories page has them. Left empty.
}

function matchesFilter(g) {
    if (!STATE.filter) return true;
    const needle = STATE.filter.toLowerCase();
    return g.name.toLowerCase().includes(needle)
        || (g.name_ar || '').toLowerCase().includes(needle);
}

function renderList() {
    const list = $('[data-abo-admin-size-guides-list]');
    if (!list) return;
    const empty      = $('[data-abo-admin-size-guides-state="empty"]');
    const noResults  = $('[data-abo-admin-size-guides-state="noresults"]');
    const skeleton   = $('[data-abo-admin-size-guides-state="loading"]');
    if (skeleton) skeleton.hidden = true;

    const rows = STATE.guides.filter(matchesFilter);
    list.replaceChildren();

    if (STATE.guides.length === 0) {
        if (empty) { empty.hidden = false; list.hidden = true; }
        if (noResults) noResults.hidden = true;
        return;
    }
    if (empty) { empty.hidden = true; list.hidden = false; }
    if (rows.length === 0) {
        if (noResults) { noResults.hidden = false; list.hidden = true; }
        return;
    }
    if (noResults) noResults.hidden = true;
    list.hidden = false;

    rows.forEach(g => list.appendChild(rowFor(g)));
    syncSelectedRow();
}

function rowFor(g) {
    const li = el('li', { role: 'option' });
    const btn = el('button', {
        type: 'button',
        class: 'abo-admin-size-guides__row',
        dataset: { aboAdminSizeGuidesRow: g.id },
        'aria-selected': g.id === STATE.selectedId ? 'true' : 'false',
    });

    const text = el('div', { class: 'abo-admin-size-guides__row-text' });
    text.appendChild(el('p', { class: 'abo-admin-size-guides__row-name', text: g.name || 'Untitled guide' }));

    const meta = el('p', { class: 'abo-admin-size-guides__row-meta' });
    const sizesCount = g.sizes.length;
    const measCount  = g.measurements.length;
    meta.appendChild(document.createTextNode(
        `${sizesCount} size${sizesCount === 1 ? '' : 's'}, ${measCount} measurement${measCount === 1 ? '' : 's'}`
    ));
    meta.appendChild(el('span', { class: 'abo-admin-size-guides__row-meta-sep', text: '·' }));
    const productsSpan = el('span', {
        class: 'abo-admin-size-guides__row-products' +
               ((g.product_count || 0) === 0 ? ' abo-admin-size-guides__row-products--zero' : ''),
        text: `${g.product_count || 0} product${(g.product_count || 0) === 1 ? '' : 's'}`,
    });
    meta.appendChild(productsSpan);
    text.appendChild(meta);

    const toggle = el('label', { class: 'abo-admin-size-guides__row-toggle' });
    const input  = el('input', {
        type: 'checkbox',
        'aria-label': `Active for ${g.name}`,
        dataset: { aboAdminSizeGuidesRowToggle: g.id },
    });
    input.checked = !!g.active;
    input.setAttribute('aria-checked', input.checked ? 'true' : 'false');
    toggle.appendChild(input);
    toggle.appendChild(el('span', { class: 'abo-admin-size-guides__row-toggle-track' }));
    toggle.appendChild(el('span', { class: 'abo-admin-size-guides__row-toggle-thumb' }));

    btn.append(text, toggle);
    li.appendChild(btn);
    return li;
}

function syncSelectedRow() {
    $$('[data-abo-admin-size-guides-row]').forEach(btn => {
        const on = btn.dataset.aboAdminSizeGuidesRow === STATE.selectedId;
        btn.classList.toggle('is-selected', on);
        btn.setAttribute('aria-selected', on ? 'true' : 'false');
    });
}

/**
 * Show or hide a guide.
 *
 * The switch moves on screen first, and is put back where the
 * database still sees it when the server refuses the change. The
 * guide on screen is the guide the server answered with.
 */
async function toggleGuide(g, input) {
    const next = !g.active;
    const previous = g.active;

    g.active = next;
    input.checked = next;
    input.setAttribute('aria-checked', next ? 'true' : 'false');

    const url = guideUrl('toggle', g.id);

    if (!url) {
        g.active = previous;
        input.checked = previous;
        input.setAttribute('aria-checked', previous ? 'true' : 'false');
        showToast('The size guide endpoint is not configured.', 'coral');
        return;
    }

    try {
        const response = await api('PATCH', url, { is_active: next });
        const saved = guideFromPayload(response.guide);
        const index = STATE.guides.findIndex((x) => x.id === saved.id);
        if (index > -1) STATE.guides[index] = saved;
        if (STATE.selectedId === saved.id && editor) {
            editor.active = saved.active;
            renderEditor();
        }
        renderList();
        showToast(response.message || 'Saved');
    } catch (err) {
        g.active = previous;
        input.checked = previous;
        input.setAttribute('aria-checked', previous ? 'true' : 'false');
        showToast(err.message, 'coral');
    }
}

/* ──────────────────────────────────────────────────────────────
   Editor + panel
   ────────────────────────────────────────────────────────────── */

function setDirty(v) {
    STATE.dirty = v;
    const dot = $('[data-abo-admin-size-guides-dirty-dot]');
    if (dot) dot.classList.toggle('is-visible', v);
}

function openEditor(id) {
    const g = getGuide(id);
    if (!g) return;
    STATE.selectedId = id;
    STATE.panelMode  = 'edit';
    STATE.displayUnit = g.unit === 'inch' ? 'inch' : 'cm';
    editor = cloneGuide(g);
    const form = $('[data-abo-admin-size-guides-panel-form]');
    const noSel = $('[data-abo-admin-size-guides-panel-no-selection]');
    if (form)  form.hidden  = false;
    if (noSel) noSel.hidden = true;
    $('[data-abo-admin-size-guides-panel-title-text]').textContent = 'EDIT GUIDE';
    syncSelectedRow();
    openPanelOverlay();
    setDirty(false);
    renderEditor();
    syncEditorUnit();
}

function newGuide() {
    STATE.selectedId = null;
    STATE.panelMode  = 'new';
    STATE.displayUnit = 'cm';
    editor = {
        id: nextId(),
        name: '',
        name_ar: '',
        sizes: ['S', 'M', 'L'],
        measurements: ['Chest', 'Length'],
        rows: [
            ['', ''],
            ['', ''],
            ['', ''],
        ],
        // The ids of the stored rows — a new guide has none yet.
        rowIds: [null, null, null],
        description: '',
        note: '',
        note_ar: '',
        unit: 'cm',
        how_to: '',
        how_to_ar: '',
        image: null,
        image_path: '',
        image_file: null,
        category_id: null,
        product_count: 0,
        active: true,
    };
    const form = $('[data-abo-admin-size-guides-panel-form]');
    const noSel = $('[data-abo-admin-size-guides-panel-no-selection]');
    if (form)  form.hidden  = false;
    if (noSel) noSel.hidden = true;
    $('[data-abo-admin-size-guides-panel-title-text]').textContent = 'NEW GUIDE';
    syncSelectedRow();
    openPanelOverlay();
    setDirty(false);
    renderEditor();
    syncEditorUnit();
}

function closePanel() {
    STATE.selectedId = null;
    STATE.panelMode  = null;
    editor = null;
    const form = $('[data-abo-admin-size-guides-panel-form]');
    const noSel = $('[data-abo-admin-size-guides-panel-no-selection]');
    if (form)  form.hidden  = true;
    if (noSel) noSel.hidden = false;
    closePanelOverlay();
    syncSelectedRow();
    setDirty(false);
}

function openPanelOverlay() {
    const overlay = $('[data-abo-admin-size-guides-panel-overlay]');
    const panel   = $('[data-abo-admin-size-guides-panel]');
    if (overlay) overlay.hidden = false;
    if (panel)   panel.classList.add('is-open');
}

function closePanelOverlay() {
    const overlay = $('[data-abo-admin-size-guides-panel-overlay]');
    const panel   = $('[data-abo-admin-size-guides-panel]');
    if (overlay) overlay.hidden = true;
    if (panel)   panel.classList.remove('is-open');
}

function renderEditor() {
    if (!editor) return;
    $('[data-abo-admin-size-guides-name]').value    = editor.name    || '';
    $('[data-abo-admin-size-guides-name-ar]').value = editor.name_ar || '';

    // The category the guide belongs to. The storefront reads the
    // chart of the product's category, so this is what puts the chart
    // on the product page.
    const categorySel = $('[data-abo-admin-size-guides-category]');
    if (categorySel) categorySel.value = editor.category_id || '';

    // Note + how-to: show only the EN or AR textarea based on the
    // currently selected language tab.
    syncBilingual('note');
    syncBilingual('howto');

    // Active toggle
    const activeInput = $('[data-abo-admin-size-guides-active]');
    if (activeInput) {
        activeInput.checked = !!editor.active;
        activeInput.setAttribute('aria-checked', activeInput.checked ? 'true' : 'false');
    }

    // Image preview
    const preview = $('[data-abo-admin-size-guides-howto-preview]');
    if (preview) {
        preview.replaceChildren();
        if (editor.image) {
            preview.appendChild(el('img', { src: editor.image, alt: '' }));
        } else {
            preview.appendChild(el('span', { class: 'abo-admin-size-guides__howto-letter', text: 'IMG' }));
        }
    }
    const removeBtn = $('[data-abo-admin-size-guides-howto-image-remove]');
    if (removeBtn) removeBtn.disabled = !editor.image;

    // Table + delete note
    renderChartTable();
    updateDeleteNote();
}

function syncBilingual(kind) {
    // kind: 'note' or 'howto'
    const suffix = kind === 'note' ? 'note' : 'howto';
    const langSel = $(`[data-abo-admin-size-guides-${suffix}-lang]`);
    const enTextarea = $(`[data-abo-admin-size-guides-${suffix}]`);
    const arTextarea = $(`[data-abo-admin-size-guides-${suffix}-ar]`);
    if (!enTextarea || !arTextarea || !langSel) return;
    let lang = 'en';
    const active = langSel.querySelector('.is-active');
    if (active && active.dataset.aboAdminSizeGuidesNoteLangBtn) lang = active.dataset.aboAdminSizeGuidesNoteLangBtn;
    if (kind === 'howto' && active && active.dataset.aboAdminSizeGuidesHowtoLangBtn) {
        lang = active.dataset.aboAdminSizeGuidesHowtoLangBtn;
    }
    if (lang === 'ar') {
        enTextarea.hidden = true;
        arTextarea.hidden = false;
    } else {
        enTextarea.hidden = false;
        arTextarea.hidden = true;
    }
}

function syncEditorUnit() {
    $$('[data-abo-admin-size-guides-unit-btn]').forEach(btn => {
        const on = btn.dataset.aboAdminSizeGuidesUnitBtn === STATE.displayUnit;
        btn.classList.toggle('is-active', on);
        btn.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    renderChartTable();
}

function updateDeleteNote() {
    const note = $('[data-abo-admin-size-guides-delete-note]');
    const btn  = $('[data-abo-admin-size-guides-delete]');
    if (!note || !btn || !editor) return;
    const used = (editor.product_count || 0) > 0;
    btn.disabled = used;
    note.hidden = !used;
}

/* ─── Chart table (rows = sizes, columns = measurements) ─── */

function renderChartTable() {
    if (!editor) return;
    const table = $('[data-abo-admin-size-guides-table]');
    if (!table) return;
    const unit = STATE.displayUnit;

    // Empty table state
    const emptyTbl = $('[data-abo-admin-size-guides-empty-table]');
    if (emptyTbl) emptyTbl.hidden = !(editor.sizes.length === 0);

    // Clear
    table.replaceChildren();
    table.appendChild(el('caption', { class: 'sr-only', text: `${editor.name || 'Untitled guide'} size chart` }));

    if (editor.sizes.length === 0) return;

    // Ensure rows are aligned with sizes: if a new size was added,
    // extend rows with blanks; if a size was removed, drop the row.
    editor.rows = editor.rows.slice(0, editor.sizes.length);
    while (editor.rows.length < editor.sizes.length) {
        editor.rows.push(new Array(editor.measurements.length).fill(''));
    }
    // Ensure each row has the right number of cells.
    editor.rows.forEach(r => {
        while (r.length < editor.measurements.length) r.push('');
        if (r.length > editor.measurements.length) r.length = editor.measurements.length;
    });

    // Head: drag column + size column + N measurement columns + remove column
    const head = el('thead');
    const headRow = el('tr');
    headRow.appendChild(el('th', { scope: 'col', 'aria-label': 'Drag' }));    // drag handle column
    headRow.appendChild(el('th', { scope: 'col', class: 'abo-admin-size-guides__col-size-head', text: 'Size' }));
    editor.measurements.forEach((m, mi) => {
        const th = el('th', { scope: 'col' });
        const wrap = el('div', { class: 'abo-admin-size-guides__col-head' });
        const input = el('input', {
            type: 'text',
            value: m,
            class: 'abo-admin-size-guides__col-head-input',
            'aria-label': `Measurement ${mi + 1} name`,
            dataset: { aboAdminSizeGuidesCol: String(mi) },
        });
        const remove = el('button', {
            type: 'button',
            class: 'abo-admin-size-guides__col-remove',
            'aria-label': 'Remove column',
            text: '×',
            dataset: { aboAdminSizeGuidesColRemove: String(mi) },
        });
        wrap.append(input, remove);
        th.appendChild(wrap);
        headRow.appendChild(th);
    });
    head.appendChild(headRow);
    table.appendChild(head);

    // Body
    const body = el('tbody');
    editor.sizes.forEach((s, si) => {
        const tr = el('tr', {
            class: 'abo-admin-size-guides__data-row',
            draggable: 'true',
            dataset: { aboAdminSizeGuidesRowIndex: String(si) },
        });

        // Drag handle cell
        const dragTd = el('td');
        const dragBtn = el('button', {
            type: 'button',
            class: 'abo-admin-size-guides__drag',
            'aria-label': `Drag to reorder ${s}`,
            text: '⋮⋮',
        });
        dragTd.appendChild(dragBtn);
        tr.appendChild(dragTd);

        // Size chip cell (read-only hairline chip)
        const sizeTh = el('th', { scope: 'row' });
        sizeTh.appendChild(el('span', { class: 'abo-admin-size-guides__row-size-chip', text: s }));
        tr.appendChild(sizeTh);

        // Measurement cells
        editor.measurements.forEach((_, mi) => {
            const td = el('td');
            const raw = editor.rows[si]?.[mi] ?? '';
            const display = formatValue(raw, unit);
            const input = el('input', {
                type: 'number',
                inputmode: 'decimal',
                step: '0.1',
                min: '0',
                value: display,
                'aria-label': `${s} ${editor.measurements[mi] || `measurement ${mi + 1}`} in ${unit}`,
                class: 'abo-admin-size-guides__cell-input' +
                       (raw === '' ? ' abo-admin-size-guides__cell-input--empty' : ''),
                dataset: { aboAdminSizeGuidesCell: `${si}:${mi}` },
            });
            td.appendChild(input);
            tr.appendChild(td);
        });

        // Remove row cell
        const removeTd = el('td');
        const removeBtn = el('button', {
            type: 'button',
            class: 'abo-admin-size-guides__row-remove',
            'aria-label': `Remove size row ${s}`,
            text: '×',
            dataset: { aboAdminSizeGuidesRowRemove: String(si) },
        });
        removeTd.appendChild(removeBtn);
        tr.appendChild(removeTd);

        body.appendChild(tr);
    });
    table.appendChild(body);

    updateEmptyCellsNote();
}

function updateEmptyCellsNote() {
    if (!editor) return;
    const hasEmpty = editor.rows.some(r => r.some(c => c === '' || c == null));
    const note = $('[data-abo-admin-size-guides-empty-note]');
    if (note) note.hidden = !hasEmpty;
}

/* ─── Wire up editor events (delegated) ──────────────────── */

function initEditor() {
    const root = $('[data-abo-admin-size-guides-panel-form]');
    if (!root || root.dataset.bound === '1') return;
    root.dataset.bound = '1';

    // Name fields
    const nameInput = $('[data-abo-admin-size-guides-name]');
    if (nameInput) nameInput.addEventListener('input', () => {
        editor.name = nameInput.value;
        setDirty(true);
    });
    const nameArInput = $('[data-abo-admin-size-guides-name-ar]');
    if (nameArInput) nameArInput.addEventListener('input', () => {
        editor.name_ar = nameArInput.value;
        setDirty(true);
    });

    // Bilingual tabs (note + how-to)
    $$('[data-abo-admin-size-guides-note-lang-btn], [data-abo-admin-size-guides-howto-lang-btn]').forEach(btn => {
        btn.addEventListener('click', () => {
            const group = btn.parentElement;
            $$('button', group).forEach(b => {
                b.classList.toggle('is-active', b === btn);
                b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
            });
            if (btn.dataset.aboAdminSizeGuidesNoteLangBtn) syncBilingual('note');
            if (btn.dataset.aboAdminSizeGuidesHowtoLangBtn) syncBilingual('howto');
        });
    });

    // Note textareas (EN/AR)
    const noteEn = $('[data-abo-admin-size-guides-note]');
    if (noteEn) noteEn.addEventListener('input', () => { editor.note = noteEn.value; setDirty(true); });
    const noteAr = $('[data-abo-admin-size-guides-note-ar]');
    if (noteAr) noteAr.addEventListener('input', () => { editor.note_ar = noteAr.value; setDirty(true); });

    // The category the guide belongs to.
    const categorySel = $('[data-abo-admin-size-guides-category]');
    if (categorySel) categorySel.addEventListener('change', () => {
        editor.category_id = categorySel.value || null;
        setDirty(true);
    });

    // How-to textareas
    const howtoEn = $('[data-abo-admin-size-guides-howto]');
    if (howtoEn) howtoEn.addEventListener('input', () => { editor.how_to = howtoEn.value; setDirty(true); });
    const howtoAr = $('[data-abo-admin-size-guides-howto-ar]');
    if (howtoAr) howtoAr.addEventListener('input', () => { editor.how_to_ar = howtoAr.value; setDirty(true); });

    // Unit switch
    $$('[data-abo-admin-size-guides-unit-btn]').forEach(btn => {
        btn.addEventListener('click', () => {
            const target = btn.dataset.aboAdminSizeGuidesUnitBtn;
            // Display only. If the user flips back, the original
            // cm values are still on the rows because we always
            // reverse-format on input. So flipping the unit is
            // reversible.
            STATE.displayUnit = target;
            syncEditorUnit();
        });
    });

    // Cell input (delegated on table) — write back into editor.rows
    const table = $('[data-abo-admin-size-guides-table]');
    if (table) {
        table.addEventListener('input', (e) => {
            const t = e.target;
            if (!t.dataset.aboAdminSizeGuidesCell) return;
            const [si, mi] = t.dataset.aboAdminSizeGuidesCell.split(':').map(Number);
            const raw = STATE.displayUnit === 'inch' ? reverseValue(t.value, 'inch') : t.value;
            editor.rows[si][mi] = raw;
            t.classList.toggle('abo-admin-size-guides__cell-input--empty', raw === '');
            setDirty(true);
            updateEmptyCellsNote();
        });
        // Column header rename
        table.addEventListener('input', (e) => {
            const t = e.target;
            if (!t.dataset.aboAdminSizeGuidesCol) return;
            const mi = +t.dataset.aboAdminSizeGuidesCol;
            editor.measurements[mi] = t.value;
            setDirty(true);
            updateEmptyCellsNote();
        });
        // Column remove
        table.addEventListener('click', (e) => {
            const t = e.target.closest('[data-abo-admin-size-guides-col-remove]');
            if (!t) return;
            const mi = +t.dataset.aboAdminSizeGuidesColRemove;
            editor.measurements.splice(mi, 1);
            editor.rows.forEach(r => r.splice(mi, 1));
            setDirty(true);
            renderChartTable();
        });
        // Row remove
        table.addEventListener('click', (e) => {
            const t = e.target.closest('[data-abo-admin-size-guides-row-remove]');
            if (!t) return;
            const si = +t.dataset.aboAdminSizeGuidesRowRemove;
            editor.sizes.splice(si, 1);
            editor.rows.splice(si, 1);
            if (editor.rowIds) editor.rowIds.splice(si, 1);
            setDirty(true);
            renderChartTable();
        });
        // Drag-and-drop row reorder
        let dragSrc = null;
        table.addEventListener('dragstart', (e) => {
            const tr = e.target.closest('[data-abo-admin-size-guides-data-row]');
            if (!tr) return;
            dragSrc = +tr.dataset.aboAdminSizeGuidesRowIndex;
            tr.classList.add('is-dragging');
            e.dataTransfer.effectAllowed = 'move';
        });
        table.addEventListener('dragend', (e) => {
            const tr = e.target.closest('[data-abo-admin-size-guides-data-row]');
            if (tr) tr.classList.remove('is-dragging');
            $$('.abo-admin-size-guides__row.is-drop-target').forEach(r => r.classList.remove('is-drop-target'));
        });
        table.addEventListener('dragover', (e) => {
            const tr = e.target.closest('[data-abo-admin-size-guides-data-row]');
            if (!tr || dragSrc == null) return;
            e.preventDefault();
            tr.classList.add('is-drop-target');
        });
        table.addEventListener('dragleave', (e) => {
            const tr = e.target.closest('[data-abo-admin-size-guides-data-row]');
            if (tr) tr.classList.remove('is-drop-target');
        });
        table.addEventListener('drop', (e) => {
            const tr = e.target.closest('[data-abo-admin-size-guides-data-row]');
            if (!tr || dragSrc == null) return;
            e.preventDefault();
            tr.classList.remove('is-drop-target');
            const dst = +tr.dataset.aboAdminSizeGuidesRowIndex;
            if (dragSrc === dst) return;
            const [movedSize] = editor.sizes.splice(dragSrc, 1);
            const [movedRow]  = editor.rows.splice(dragSrc, 1);
            const [movedId]   = editor.rowIds ? editor.rowIds.splice(dragSrc, 1) : [null];
            editor.sizes.splice(dst, 0, movedSize);
            editor.rows.splice(dst, 0, movedRow);
            if (editor.rowIds) editor.rowIds.splice(dst, 0, movedId);
            dragSrc = null;
            // The order is part of the guide, so it is saved with it.
            setDirty(true);
            renderChartTable();
        });
    }

    // Add size row
    const addSizeBtn = $('[data-abo-admin-size-guides-add-size]');
    const addSizeSel = $('[data-abo-admin-size-guides-add-size-select]');
    if (addSizeBtn && addSizeSel) {
        addSizeBtn.addEventListener('click', () => {
            // Populate the select with the palette minus already-used.
            const used = new Set(editor.sizes);
            const remaining = STATE.palette.filter(s => !used.has(s));
            addSizeSel.replaceChildren();
            if (remaining.length === 0) {
                addSizeSel.appendChild(el('option', { value: '', text: 'No more sizes' }));
                addSizeSel.disabled = true;
            } else {
                addSizeSel.disabled = false;
                addSizeSel.appendChild(el('option', { value: '', text: 'Pick a size…' }));
                remaining.forEach(s => {
                    addSizeSel.appendChild(el('option', { value: s, text: s }));
                });
            }
            const open = !addSizeSel.hidden;
            addSizeSel.hidden = open;
            if (!open) addSizeSel.focus();
        });
        addSizeSel.addEventListener('change', () => {
            const v = addSizeSel.value;
            if (!v) return;
            editor.sizes.push(v);
            editor.rows.push(new Array(editor.measurements.length).fill(''));
            if (editor.rowIds) editor.rowIds.push(null);
            setDirty(true);
            addSizeSel.hidden = true;
            addSizeSel.value = '';
            renderChartTable();
        });
        addSizeSel.addEventListener('blur', () => {
            // Close the select when focus leaves.
            setTimeout(() => { addSizeSel.hidden = true; }, 150);
        });
    }

    // Add measurement
    const addMeasBtn = $('[data-abo-admin-size-guides-add-measurement]');
    if (addMeasBtn) {
        addMeasBtn.addEventListener('click', () => {
            if (editor.measurements.length >= 6) return; // spec: max 6
            editor.measurements.push(`Measurement ${editor.measurements.length + 1}`);
            editor.rows.forEach(r => r.push(''));
            setDirty(true);
            renderChartTable();
        });
    }

    // How-to image: change / remove
    const fileInput = $('[data-abo-admin-size-guides-howto-file]');
    const changeBtn = $('[data-abo-admin-size-guides-howto-change]');
    if (changeBtn) changeBtn.addEventListener('click', () => fileInput?.click());
    if (fileInput) fileInput.addEventListener('change', () => {
        const file = fileInput.files?.[0];
        if (!file) return;
        // The file is uploaded once the guide has an id to hold it.
        editor.image_file = file;
        editor.image = URL.createObjectURL(file);
        setDirty(true);
        renderEditor();
    });
    const removeImgBtn = $('[data-abo-admin-size-guides-howto-image-remove]');
    if (removeImgBtn) removeImgBtn.addEventListener('click', () => {
        if (!editor.image && !editor.image_file) return;
        editor.image = null;
        editor.image_path = '';
        editor.image_file = null;
        setDirty(true);
        renderEditor();
    });

    // Active toggle
    const activeInput = $('[data-abo-admin-size-guides-active]');
    if (activeInput) activeInput.addEventListener('change', () => {
        editor.active = activeInput.checked;
        activeInput.setAttribute('aria-checked', activeInput.checked ? 'true' : 'false');
        setDirty(true);
    });

    // Save / Duplicate / Delete / Preview
    const saveBtn = $('[data-abo-admin-size-guides-save]');
    if (saveBtn) saveBtn.addEventListener('click', async () => {
        if (!editor) return;

        const working = cloneGuide(editor);
        const isNew = STATE.panelMode === 'new';

        saveBtn.disabled = true;

        try {
            const url = isNew ? STATE.routes.store?.url : guideUrl('update', working.id);
            if (!url) throw new Error('The size guide endpoint is not configured.');

            const response = await api(isNew ? 'POST' : 'PUT', url, payloadFromEditor(working));

            // The server's guide replaces the local copy — including
            // the ids it gave the rows.
            let saved = guideFromPayload(response.guide);

            // A new image is uploaded once the guide exists.
            if (working.image_file) {
                const uploaded = await uploadImage(saved.id, working.image_file);
                if (uploaded && uploaded.guide) {
                    saved = guideFromPayload(uploaded.guide);
                }
            }

            if (isNew) {
                STATE.guides.unshift(saved);
            } else {
                const index = STATE.guides.findIndex((g) => g.id === saved.id);
                if (index > -1) STATE.guides[index] = saved;
            }

            STATE.selectedId = saved.id;
            STATE.panelMode = 'edit';
            setDirty(false);
            renderList();
            openEditor(saved.id);
            showToast(response.message || 'Saved');
        } catch (err) {
            showToast(err.message, 'coral');
        } finally {
            saveBtn.disabled = false;
        }
    });
    const dupBtn = $('[data-abo-admin-size-guides-duplicate]');
    if (dupBtn) dupBtn.addEventListener('click', async () => {
        if (!editor) return;

        const url = guideUrl('duplicate', editor.id);
        if (!url) {
            showToast('The size guide endpoint is not configured.', 'coral');
            return;
        }

        dupBtn.disabled = true;

        try {
            const response = await api('POST', url, {});
            const copy = guideFromPayload(response.guide);
            STATE.guides.unshift(copy);
            STATE.selectedId = copy.id;
            STATE.panelMode = 'edit';
            setDirty(false);
            renderList();
            openEditor(copy.id);
            showToast(response.message || 'Duplicated');
        } catch (err) {
            showToast(err.message, 'coral');
        } finally {
            dupBtn.disabled = false;
        }
    });
    const delBtn = $('[data-abo-admin-size-guides-delete]');
    if (delBtn) delBtn.addEventListener('click', () => {
        if (!editor) return;
        if (delBtn.disabled) return;
        openDeleteModal();
    });
    const prevBtn = $('[data-abo-admin-size-guides-preview]');
    if (prevBtn) prevBtn.addEventListener('click', openPreviewModal);
}

/* ──────────────────────────────────────────────────────────────
   Modals (preview + delete confirm)
   ────────────────────────────────────────────────────────────── */

function openPreviewModal() {
    if (!editor) return;
    const modal  = $('[data-abo-admin-size-guides-preview-modal]');
    const body   = $('[data-abo-admin-size-guides-preview-modal-body]');
    if (!modal || !body) return;
    const title  = $('[data-abo-admin-size-guides-preview-modal-title]');
    if (title) title.textContent = `${(editor.name || 'GUIDE PREVIEW').toUpperCase()} · PREVIEW`;

    body.replaceChildren();
    const wrap = el('div', { class: 'abo-admin-size-guides__preview-body' });

    // Eyebrow + title
    const eyebrow = el('p', { class: 'abo-admin-size-guides__preview-eyebrow' }, 'SIZE CHART');
    const titleEl = el('h3', { class: 'abo-admin-size-guides__preview-title' });
    titleEl.appendChild(document.createTextNode('FIND YOUR '));
    titleEl.appendChild(el('span', { class: 'abo-admin-size-guides__preview-title-accent', text: 'FIT.' }));
    wrap.append(eyebrow, titleEl);

    if (editor.note) {
        wrap.appendChild(el('p', { class: 'abo-admin-size-guides__preview-note', text: editor.note }));
    }

    // Table
    if (editor.sizes.length > 0 && editor.measurements.length > 0) {
        const tableWrap = el('div', { class: 'abo-admin-size-guides__preview-table-wrap' });
        const table = el('table', { class: 'abo-admin-size-guides__preview-table' });
        table.appendChild(el('caption', { class: 'sr-only', text: editor.name || 'Size chart' }));
        const thead = el('thead');
        const trh = el('tr');
        trh.appendChild(el('th', { scope: 'col', text: 'Size' }));
        editor.measurements.forEach(m => trh.appendChild(el('th', { scope: 'col', text: m })));
        thead.appendChild(trh);
        table.appendChild(thead);
        const tbody = el('tbody');
        editor.sizes.forEach((s, si) => {
            const tr = el('tr');
            tr.appendChild(el('th', { scope: 'row', text: s }));
            editor.measurements.forEach((_, mi) => {
                const v = editor.rows[si]?.[mi] ?? '';
                tr.appendChild(el('td', { text: v === '' ? '—' : v }));
            });
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        tableWrap.appendChild(table);
        wrap.appendChild(tableWrap);
    } else {
        wrap.appendChild(el('p', { class: 'abo-admin-size-guides__preview-empty', text: 'No size chart to preview yet.' }));
    }

    // How to measure
    if (editor.how_to) {
        const howtoBox = el('div', { class: 'abo-admin-size-guides__preview-howto' });
        howtoBox.appendChild(el('p', { class: 'abo-admin-size-guides__preview-howto-title', text: 'HOW TO MEASURE' }));
        howtoBox.appendChild(el('p', { class: 'abo-admin-size-guides__preview-howto-text', text: editor.how_to }));
        if (editor.image) {
            howtoBox.appendChild(el('img', {
                class: 'abo-admin-size-guides__preview-howto-img',
                src: editor.image, alt: 'How to measure',
            }));
        }
        wrap.appendChild(howtoBox);
    }

    body.appendChild(wrap);
    modal.hidden = false;
}

function closePreviewModal() {
    const modal = $('[data-abo-admin-size-guides-preview-modal]');
    if (modal) modal.hidden = true;
}

let deleteHandler = null;
function openDeleteModal() {
    const modal = $('[data-abo-admin-size-guides-modal]');
    if (!modal) return;
    const body = $('[data-abo-admin-size-guides-modal-body]', modal);
    if (body) body.textContent = `Delete "${editor?.name || 'this guide'}"? This action cannot be undone.`;
    modal.hidden = false;
    deleteHandler = async () => {
        if (!editor) return;

        const target = editor;
        const url = guideUrl('destroy', target.id);

        if (!url) {
            showToast('The size guide endpoint is not configured.', 'coral');
            return;
        }

        try {
            const response = await api('DELETE', url);
            STATE.guides = STATE.guides.filter(g => g.id !== target.id);
            closePanel();
            renderList();
            showToast(response.message || 'Guide deleted', 'coral');
        } catch (err) {
            showToast(err.message, 'coral');
        }
    };
}
function closeDeleteModal() {
    const modal = $('[data-abo-admin-size-guides-modal]');
    if (modal) modal.hidden = true;
    deleteHandler = null;
}

function initModals() {
    const preview = $('[data-abo-admin-size-guides-preview-modal]');
    if (preview && preview.dataset.bound !== '1') {
        preview.dataset.bound = '1';
        $$('[data-abo-admin-size-guides-preview-modal-close]', preview).forEach(b =>
            b.addEventListener('click', closePreviewModal)
        );
    }
    const del = $('[data-abo-admin-size-guides-modal]');
    if (del && del.dataset.bound !== '1') {
        del.dataset.bound = '1';
        $$('[data-abo-admin-size-guides-modal-close]', del).forEach(b =>
            b.addEventListener('click', closeDeleteModal)
        );
        $('[data-abo-admin-size-guides-modal-cancel]', del)
            ?.addEventListener('click', closeDeleteModal);
        $('[data-abo-admin-size-guides-modal-confirm]', del)
            ?.addEventListener('click', () => {
                const h = deleteHandler;
                closeDeleteModal();
                if (h) h();
            });
    }
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if (preview && !preview.hidden) closePreviewModal();
        else if (del && !del.hidden) closeDeleteModal();
    });
}

/* ──────────────────────────────────────────────────────────────
   List page wiring
   ────────────────────────────────────────────────────────────── */

function initList() {
    const list = $('[data-abo-admin-size-guides-list]');
    if (!list) return;

    // Read the data and the routes the controller shipped with the page.
    const dataEl = $('[data-abo-admin-size-guides-data]');
    const sizeEl = $('[data-abo-admin-size-guides-sizes]');
    const routeEl = $('[data-abo-admin-size-guides-routes]');
    try {
        const payload = dataEl ? JSON.parse(dataEl.textContent || '[]') : [];
        STATE.guides = (Array.isArray(payload) ? payload : []).map(guideFromPayload);
        STATE.palette = sizeEl ? JSON.parse(sizeEl.textContent || '[]') : [];
    } catch (e) {
        STATE.guides  = [];
        STATE.palette = [];
    }
    try {
        STATE.routes = routeEl ? JSON.parse(routeEl.textContent || '{}') : {};
    } catch (e) {
        STATE.routes = {};
    }

    initEditor();
    initModals();

    // Search
    const search = $('[data-abo-admin-size-guides-search]');
    if (search) {
        search.addEventListener('input', debounce((e) => {
            STATE.filter = e.target.value.trim();
            renderList();
        }, 150));
    }

    // Add buttons (header + empty state)
    $$('[data-abo-admin-size-guides-add], [data-abo-admin-size-guides-add-empty]').forEach(btn => {
        btn.addEventListener('click', () => {
            newGuide();
        });
    });

    // Row click → select
    list.addEventListener('click', (e) => {
        // Toggle first (the row's toggle is inside a <label>)
        const toggle = e.target.closest('[data-abo-admin-size-guides-row-toggle]');
        if (toggle) {
            const id = toggle.dataset.aboAdminSizeGuidesRowToggle;
            const g = getGuide(id);
            if (g) toggleGuide(g, toggle);
            return;
        }
        // Otherwise: select the row
        const row = e.target.closest('[data-abo-admin-size-guides-row]');
        if (row) openEditor(row.dataset.aboAdminSizeGuidesRow);
    });

    // Close panel button + overlay
    $$('[data-abo-admin-size-guides-panel-close]').forEach(btn => {
        btn.addEventListener('click', closePanel);
    });
    const overlay = $('[data-abo-admin-size-guides-panel-overlay]');
    if (overlay) overlay.addEventListener('click', closePanel);

    // Keyboard: arrow keys + Enter on rows
    list.addEventListener('keydown', (e) => {
        const rows = $$('[data-abo-admin-size-guides-row]');
        if (rows.length === 0) return;
        const current = STATE.selectedId
            ? rows.findIndex(r => r.dataset.aboAdminSizeGuidesRow === STATE.selectedId)
            : -1;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            const next = rows[Math.min(current + 1, rows.length - 1)];
            next?.focus();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            const next = rows[Math.max(current - 1, 0)];
            next?.focus();
        } else if (e.key === 'Enter' || e.key === ' ') {
            if (e.target.closest('[data-abo-admin-size-guides-row]')) {
                e.preventDefault();
                openEditor(e.target.closest('[data-abo-admin-size-guides-row]').dataset.aboAdminSizeGuidesRow);
            }
        }
    });

    // Initial render and selection
    renderList();
    if (STATE.guides.length > 0) {
        openEditor(STATE.guides[0].id);
    }
}

/* ──────────────────────────────────────────────────────────────
   Init
   ────────────────────────────────────────────────────────────── */

document.addEventListener('DOMContentLoaded', () => {
    initTodayDate();
    initList();
});
