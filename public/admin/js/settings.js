/*
 * ABO Admin · Settings — page-local script.
 *
 * Self-gating: the bootstrap function checks for the root element
 * and exits silently if not present. Loaded only on /admin/settings
 * via @stack('scripts') so other admin pages never see a console
 * error from this file.
 *
 * NO network calls. NO localStorage. NO IndexedDB. Every value is
 * either hard-coded at the top of the view, passed via data-*
 * attributes on the settings root, or read from the DOM. Save is
 * visual only — clicking SAVE shows the "Saved (demo)" toast.
 *
 * Sections:
 *   initTodayDate      — set the header date (matches dashboard)
 *   initToast          — single shared toast
 *   initModal          — single shared confirm modal
 *   loadFromDom        — hydrate STATE from data-* attributes
 *   initFields         — hydrate inputs/toggles/textareas/selects,
 *                        listen for changes, mark the form dirty
 *   initTabs           — hash-driven section switching + sub-nav state
 *   initFileInputs     — URL.createObjectURL previews for logo / favicon
 *   initReasons        — render + drag reorder + add / delete + confirm
 *   initReceiptPreview — live receipt paper that mirrors the form
 *   initSaveBar        — visible when the form is dirty
 *   initActions        — restore defaults, discard, save (toast)
 */

/* ──────────────────────────────────────────────────────────────
   Tiny helpers
   ────────────────────────────────────────────────────────────── */

var $  = window.$  || ((sel, root = document) => root.querySelector(sel));
var $$ = window.$$ || ((sel, root = document) => Array.from(root.querySelectorAll(sel)));

function escapeHTML(s) {
    return String(s ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function getNested(obj, path) {
    return path.split('.').reduce((acc, key) => (acc == null ? acc : acc[key]), obj);
}

function setNested(obj, path, value) {
    const keys = path.split('.');
    let cur = obj;
    for (let i = 0; i < keys.length - 1; i++) {
        const k = keys[i];
        if (cur[k] == null || typeof cur[k] !== 'object') cur[k] = {};
        cur = cur[k];
    }
    cur[keys[keys.length - 1]] = value;
}

/* ──────────────────────────────────────────────────────────────
   Today date (matches dashboard / admin.js pattern)
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
function showToast(message) {
    const toast = $('[data-abo-admin-settings-toast]');
    if (!toast) return;
    const text = $('[data-abo-admin-settings-toast-text]', toast);
    if (text) text.textContent = message;
    toast.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toast.hidden = true; }, 2200);
}

/* ──────────────────────────────────────────────────────────────
   Modal
   ────────────────────────────────────────────────────────────── */

let modalConfirmHandler = null;
let modalCancelHandler  = null;

function openModal({ title, body, confirmLabel = 'Confirm', danger = true, onConfirm, onCancel }) {
    const modal = $('[data-abo-admin-settings-modal]');
    if (!modal) return;
    const titleNode = $('#abo-admin-settings-modal-title', modal);
    const bodyNode  = $('[data-abo-admin-settings-modal-body]', modal);
    const confirm   = $('[data-abo-admin-settings-modal-confirm]', modal);
    if (titleNode) titleNode.textContent = title;
    if (bodyNode)  bodyNode.textContent  = body;
    if (confirm) {
        confirm.textContent = confirmLabel;
        confirm.classList.toggle('is-danger', danger);
    }
    modal.hidden = false;
    modalConfirmHandler = onConfirm || null;
    modalCancelHandler  = onCancel  || null;
    setTimeout(() => {
        const cancel = $('[data-abo-admin-settings-modal-close]', modal);
        if (cancel) cancel.focus();
    }, 0);
}

function closeModal() {
    const modal = $('[data-abo-admin-settings-modal]');
    if (!modal) return;
    modal.hidden = true;
    modalConfirmHandler = null;
    modalCancelHandler  = null;
}

function initModal() {
    const modal = $('[data-abo-admin-settings-modal]');
    if (!modal || modal.dataset.bound === '1') return;
    modal.dataset.bound = '1';

    $$('[data-abo-admin-settings-modal-close]', modal).forEach(btn => {
        btn.addEventListener('click', () => {
            const cancel = modalCancelHandler;
            closeModal();
            if (cancel) cancel();
        });
    });
    $('[data-abo-admin-settings-modal-confirm]', modal)
        ?.addEventListener('click', () => {
            const handler = modalConfirmHandler;
            closeModal();
            if (handler) handler();
        });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !modal.hidden) {
            const cancel = modalCancelHandler;
            closeModal();
            if (cancel) cancel();
        }
    });
}

/* ══════════════════════════════════════════════════════════════
   STATE
   ══════════════════════════════════════════════════════════════ */

const STATE = {
    store: { name: '', phone: '', whatsapp: '', email: '', address: '',
             social: { instagram: '', facebook: '', tiktok: '' },
             maintenance: false },
    orders: { minimum: 0, autoCancelHours: 24, guestCheckout: true,
              prefix: 'ABO-', nextNumber: 1043 },
    inventory: { lowStockThreshold: 5, hideOutOfStock: false },
    reasons: [],
    receipts: { showLogo: true, headerText: '', footerNote: '',
                fields: { orderNumber: true, date: true, customerPhone: true,
                          address: false, paymentMethod: true, shippingFee: true },
                paper: 'a4' },
    notifications: { newOrder: true, lowStock: true, returnRequest: true,
                     shipmentFailed: false, couponExpiring: false, browserSound: true },
    reasonSeq: 1,
};

const SNAPSHOT = {
    store: null,
    orders: null,
    inventory: null,
    reasons: null,
    receipts: null,
    notifications: null,
};

function snapshot() {
    SNAPSHOT.store         = JSON.parse(JSON.stringify(STATE.store));
    SNAPSHOT.orders        = JSON.parse(JSON.stringify(STATE.orders));
    SNAPSHOT.inventory     = JSON.parse(JSON.stringify(STATE.inventory));
    SNAPSHOT.reasons       = JSON.parse(JSON.stringify(STATE.reasons));
    SNAPSHOT.receipts      = JSON.parse(JSON.stringify(STATE.receipts));
    SNAPSHOT.notifications = JSON.parse(JSON.stringify(STATE.notifications));
}

function deepEqual(a, b) {
    return JSON.stringify(a) === JSON.stringify(b);
}

function isDirty() {
    return !deepEqual(STATE.store,         SNAPSHOT.store)
        || !deepEqual(STATE.orders,        SNAPSHOT.orders)
        || !deepEqual(STATE.inventory,     SNAPSHOT.inventory)
        || !deepEqual(STATE.reasons,       SNAPSHOT.reasons)
        || !deepEqual(STATE.receipts,      SNAPSHOT.receipts)
        || !deepEqual(STATE.notifications, SNAPSHOT.notifications);
}

/* ══════════════════════════════════════════════════════════════
   LOAD FROM DOM (data-* attributes emitted by the Blade view)
   ══════════════════════════════════════════════════════════════ */

function loadFromDom(root) {
    const ds = root.dataset;
    try {
        if (ds.storeInfo)      STATE.store         = { ...STATE.store,         ...JSON.parse(ds.storeInfo) };
        if (ds.orders)         STATE.orders        = { ...STATE.orders,        ...JSON.parse(ds.orders) };
        if (ds.inventory)      STATE.inventory     = { ...STATE.inventory,     ...JSON.parse(ds.inventory) };
        if (ds.receipts)       STATE.receipts      = { ...STATE.receipts,      ...JSON.parse(ds.receipts) };
        if (ds.notifications)  STATE.notifications = { ...STATE.notifications, ...JSON.parse(ds.notifications) };
        if (ds.reasons)        STATE.reasons       = JSON.parse(ds.reasons);
    } catch (err) {
        // Fall back to empty defaults if the JSON is malformed.
        console.warn('Settings: failed to parse data-* attributes', err);
    }
    // Make sure required nested objects exist.
    STATE.store.social = STATE.store.social || {};
    STATE.receipts.fields = STATE.receipts.fields || {};
}

/* ══════════════════════════════════════════════════════════════
   FIELDS — hydrate inputs/toggles, listen for changes, mark dirty
   ══════════════════════════════════════════════════════════════ */

function setFieldValue(el, value) {
    if (el.type === 'checkbox') {
        el.checked = !!value;
        el.setAttribute('aria-checked', el.checked ? 'true' : 'false');
    } else if (el.type === 'radio') {
        el.checked = el.value === String(value);
    } else {
        el.value = (value == null ? '' : value);
    }
}

function initFields(root) {
    $$('[data-abo-admin-settings-field]', root).forEach(el => {
        const path = el.dataset.aboAdminSettingsField;
        const value = getNested(STATE, path);

        // Hydrate from STATE.
        if (el.type === 'checkbox' || el.type === 'radio') {
            setFieldValue(el, value);
        } else if (!el.matches('[type="file"]')) {
            setFieldValue(el, value);
        }

        // Wire up change → update STATE → re-render dependents → mark dirty.
        const onChange = () => {
            let v;
            if (el.type === 'checkbox') {
                v = el.checked;
                el.setAttribute('aria-checked', v ? 'true' : 'false');
            } else if (el.type === 'radio') {
                if (!el.checked) return;
                v = el.value;
                // For radios, sync the sibling visual state.
                const group = el.name
                    ? $$(`input[name="${el.name}"]`)
                    : [];
                group.forEach(r => r.parentElement?.classList.toggle('is-active', r === el));
            } else if (el.type === 'number') {
                v = el.value === '' ? null : Number(el.value);
            } else {
                v = el.value;
            }

            setNested(STATE, path, v);
            updateDependents(path);
            markDirty();
        };

        el.addEventListener('input',  onChange);
        el.addEventListener('change', onChange);
    });

    // Required-empty visual: required text input shows a coral note when empty.
    $$('input[data-abo-admin-settings-field][required]', root).forEach(el => {
        const wrap = el.closest('.abo-admin-settings__field');
        const note = wrap ? $('[hidden] .abo-admin-settings__error', wrap) : null;
        const errNode = wrap?.querySelector('.abo-admin-settings__error') || null;
        el.addEventListener('blur', () => {
            if (!wrap) return;
            const empty = !el.value.trim();
            wrap.classList.toggle('is-empty', empty);
            if (errNode) errNode.hidden = !empty;
        });
    });
}

/* When some fields change, mirror them into a visible dependent */
function updateDependents(path) {
    if (path === 'orders.prefix') {
        const preview = $('[data-abo-admin-settings-preview="orders.nextNumber"]');
        if (preview) preview.textContent = `${STATE.orders.prefix}${STATE.orders.nextNumber}`;
    }
    if (path === 'receipts.headerText') {
        const node = $('[data-abo-admin-settings-receipt-store]');
        if (node) node.textContent = STATE.receipts.headerText || '';
    }
    if (path === 'receipts.footerNote') {
        const node = $('[data-abo-admin-settings-receipt-footer]');
        if (node) node.textContent = STATE.receipts.footerNote || '';
    }
    if (path === 'receipts.showLogo') {
        const node = $('[data-abo-admin-settings-receipt-logo]');
        if (node) node.hidden = !STATE.receipts.showLogo;
    }
    if (path === 'receipts.paper') {
        const preview = $('[data-abo-admin-settings-receipt-preview] .abo-admin-settings__receipt-paper');
        if (preview) preview.classList.toggle('abo-admin-settings__receipt-paper--thermal', STATE.receipts.paper === 'thermal');
        // Sync radio visual state.
        $$('.abo-admin-settings__paper-option').forEach(opt => {
            const input = $('input', opt);
            if (!input) return;
            opt.classList.toggle('is-active', input.checked);
        });
    }
    if (path && path.startsWith('receipts.fields.')) {
        const key = path.split('.').pop();
        const node = $(`[data-abo-admin-settings-receipt-row="${key}"]`);
        if (node) node.hidden = !getNested(STATE.receipts.fields, key);
    }
    if (path === 'store.maintenance') {
        const banner = $('[data-abo-admin-settings-banner="maintenance"]');
        if (banner) banner.hidden = !STATE.store.maintenance;
    }
}

/* ══════════════════════════════════════════════════════════════
   TABS — hash-driven section switching + sub-nav state
   ══════════════════════════════════════════════════════════════ */

const TAB_IDS = ['store-info', 'orders', 'inventory', 'cancellation', 'receipts', 'notifications'];

function switchTab(id, opts = {}) {
    if (!TAB_IDS.includes(id)) id = 'store-info';

    // Toggle panels.
    $$('.abo-admin-settings__panel').forEach(p => {
        const isActive = p.dataset.aboAdminSettingsPanel === id;
        p.hidden = !isActive;
        p.classList.toggle('is-active', isActive);
    });

    // Toggle tabs.
    $$('.abo-admin-settings__nav-item').forEach(t => {
        const isActive = t.dataset.aboAdminSettingsTab === id;
        t.classList.toggle('is-active', isActive);
        t.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });

    if (!opts.skipScroll && opts.fromHash !== true) {
        const panel = $(`[data-abo-admin-settings-panel="${id}"]`);
        if (panel) panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else if (opts.fromHash === true) {
        // hash navigation: just make sure the heading is visible
        // (browser already scrolled to it).
    }
}

function initTabs() {
    $$('.abo-admin-settings__nav-item').forEach(t => {
        t.addEventListener('click', (e) => {
            e.preventDefault();
            const id = t.dataset.aboAdminSettingsTab;
            if (id) {
                history.replaceState(null, '', `#${id}`);
                switchTab(id);
            }
        });
    });

    window.addEventListener('hashchange', () => {
        const id = (window.location.hash || '').replace(/^#/, '');
        if (TAB_IDS.includes(id)) switchTab(id, { fromHash: true });
    });

    // Initial tab from hash, else default to store-info.
    const initial = (window.location.hash || '').replace(/^#/, '');
    switchTab(TAB_IDS.includes(initial) ? initial : 'store-info', { fromHash: true });
}

/* ══════════════════════════════════════════════════════════════
   FILE INPUTS — local preview via URL.createObjectURL
   ══════════════════════════════════════════════════════════════ */

function initFileInputs(root) {
    $$('[data-abo-admin-settings-file]', root).forEach(input => {
        input.addEventListener('change', () => {
            const key  = input.dataset.aboAdminSettingsFile;
            const file = input.files && input.files[0];
            const preview  = $(`[data-abo-admin-settings-preview="${key}"]`);
            const filename = $(`[data-abo-admin-settings-filename="${key}"]`);
            if (!preview || !filename) return;

            // Clear any previous object URL.
            if (preview.dataset.objectUrl) {
                URL.revokeObjectURL(preview.dataset.objectUrl);
                preview.dataset.objectUrl = '';
            }

            if (!file) {
                preview.style.backgroundImage = '';
                preview.textContent = preview.dataset.fallback || preview.textContent;
                filename.textContent = 'No file selected';
                markDirty();
                return;
            }

            const url = URL.createObjectURL(file);
            preview.dataset.objectUrl = url;
            preview.style.backgroundImage = `url("${url}")`;
            preview.textContent = '';
            // Add an <img> for accessibility / fallback (object-fit: contain in CSS).
            if (!preview.querySelector('img')) {
                const img = new Image();
                img.alt = file.name;
                img.src = url;
                preview.appendChild(img);
            } else {
                $('img', preview).src = url;
            }
            filename.textContent = file.name;
            markDirty();
        });
    });
}

/* ══════════════════════════════════════════════════════════════
   CANCELLATION REASONS
   ══════════════════════════════════════════════════════════════ */

function renderReasons(root) {
    const list = $('[data-abo-admin-settings-reasons]', root);
    if (!list) return;
    list.innerHTML = STATE.reasons.map(r => `
        <li class="abo-admin-settings__reason"
            draggable="true"
            data-abo-admin-settings-reason-id="${escapeHTML(r.id)}">
            <span class="abo-admin-settings__reason-drag" aria-label="Drag to reorder" role="button" tabindex="0">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <circle cx="9" cy="6"  r="1.4" fill="currentColor"/>
                    <circle cx="9" cy="12" r="1.4" fill="currentColor"/>
                    <circle cx="9" cy="18" r="1.4" fill="currentColor"/>
                    <circle cx="15" cy="6"  r="1.4" fill="currentColor"/>
                    <circle cx="15" cy="12" r="1.4" fill="currentColor"/>
                    <circle cx="15" cy="18" r="1.4" fill="currentColor"/>
                </svg>
            </span>
            <input class="abo-admin-settings__reason-input"
                   type="text"
                   autocomplete="off"
                   value="${escapeHTML(r.en)}"
                   aria-label="English label"
                   data-abo-admin-settings-reason-field="en">
            <input class="abo-admin-settings__reason-input abo-admin-settings__reason-input--ar"
                   type="text"
                   dir="rtl"
                   autocomplete="off"
                   value="${escapeHTML(r.ar)}"
                   aria-label="Arabic label"
                   data-abo-admin-settings-reason-field="ar">
            <label class="abo-admin-settings__toggle" aria-label="Toggle reason visibility">
                <input type="checkbox"
                       role="switch"
                       aria-checked="${r.active ? 'true' : 'false'}"
                       ${r.active ? 'checked' : ''}
                       data-abo-admin-settings-reason-field="active">
                <span class="abo-admin-settings__toggle-track" aria-hidden="true"></span>
                <span class="abo-admin-settings__toggle-thumb" aria-hidden="true"></span>
            </label>
            <div class="abo-admin-settings__reason-actions">
                <button type="button"
                        class="abo-admin-settings__reason-action abo-admin-settings__reason-action--danger"
                        aria-label="Delete reason"
                        data-abo-admin-settings-reason-action="delete">×</button>
            </div>
        </li>
    `).join('');
}

function findReasonRow(root, id) {
    return $(`[data-abo-admin-settings-reason-id="${id}"]`, root);
}

function initReasons(root) {
    renderReasons(root);
    const list = $('[data-abo-admin-settings-reasons]', root);
    if (!list) return;

    // Delegated listeners on inputs inside the list.
    list.addEventListener('input', (e) => {
        const row = e.target.closest('[data-abo-admin-settings-reason-id]');
        if (!row) return;
        const id = row.dataset.aboAdminSettingsReasonId;
        const reason = STATE.reasons.find(r => r.id === id);
        if (!reason) return;
        const field = e.target.dataset.aboAdminSettingsReasonField;
        if (field === 'en' || field === 'ar' || field === 'active') {
            reason[field] = field === 'active' ? e.target.checked : e.target.value;
            markDirty();
        }
    });

    list.addEventListener('change', (e) => {
        const row = e.target.closest('[data-abo-admin-settings-reason-id]');
        if (!row) return;
        const id = row.dataset.aboAdminSettingsReasonId;
        const reason = STATE.reasons.find(r => r.id === id);
        if (!reason) return;
        const field = e.target.dataset.aboAdminSettingsReasonField;
        if (field === 'active') {
            reason.active = e.target.checked;
            e.target.setAttribute('aria-checked', e.target.checked ? 'true' : 'false');
            markDirty();
        }
    });

    list.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-abo-admin-settings-reason-action]');
        if (!btn) return;
        const row = btn.closest('[data-abo-admin-settings-reason-id]');
        if (!row) return;
        const id = row.dataset.aboAdminSettingsReasonId;
        const reason = STATE.reasons.find(r => r.id === id);
        if (!reason) return;

        if (btn.dataset.aboAdminSettingsReasonAction === 'delete') {
            openModal({
                title: 'DELETE REASON',
                body: `“${reason.en}” will be removed from the cancellation list. This action cannot be undone in this demo.`,
                confirmLabel: 'DELETE',
                danger: true,
                onConfirm: () => {
                    STATE.reasons = STATE.reasons.filter(r => r.id !== id);
                    renderReasons(root);
                    markDirty();
                },
                onCancel: () => { /* no-op */ },
            });
        }
    });

    // Drag-to-reorder.
    let draggingId = null;
    list.addEventListener('dragstart', (e) => {
        const row = e.target.closest('[data-abo-admin-settings-reason-id]');
        if (!row) return;
        draggingId = row.dataset.aboAdminSettingsReasonId;
        row.classList.add('is-dragging');
        try { e.dataTransfer.effectAllowed = 'move'; } catch { /* some browsers */ }
        e.dataTransfer.setData('text/plain', draggingId);
    });
    list.addEventListener('dragover', (e) => {
        const row = e.target.closest('[data-abo-admin-settings-reason-id]');
        if (!row || row.dataset.aboAdminSettingsReasonId === draggingId) return;
        e.preventDefault();
        $$('.abo-admin-settings__reason', list).forEach(r =>
            r.classList.toggle('is-drop-target', r === row));
    });
    list.addEventListener('dragleave', () => {
        $$('.abo-admin-settings__reason.is-drop-target', list)
            .forEach(r => r.classList.remove('is-drop-target'));
    });
    list.addEventListener('drop', (e) => {
        e.preventDefault();
        const target = e.target.closest('[data-abo-admin-settings-reason-id]');
        if (!target || !draggingId) return;
        const targetId = target.dataset.aboAdminSettingsReasonId;
        if (targetId === draggingId) return;
        const from = STATE.reasons.findIndex(r => r.id === draggingId);
        const to   = STATE.reasons.findIndex(r => r.id === targetId);
        if (from < 0 || to < 0) return;
        const [moved] = STATE.reasons.splice(from, 1);
        STATE.reasons.splice(to, 0, moved);
        renderReasons(root);
        markDirty();
    });
    list.addEventListener('dragend', () => {
        draggingId = null;
        $$('.abo-admin-settings__reason', list).forEach(r =>
            r.classList.remove('is-dragging', 'is-drop-target'));
    });

    // Keyboard reorder via the drag handle.
    list.addEventListener('keydown', (e) => {
        if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
        const handle = e.target.closest('.abo-admin-settings__reason-drag');
        if (!handle) return;
        const row = handle.closest('[data-abo-admin-settings-reason-id]');
        if (!row) return;
        e.preventDefault();
        const id = row.dataset.aboAdminSettingsReasonId;
        const idx = STATE.reasons.findIndex(r => r.id === id);
        if (idx < 0) return;
        const target = e.key === 'ArrowUp' ? idx - 1 : idx + 1;
        if (target < 0 || target >= STATE.reasons.length) return;
        const [moved] = STATE.reasons.splice(idx, 1);
        STATE.reasons.splice(target, 0, moved);
        renderReasons(root);
        markDirty();
    });

    // "+ Add reason" button.
    $('[data-abo-admin-settings-action="add-reason"]', root)
        ?.addEventListener('click', () => {
            STATE.reasonSeq += 1;
            STATE.reasons.push({
                id: `r-${Date.now().toString(36)}-${STATE.reasonSeq}`,
                en: 'New reason',
                ar: 'سبب جديد',
                active: true,
            });
            renderReasons(root);
            markDirty();
            // Focus the last reason's English input.
            requestAnimationFrame(() => {
                const last = $$('[data-abo-admin-settings-reason-id]', list).slice(-1)[0];
                const en = last && $('[data-abo-admin-settings-reason-field="en"]', last);
                en?.focus();
                en?.select();
            });
        });
}

/* ══════════════════════════════════════════════════════════════
   SAVE BAR — visible whenever the form is dirty
   ══════════════════════════════════════════════════════════════ */

function markDirty() {
    const bar = $('[data-abo-admin-settings-savebar]');
    if (!bar) return;
    const dirty = isDirty();
    bar.hidden = !dirty;
    const meta = $('.abo-admin-settings__nav-meta');
    if (meta) meta.classList.toggle('is-dirty', dirty);
    const status = $('[data-abo-admin-settings-status]');
    if (status) status.textContent = dirty ? 'Unsaved changes' : 'Everything is up to date';
}

function initSaveBar() {
    $('[data-abo-admin-settings-action="save"]')?.addEventListener('click', () => {
        if (!isDirty()) return;
        // Visual only: snapshots become the new "current" state.
        snapshot();
        markDirty();
        showToast('Saved (demo)');
    });

    $('[data-abo-admin-settings-action="discard"]')?.addEventListener('click', () => {
        if (!isDirty()) return;
        // Restore STATE from snapshot, then re-hydrate the DOM.
        STATE.store         = JSON.parse(JSON.stringify(SNAPSHOT.store));
        STATE.orders        = JSON.parse(JSON.stringify(SNAPSHOT.orders));
        STATE.inventory     = JSON.parse(JSON.stringify(SNAPSHOT.inventory));
        STATE.reasons       = JSON.parse(JSON.stringify(SNAPSHOT.reasons));
        STATE.receipts      = JSON.parse(JSON.stringify(SNAPSHOT.receipts));
        STATE.notifications = JSON.parse(JSON.stringify(SNAPSHOT.notifications));
        const root = $('[data-abo-admin-settings-root]');
        if (!root) return;
        initFields(root);
        initReasons(root);
        initReceiptPreview(root);
        // Re-sync all derived UI bits.
        ['orders.prefix','receipts.headerText','receipts.footerNote',
         'receipts.showLogo','receipts.paper','store.maintenance']
          .forEach(updateDependents);
        Object.keys(STATE.receipts.fields)
              .forEach(k => updateDependents(`receipts.fields.${k}`));
        markDirty();
    });

    $('[data-abo-admin-settings-action="restore"]')?.addEventListener('click', () => {
        openModal({
            title: 'RESTORE DEFAULTS',
            body: 'Every setting in this page will be reset to the demo defaults shown when the page first loaded. This action cannot be undone in this demo.',
            confirmLabel: 'RESTORE',
            danger: false,
            onConfirm: () => {
                // Reload the page (simplest, reliable way to fully reset
                // every hydrated input, including any chosen files).
                window.location.reload();
            },
        });
    });
}

/* ══════════════════════════════════════════════════════════════
   MAINTENANCE CONFIRM
   ══════════════════════════════════════════════════════════════ */

function initMaintenanceConfirm() {
    const toggle = $('[data-abo-admin-settings-field="store.maintenance"]');
    if (!toggle) return;
    toggle.addEventListener('change', () => {
        if (toggle.checked) {
            openModal({
                title: 'TURN ON MAINTENANCE',
                body: 'The storefront will be hidden from customers and a one-line maintenance page will be shown instead. Orders keep coming in through the admin. Continue?',
                confirmLabel: 'TURN ON',
                danger: true,
                onConfirm: () => {
                    STATE.store.maintenance = true;
                    toggle.checked = true;
                    toggle.setAttribute('aria-checked', 'true');
                    updateDependents('store.maintenance');
                    markDirty();
                },
                onCancel: () => {
                    toggle.checked = false;
                    toggle.setAttribute('aria-checked', 'false');
                    STATE.store.maintenance = false;
                    updateDependents('store.maintenance');
                    markDirty();
                },
            });
        }
        // When switching OFF, no confirm is needed (just mark dirty).
    });
}

/* ══════════════════════════════════════════════════════════════
   RECEIPT PREVIEW
   ══════════════════════════════════════════════════════════════ */

function initReceiptPreview(root) {
    // Initial state.
    updateDependents('receipts.headerText');
    updateDependents('receipts.footerNote');
    updateDependents('receipts.showLogo');
    updateDependents('receipts.paper');
    Object.keys(STATE.receipts.fields)
          .forEach(k => updateDependents(`receipts.fields.${k}`));
}

/* ══════════════════════════════════════════════════════════════
   BOOT
   ══════════════════════════════════════════════════════════════ */

function boot() {
    const root = $('[data-abo-admin-settings-root]');
    if (!root) return;

    loadFromDom(root);
    initFields(root);
    initTabs();
    initFileInputs(root);
    initReasons(root);
    initReceiptPreview(root);
    initMaintenanceConfirm();
    initModal();
    initSaveBar();

    // Hide the skeleton + reveal the real shell.
    const skeleton = $('[data-abo-admin-settings-skeleton]');
    if (skeleton) skeleton.hidden = true;
    root.hidden = false;

    // Take the initial snapshot of STATE for dirty detection.
    snapshot();
    markDirty();
}

document.addEventListener('DOMContentLoaded', () => {
    initTodayDate();
    boot();
});
