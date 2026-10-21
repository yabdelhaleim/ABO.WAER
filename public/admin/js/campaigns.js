// ABO Admin · Campaigns — front-end for the marketing catalog.
//
// Wired to live Laravel APIs:
//   - window.CAMPAIGNS_DATA is hydrated by resources/views/admin/campaigns/
//     index.blade.php via Admin\CampaignController::index (see
//     App\Services\Admin\CampaignService::getAllCampaignsForAdmin).
//   - window.CAMPAIGNS_API exposes the named routes + CSRF token so
//     the slide-over can POST/PUT and the row menu can DELETE/duplicate
//     against /admin/campaigns/{campaign}.
//
// The script wires the campaign list page (table + filters +
// stats), the new / edit slide-over (4 steps with progress,
// live phone preview, audience reach, schedule, summary),
// the shared send-confirm + delete modal, the toast and the
// row menu. All other DOM (page header, sidebar, topbar) is
// rendered server-side by the Blade view.

/* eslint-disable no-console */

// ─── Live data ────────────────────────────────────────────────

const CAMPAIGNS = window.CAMPAIGNS_DATA ?? [];
const CAMPAIGNS_API = window.CAMPAIGNS_API ?? {
    store: '',
    update: '',
    destroy: '',
    duplicate: '',
    sendNow: '',
    audienceCount: '',
    csrf: '',
};

function apiUrl(template, id) {
    if (!template) return '';
    return template.replace('__ID__', encodeURIComponent(id));
}

// ─── Tiny helpers ───────────────────────────────────────────

var $ = window.$ || ((sel, root = document) => root.querySelector(sel));
var $$ = window.$$ || ((sel, root = document) => Array.from(root.querySelectorAll(sel)));

const TYPE_LABEL = { push: 'Push', email: 'Email', sms: 'SMS' };
const STATUS_LABEL = {
    active: 'Active',
    scheduled: 'Scheduled',
    draft: 'Draft',
    ended: 'Ended',
};

function clamp(n, min, max) {
    return Math.max(min, Math.min(max, n));
}

function escapeHtml(str) {
    return String(str == null ? '' : str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function formatNumber(n) {
    if (n == null) return '—';
    if (n >= 1000) return (n / 1000).toFixed(n >= 10000 ? 0 : 1).replace(/\.0$/, '') + 'k';
    return n.toLocaleString('en-US');
}

function formatDate(dateStr) {
    if (!dateStr) return '—';
    const d = new Date(dateStr + 'T00:00:00');
    if (Number.isNaN(d.getTime())) return dateStr;
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

// ─── Toast ─────────────────────────────────────────────────────

let toastTimer = null;
function showToast(message) {
    const toast = $('[data-abo-admin-campaigns-toast]');
    if (!toast) return;
    const text = $('[data-abo-admin-campaigns-toast-text]', toast);
    if (text) text.textContent = message;
    toast.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toast.hidden = true; }, 2200);
}

// ─── Modal ─────────────────────────────────────────────────────

let modalConfirmHandler = null;
let modalCancelHandler = null;

function openModal({ title, body, confirmLabel = 'Confirm', danger = true, onConfirm, onCancel }) {
    const modal = $('[data-abo-admin-campaigns-modal]');
    if (!modal) return;
    const titleNode = $('#abo-admin-campaigns-modal-title', modal);
    const bodyNode = $('[data-abo-admin-campaigns-modal-body]', modal);
    const confirm = $('[data-abo-admin-campaigns-modal-confirm]', modal);
    if (titleNode) titleNode.textContent = title;
    if (bodyNode) bodyNode.textContent = body;
    if (confirm) {
        confirm.textContent = confirmLabel;
        confirm.classList.toggle('abo-admin-campaigns__modal-confirm--safe', !danger);
    }
    modal.hidden = false;
    modalConfirmHandler = onConfirm || null;
    modalCancelHandler = onCancel || null;
    setTimeout(() => {
        const cancel = $('[data-abo-admin-campaigns-modal-close]', modal);
        if (cancel) cancel.focus();
    }, 0);
}

function closeModal(result) {
    const modal = $('[data-abo-admin-campaigns-modal]');
    if (!modal) return;
    modal.hidden = true;
    if (result === 'confirm' && modalConfirmHandler) modalConfirmHandler();
    if (result === 'cancel' && modalCancelHandler) modalCancelHandler();
    modalConfirmHandler = null;
    modalCancelHandler = null;
}

function initModal() {
    const modal = $('[data-abo-admin-campaigns-modal]');
    if (!modal || modal.dataset.bound === '1') return;
    modal.dataset.bound = '1';
    $$('[data-abo-admin-campaigns-modal-close]', modal).forEach((btn) => {
        btn.addEventListener('click', () => closeModal('cancel'));
    });
    const confirm = $('[data-abo-admin-campaigns-modal-confirm]', modal);
    if (confirm) {
        confirm.addEventListener('click', () => closeModal('confirm'));
    }
    document.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape' && !modal.hidden) closeModal('cancel');
    });
}

// ─── Table render + filters ───────────────────────────────────

const filterState = {
    search: '',
    type: '',
    status: '',
    sort: 'newest',
    pausedIds: new Set(),
};

function applyFilters() {
    const q = filterState.search.trim().toLowerCase();
    const t = filterState.type;
    const s = filterState.status;

    let rows = CAMPAIGNS.filter((c) => {
        if (q && !('c-' + c.id + ' ' + c.preview).includes(q)
            && !c.preview.toLowerCase().includes(q)
            && !c.audience.toLowerCase().includes(q)) return false;
        if (t && c.type !== t) return false;
        if (s && c.status !== s) return false;
        return true;
    });

    if (filterState.sort === 'newest') {
        rows.sort((a, b) => {
            const ad = a.schedule ? a.schedule.date : '0000-00-00';
            const bd = b.schedule ? b.schedule.date : '0000-00-00';
            return bd.localeCompare(ad);
        });
    } else if (filterState.sort === 'best') {
        rows.sort((a, b) => (b.opened || 0) - (a.opened || 0));
    }
    return rows;
}

function rowHtml(c) {
    const status = filterState.pausedIds.has(c.id) ? 'paused' : c.status;
    const statusKey = status === 'paused' ? 'active' : status;
    const statusLabel = status === 'paused' ? 'Paused' : STATUS_LABEL[statusKey];
    const sent = formatNumber(c.sent);
    const openedPct = c.opened == null ? null : clamp(c.opened, 0, 100);
    const clickedPct = c.clicked == null ? null : clamp(c.clicked, 0, 100);

    return `
        <tr data-abo-admin-campaigns-row="${escapeHtml(c.id)}">
            <td>
                <div class="abo-admin-campaigns__name-cell">
                    <span class="abo-admin-campaigns__name">${escapeHtml(c.name || (c.preview && c.preview.split('.')[0]) || c.preview || '')}</span>
                    <span class="abo-admin-campaigns__preview" title="${escapeHtml(c.preview || '')}">${escapeHtml(c.preview || '')}</span>
                </div>
            </td>
            <td>
                <span class="abo-admin-pill abo-admin-campaigns__pill--type" data-type="${escapeHtml(c.type)}">
                    <span class="abo-admin-campaigns__pill-icon" aria-hidden="true"></span>
                    ${escapeHtml(TYPE_LABEL[c.type] || c.type)}
                </span>
            </td>
            <td>${escapeHtml(c.audience)}</td>
            <td>
                <span class="abo-admin-pill abo-admin-campaigns__pill--${escapeHtml(statusKey)}">${escapeHtml(statusLabel)}</span>
            </td>
            <td>
                <div class="abo-admin-campaigns__schedule-cell">
                    <span class="abo-admin-campaigns__schedule-date">${escapeHtml(formatDate(c.schedule && c.schedule.date))}</span>
                    <span class="abo-admin-campaigns__schedule-time">${escapeHtml(c.schedule ? c.schedule.time : '—')}</span>
                </div>
            </td>
            <td class="abo-admin-campaigns__th-num"><span class="abo-admin-campaigns__sent">${escapeHtml(sent)}</span></td>
            <td class="abo-admin-campaigns__th-bar">
                <div class="abo-admin-campaigns__metric" aria-label="${openedPct == null ? 'no data' : openedPct + '% opened'}">
                    <span class="abo-admin-campaigns__metric-bar" aria-hidden="true">
                        <span class="abo-admin-campaigns__metric-fill ${openedPct == null ? 'abo-admin-campaigns__metric-fill--muted' : ''}" style="--w: ${openedPct == null ? 0 : openedPct}%;"></span>
                    </span>
                    <span class="abo-admin-campaigns__metric-value">${openedPct == null ? '—' : openedPct + '%'}</span>
                </div>
            </td>
            <td class="abo-admin-campaigns__th-bar">
                <div class="abo-admin-campaigns__metric" aria-label="${clickedPct == null ? 'no data' : clickedPct + '% clicked'}">
                    <span class="abo-admin-campaigns__metric-bar" aria-hidden="true">
                        <span class="abo-admin-campaigns__metric-fill ${clickedPct == null ? 'abo-admin-campaigns__metric-fill--muted' : ''}" style="--w: ${clickedPct == null ? 0 : clickedPct}%;"></span>
                    </span>
                    <span class="abo-admin-campaigns__metric-value">${clickedPct == null ? '—' : clickedPct + '%'}</span>
                </div>
            </td>
            <td class="abo-admin-campaigns__th-actions">
                <div class="abo-admin-campaigns__row-menu" data-abo-admin-campaigns-row-menu>
                    <button type="button"
                            class="abo-admin-campaigns__row-menu-btn"
                            aria-label="Open row menu"
                            aria-haspopup="menu"
                            aria-expanded="false"
                            data-abo-admin-campaigns-row-menu-btn>
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <circle cx="5"  cy="12" r="1.6" fill="currentColor"/>
                            <circle cx="12" cy="12" r="1.6" fill="currentColor"/>
                            <circle cx="19" cy="12" r="1.6" fill="currentColor"/>
                        </svg>
                    </button>
                    <div class="abo-admin-campaigns__row-menu-pop" role="menu" hidden
                         data-abo-admin-campaigns-row-menu-pop>
                        <button type="button" role="menuitem" data-abo-admin-campaigns-row-action="edit">Edit</button>
                        <button type="button" role="menuitem" data-abo-admin-campaigns-row-action="duplicate">Duplicate</button>
                        ${status !== 'ended' ? `<button type="button" role="menuitem" data-abo-admin-campaigns-row-action="sendNow">Send now</button>` : ''}
                        <button type="button" role="menuitem" data-abo-admin-campaigns-row-action="pause">
                            ${status === 'active' ? 'Pause' : 'Resume'}
                        </button>
                        <button type="button" role="menuitem" data-abo-admin-campaigns-row-action="delete" class="is-danger">Delete</button>
                    </div>
                </div>
            </td>
        </tr>
    `;
}

function renderTable() {
    const tbody = $('[data-abo-admin-campaigns-tbody]');
    if (!tbody) return;
    const rows = applyFilters();
    const empty = $('[data-abo-admin-campaigns-state="empty"]');
    const noRes = $('[data-abo-admin-campaigns-state="noresults"]');

    tbody.innerHTML = rows.map(rowHtml).join('');

    if (CAMPAIGNS.length === 0) {
        empty && (empty.hidden = false);
        noRes && (noRes.hidden = true);
        tbody.parentElement && (tbody.parentElement.hidden = true);
    } else if (rows.length === 0) {
        empty && (empty.hidden = true);
        noRes && (noRes.hidden = false);
        tbody.parentElement && (tbody.parentElement.hidden = true);
    } else {
        empty && (empty.hidden = true);
        noRes && (noRes.hidden = true);
        tbody.parentElement && (tbody.parentElement.hidden = false);
    }

    bindRowMenus();
}

function bindRowMenus() {
    $$('[data-abo-admin-campaigns-row-menu]').forEach((wrap) => {
        const btn = $('[data-abo-admin-campaigns-row-menu-btn]', wrap);
        const pop = $('[data-abo-admin-campaigns-row-menu-pop]', wrap);
        if (!btn || !pop || pop.dataset.bound === '1') return;
        pop.dataset.bound = '1';

        btn.addEventListener('click', (ev) => {
            ev.stopPropagation();
            // Close any other open menus first
            $$('[data-abo-admin-campaigns-row-menu].is-open').forEach((other) => {
                if (other !== wrap) closeOtherMenu(other);
            });
            const isOpen = wrap.classList.toggle('is-open');
            pop.hidden = !isOpen;
            btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });

        $$('button', pop).forEach((actionBtn) => {
            actionBtn.addEventListener('click', (ev) => {
                ev.stopPropagation();
                const row = wrap.closest('tr');
                const id = row && row.dataset.aboAdminCampaignsRow;
                const action = actionBtn.dataset.aboAdminCampaignsRowAction;
                wrap.classList.remove('is-open');
                pop.hidden = true;
                btn.setAttribute('aria-expanded', 'false');
                handleRowAction(id, action);
            });
        });
    });
}

function closeOtherMenu(wrap) {
    wrap.classList.remove('is-open');
    const pop = $('[data-abo-admin-campaigns-row-menu-pop]', wrap);
    const btn = $('[data-abo-admin-campaigns-row-menu-btn]', wrap);
    if (pop) pop.hidden = true;
    if (btn) btn.setAttribute('aria-expanded', 'false');
}

function handleRowAction(id, action) {
    if (!id) return;
    if (action === 'edit') {
        openSlideover({ id });
    } else if (action === 'duplicate') {
        const c = CAMPAIGNS.find((x) => x.id === id);
        if (!c) return;
        openModal({
            title: 'DUPLICATE CAMPAIGN',
            body: 'This will create a new draft copy of "' + (c.preview || c.id) + '".',
            confirmLabel: 'DUPLICATE',
            danger: false,
            onConfirm: () => duplicateCampaign(id),
        });
    } else if (action === 'sendNow') {
        const c = CAMPAIGNS.find((x) => x.id === id);
        if (!c) return;
        openModal({
            title: 'SEND CAMPAIGN NOW',
            body: 'This queues "' + (c.preview || c.id) + '" for immediate delivery to its audience.',
            confirmLabel: 'SEND NOW',
            danger: false,
            onConfirm: () => sendCampaign(id),
        });
    } else if (action === 'pause') {
        const c = CAMPAIGNS.find((x) => x.id === id);
        if (!c) return;
        if (filterState.pausedIds.has(id)) {
            filterState.pausedIds.delete(id);
            showToast('Resumed');
        } else if (c.status === 'active') {
            filterState.pausedIds.add(id);
            showToast('Paused');
        } else {
            showToast('Not pausable');
            return;
        }
        renderTable();
    } else if (action === 'delete') {
        const c = CAMPAIGNS.find((x) => x.id === id);
        const label = c ? '"' + (c.preview || c.id) + '"' : 'this campaign';
        openModal({
            title: 'DELETE CAMPAIGN',
            body: 'Are you sure? This will permanently remove ' + label + '.',
            confirmLabel: 'DELETE',
            danger: true,
            onConfirm: () => deleteCampaign(id),
        });
    }
}

async function duplicateCampaign(id) {
    const url = apiUrl(CAMPAIGNS_API.duplicate, id);
    if (!url) return;
    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': CAMPAIGNS_API.csrf,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.success) {
            showToast(data.message || 'Could not duplicate.');
            return;
        }
        if (data.campaign) CAMPAIGNS.unshift(data.campaign);
        renderTable();
        showToast('Duplicated');
    } catch (err) {
        showToast('Network error');
    }
}

async function sendCampaign(id) {
    const url = apiUrl(CAMPAIGNS_API.sendNow, id);
    if (!url) return;
    try {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': CAMPAIGNS_API.csrf,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.success) {
            showToast(data.message || data.errors?.campaign?.[0] || 'Could not send.');
            return;
        }
        // The server has already flipped the campaign to 'active'; delivery
        // itself is the queue's job, so no reload is needed.
        const idx = CAMPAIGNS.findIndex((x) => x.id === id);
        if (idx >= 0 && data.campaign) CAMPAIGNS[idx] = data.campaign;
        renderTable();
        showToast('Dispatched to queue');
    } catch (err) {
        showToast('Network error');
    }
}

async function deleteCampaign(id) {
    const url = apiUrl(CAMPAIGNS_API.destroy, id);
    if (!url) return;
    try {
        const res = await fetch(url, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': CAMPAIGNS_API.csrf,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.success) {
            showToast(data.message || data.errors?.campaign?.[0] || 'Could not delete.');
            return;
        }
        const idx = CAMPAIGNS.findIndex((x) => x.id === id);
        if (idx >= 0) CAMPAIGNS.splice(idx, 1);
        filterState.pausedIds.delete(id);
        renderTable();
        showToast('Deleted');
    } catch (err) {
        showToast('Network error');
    }
}

async function saveCampaign(existingId) {
    const f = slideoverState.fields;
    // The audience cards are rendered from App\Enums\CampaignAudience, so the
    // radio value already is the stored segment — no mapping needed.
    const payload = {
        title: f.name || '',
        subject: f.title || f.name || '',
        preview: f.message || '',
        message: f.message || '',
        type: f.type,
        audience: f.audience || 'all',
    };
    if (f.when === 'later' && f.date) {
        const time = (f.time || '10:00') + ':00';
        payload.scheduled_for = f.date + ' ' + time;
    }

    const editing = Boolean(existingId);
    const url = editing ? apiUrl(CAMPAIGNS_API.update, existingId) : CAMPAIGNS_API.store;
    const method = editing ? 'PUT' : 'POST';

    if (!url) {
        showToast('API not configured.');
        return false;
    }

    try {
        const res = await fetch(url, {
            method,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': CAMPAIGNS_API.csrf,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
            body: JSON.stringify(payload),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.success) {
            const firstError = data.errors
                ? Object.values(data.errors).flat()[0]
                : null;
            showToast(firstError || data.message || 'Could not save.');
            return false;
        }
        if (editing) {
            const idx = CAMPAIGNS.findIndex((x) => x.id === existingId);
            if (idx >= 0) CAMPAIGNS[idx] = data.campaign;
        } else if (data.campaign) {
            CAMPAIGNS.unshift(data.campaign);
        }
        renderTable();
        showToast(editing ? 'Updated' : 'Created');
        return true;
    } catch (err) {
        showToast('Network error');
        return false;
    }
}

// ─── KPI / status filters ─────────────────────────────────────

function initStats() {
    $$('[data-abo-admin-campaigns-stat]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const key = btn.dataset.aboAdminCampaignsStat;
            const map = { active: 'active', scheduled: 'scheduled', sent: 'ended', open: '' };
            const target = map[key] ?? '';
            // Toggle off if already pressed
            const currentTab = $('[data-abo-admin-campaigns-status-tab="' + filterState.status + '"]');
            const targetTab = $('[data-abo-admin-campaigns-status-tab="' + target + '"]');
            if (filterState.status === target) {
                filterState.status = '';
                $$('[data-abo-admin-campaigns-stat]').forEach((b) => b.setAttribute('aria-pressed', 'false'));
                $$('[data-abo-admin-campaigns-status-tab]').forEach((t) => {
                    const on = t.dataset.aboAdminCampaignsStatusTab === '';
                    t.classList.toggle('is-active', on);
                    t.setAttribute('aria-selected', on ? 'true' : 'false');
                });
            } else {
                filterState.status = target;
                $$('[data-abo-admin-campaigns-stat]').forEach((b) => b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'));
                $$('[data-abo-admin-campaigns-status-tab]').forEach((t) => {
                    const on = t === targetTab;
                    t.classList.toggle('is-active', on);
                    t.setAttribute('aria-selected', on ? 'true' : 'false');
                });
            }
            renderTable();
        });
    });
}

function initFilterBar() {
    const search = $('[data-abo-admin-campaigns-search]');
    if (search) {
        search.addEventListener('input', () => {
            filterState.search = search.value || '';
            renderTable();
        });
    }
    const typeSel = $('[data-abo-admin-campaigns-filter="type"]');
    if (typeSel) {
        typeSel.addEventListener('change', () => {
            filterState.type = typeSel.value || '';
            renderTable();
        });
    }
    const sortSel = $('[data-abo-admin-campaigns-sort]');
    if (sortSel) {
        sortSel.addEventListener('change', () => {
            filterState.sort = sortSel.value || 'newest';
            renderTable();
        });
    }
    $$('[data-abo-admin-campaigns-status-tab]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const val = btn.dataset.aboAdminCampaignsStatusTab || '';
            filterState.status = val;
            $$('[data-abo-admin-campaigns-status-tab]').forEach((t) => {
                const on = t === btn;
                t.classList.toggle('is-active', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            // Sync KPI pressed state
            const reverse = { '': null, active: 'active', scheduled: 'scheduled', draft: null, ended: 'sent' };
            const statKey = reverse[val];
            $$('[data-abo-admin-campaigns-stat]').forEach((b) => {
                b.setAttribute('aria-pressed', b.dataset.aboAdminCampaignsStat === statKey ? 'true' : 'false');
            });
            renderTable();
        });
    });

    // Outside click closes any open row menu
    document.addEventListener('click', () => {
        $$('[data-abo-admin-campaigns-row-menu].is-open').forEach(closeOtherMenu);
    });
}

// ─── Slide-over ────────────────────────────────────────────────

const slideoverState = {
    step: 1,
    editingId: null,
    fields: {
        name: '',
        type: 'push',
        title: '',
        message: '',
        image: null,
        deeplink: '',
        coupon: '',
        audience: 'all',
        when: 'now',
        date: '',
        time: '10:00',
        repeat: 'none',
    },
};

function showPane(step) {
    $$('[data-abo-admin-campaigns-pane]').forEach((p) => {
        const on = String(p.dataset.aboAdminCampaignsPane) === String(step);
        p.hidden = !on;
        p.classList.toggle('is-active', on);
    });
    $$('[data-abo-admin-campaigns-progress] li').forEach((li) => {
        const num = Number(li.dataset.aboAdminCampaignsStep);
        li.classList.toggle('is-active', num === step);
        li.classList.toggle('is-done', num < step);
    });
    slideoverState.step = step;
    // Toggle footer buttons: BACK on step >1, NEXT on step <4, SEND on step 4.
    const back = $('[data-abo-admin-campaigns-back-btn]');
    const next = $('[data-abo-admin-campaigns-next-btn]');
    const send = $('[data-abo-admin-campaigns-send-btn]');
    if (back) back.hidden = step <= 1;
    if (next) next.hidden = step >= 4;
    if (send) send.hidden = step < 4;
    if (step === 4) renderSummary();
}

function openSlideover({ id } = {}) {
    const slideover = $('[data-abo-admin-campaigns-slideover]');
    const overlay = $('[data-abo-admin-campaigns-slideover-overlay]');
    if (!slideover || !overlay) return;
    slideoverState.editingId = id || null;
    if (id) {
        const c = CAMPAIGNS.find((x) => x.id === id);
        if (c) {
            slideoverState.fields = {
                name: c.name || (c.preview && c.preview.split('.')[0]) || c.preview || '',
                type: c.type,
                title: '',
                message: c.preview || '',
                image: null,
                deeplink: '',
                coupon: '',
                audience: c.audience_value || 'all',
                when: c.status === 'scheduled' ? 'later' : 'now',
                date: c.schedule ? c.schedule.date : '',
                time: c.schedule ? c.schedule.time : '10:00',
                repeat: 'none',
            };
            const titleEl = $('[data-abo-admin-campaigns-slideover-title]');
            if (titleEl) titleEl.textContent = 'EDIT CAMPAIGN';
        }
    } else {
        slideoverState.fields = {
            name: '',
            type: 'push',
            title: '',
            message: '',
            image: null,
            deeplink: '',
            coupon: '',
            audience: 'all',
            when: 'now',
            date: '',
            time: '10:00',
            repeat: 'none',
        };
        const titleEl = $('[data-abo-admin-campaigns-slideover-title]');
        if (titleEl) titleEl.textContent = 'Untitled campaign';
    }
    hydrateSlideoverFields();
    showPane(1);
    slideover.hidden = false;
    overlay.hidden = false;
    requestAnimationFrame(() => {
        slideover.classList.add('is-open');
    });
    setTimeout(() => {
        const first = $('[data-abo-admin-campaigns-name]');
        if (first) first.focus();
    }, 60);
}

function closeSlideover() {
    const slideover = $('[data-abo-admin-campaigns-slideover]');
    const overlay = $('[data-abo-admin-campaigns-slideover-overlay]');
    if (!slideover || !overlay) return;
    slideover.classList.remove('is-open');
    slideoverState.editingId = null;
    setTimeout(() => {
        slideover.hidden = true;
        overlay.hidden = true;
    }, 220);
}

function hydrateSlideoverFields() {
    const f = slideoverState.fields;
    const set = (sel, val) => {
        const el = $(sel);
        if (el) el.value = val;
    };
    set('[data-abo-admin-campaigns-field="name"]', f.name);
    set('[data-abo-admin-campaigns-field="type"]', f.type);
    set('[data-abo-admin-campaigns-field="title"]', f.title);
    set('[data-abo-admin-campaigns-field="message"]', f.message);
    set('[data-abo-admin-campaigns-field="deeplink"]', f.deeplink);
    set('[data-abo-admin-campaigns-field="coupon"]', f.coupon);
    set('[data-abo-admin-campaigns-field="date"]', f.date);
    set('[data-abo-admin-campaigns-field="time"]', f.time);
    set('[data-abo-admin-campaigns-field="repeat"]', f.repeat);

    // Lang switch
    $$('[data-abo-admin-campaigns-lang]').forEach((b) => {
        const on = b.dataset.aboAdminCampaignsLang === 'en';
        b.classList.toggle('is-active', on);
        b.setAttribute('aria-selected', on ? 'true' : 'false');
    });

    // Audience
    $$('[data-abo-admin-campaigns-audience] input[type="radio"]').forEach((r) => {
        r.checked = r.value === f.audience;
    });
    updateReach();

    // When
    $$('[data-abo-admin-campaigns-when] input[type="radio"]').forEach((r) => {
        r.checked = r.value === f.when;
        const label = r.closest('label');
        if (label) label.classList.toggle('is-active', r.checked);
    });
    const schedFields = $('[data-abo-admin-campaigns-schedule-fields]');
    if (schedFields) schedFields.hidden = f.when !== 'later';

    // Character counter + phone preview
    const counter = $('[data-abo-admin-campaigns-counter]');
    if (counter) counter.textContent = String((f.message || '').length);
    const titlePrev = $('[data-abo-admin-campaigns-phone-title]');
    const bodyPrev = $('[data-abo-admin-campaigns-phone-body]');
    if (titlePrev) titlePrev.textContent = f.title || (f.name || 'Title');
    if (bodyPrev) bodyPrev.textContent = f.message || 'Your message will appear here.';

    // Image preview (only persisted via object URL, so reset UI here)
    const preview = $('[data-abo-admin-campaigns-image-preview]');
    const thumb = $('[data-abo-admin-campaigns-image-thumb]');
    const fileInput = $('[data-abo-admin-campaigns-field="image"]');
    if (preview) preview.hidden = !f.image;
    if (thumb && f.image) thumb.src = f.image;
    if (fileInput) fileInput.value = '';

    // Phone thumb in notification preview
    const phoneThumb = $('[data-abo-admin-campaigns-phone-thumb]');
    if (phoneThumb) {
        if (f.image) {
            phoneThumb.hidden = false;
            phoneThumb.style.backgroundImage = `url("${f.image}")`;
        } else {
            phoneThumb.hidden = true;
            phoneThumb.style.backgroundImage = '';
        }
    }

    // Send button label
    const sendLabel = $('[data-abo-admin-campaigns-send-label]');
    if (sendLabel) sendLabel.textContent = f.when === 'later' ? 'SCHEDULE' : 'SEND NOW';
}

// Segment sizes are fetched once per audience and reused — the picker only
// ever shows one at a time, and a re-open of the slide-over should not re-hit
// the endpoint for numbers the server already rendered into the cards.
const reachCache = {};

async function fetchAudienceCount(audience) {
    if (!CAMPAIGNS_API.audienceCount) return null;
    if (Object.prototype.hasOwnProperty.call(reachCache, audience)) {
        return reachCache[audience];
    }
    const url = CAMPAIGNS_API.audienceCount.replace('__AUDIENCE__', encodeURIComponent(audience));
    try {
        const res = await fetch(url, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data.success || typeof data.count !== 'number') return null;
        reachCache[audience] = data.count;
        return data.count;
    } catch (err) {
        return null;
    }
}

function formatReach(n) {
    return Number(n).toLocaleString('en-US');
}

function updateReach() {
    const sel = $(`[data-abo-admin-campaigns-audience] input[type="radio"]:checked`);
    const card = sel ? sel.closest('.abo-admin-campaigns__radio-card') : null;
    const reachEl = card && card.querySelector('[data-reach]');
    const target = $('[data-abo-admin-campaigns-reach]');
    if (!target) return;

    // Paint the server-rendered number straight away so the summary never
    // flashes empty, then refresh it — the page was rendered at load and
    // orders may have landed since.
    const audience = sel ? sel.value : null;
    if (reachEl) target.textContent = reachEl.dataset.reach;
    if (!audience) return;

    fetchAudienceCount(audience).then((count) => {
        if (count === null) return;
        const live = formatReach(count);
        target.textContent = live;
        if (reachEl) {
            reachEl.dataset.reach = live;
            reachEl.textContent = `~${live} customers`;
        }
        renderSummary();
    });
}

function renderSummary() {
    const f = slideoverState.fields;
    const map = {
        name: f.name || '—',
        type: TYPE_LABEL[f.type] || f.type,
        title: f.title || '—',
        message: f.message || '—',
        deeplink: f.deeplink || 'Shop home',
        coupon: f.coupon || 'None',
        audience: (function () {
            const sel = $('[data-abo-admin-campaigns-audience] input[type="radio"]:checked');
            if (!sel) return '—';
            const card = sel.closest('.abo-admin-campaigns__radio-card');
            const title = card && card.querySelector('.abo-admin-campaigns__radio-card-title');
            return title ? title.textContent : '—';
        })(),
        reach: $('[data-abo-admin-campaigns-reach]') ? $('[data-abo-admin-campaigns-reach]').textContent : '—',
        when: f.when === 'now' ? 'Send now' : `Schedule · ${f.date || '—'} ${f.time || ''}`.trim(),
    };
    $$('[data-abo-admin-campaigns-sum]').forEach((node) => {
        const key = node.dataset.aboAdminCampaignsSum;
        if (map[key] != null) node.textContent = map[key];
    });
}

function initSlideover() {
    const slideover = $('[data-abo-admin-campaigns-slideover]');
    const overlay = $('[data-abo-admin-campaigns-slideover-overlay]');
    if (!slideover || slideover.dataset.bound === '1') return;
    slideover.dataset.bound = '1';

    const close = $('[data-abo-admin-campaigns-slideover-close]');
    if (close) close.addEventListener('click', closeSlideover);
    if (overlay) overlay.addEventListener('click', closeSlideover);

    const back = $('[data-abo-admin-campaigns-slideover-back]');
    if (back) {
        back.addEventListener('click', () => {
            if (slideoverState.step > 1) showPane(slideoverState.step - 1);
        });
    }
    const next = $('[data-abo-admin-campaigns-slideover-next]');
    if (next) {
        next.addEventListener('click', () => {
            if (slideoverState.step < 4) showPane(slideoverState.step + 1);
        });
    }

    // Field bindings
    $$('[data-abo-admin-campaigns-field]').forEach((input) => {
        const key = input.dataset.aboAdminCampaignsField;
        if (input.type === 'file') {
            input.addEventListener('change', () => {
                const file = input.files && input.files[0];
                if (!file) return;
                if (slideoverState.fields.image) URL.revokeObjectURL(slideoverState.fields.image);
                slideoverState.fields.image = URL.createObjectURL(file);
                hydrateSlideoverFields();
            });
            return;
        }
        const ev = input.tagName === 'SELECT' ? 'change' : 'input';
        input.addEventListener(ev, () => {
            slideoverState.fields[key] = input.value;
            if (key === 'message') {
                const counter = $('[data-abo-admin-campaigns-counter]');
                if (counter) counter.textContent = String(input.value.length);
                const bodyPrev = $('[data-abo-admin-campaigns-phone-body]');
                if (bodyPrev) bodyPrev.textContent = input.value || 'Your message will appear here.';
            }
            if (key === 'title' || key === 'name') {
                const titlePrev = $('[data-abo-admin-campaigns-phone-title]');
                if (titlePrev) {
                    titlePrev.textContent = slideoverState.fields.title || slideoverState.fields.name || 'Title';
                }
                const tEl = $('[data-abo-admin-campaigns-slideover-title]');
                if (tEl && key === 'name') {
                    tEl.textContent = input.value || 'Untitled campaign';
                }
            }
            if (key === 'when') {
                const schedFields = $('[data-abo-admin-campaigns-schedule-fields]');
                if (schedFields) schedFields.hidden = input.value !== 'later';
                const sendLabel = $('[data-abo-admin-campaigns-send-label]');
                if (sendLabel) sendLabel.textContent = input.value === 'later' ? 'SCHEDULE' : 'SEND NOW';
            }
        });
    });

    // Image remove
    const imgRemove = $('[data-abo-admin-campaigns-image-remove]');
    if (imgRemove) {
        imgRemove.addEventListener('click', () => {
            if (slideoverState.fields.image) URL.revokeObjectURL(slideoverState.fields.image);
            slideoverState.fields.image = null;
            hydrateSlideoverFields();
        });
    }

    // Lang switch
    $$('[data-abo-admin-campaigns-lang]').forEach((btn) => {
        btn.addEventListener('click', () => {
            $$('[data-abo-admin-campaigns-lang]').forEach((b) => {
                const on = b === btn;
                b.classList.toggle('is-active', on);
                b.setAttribute('aria-selected', on ? 'true' : 'false');
            });
        });
    });

    // Audience
    $$('[data-abo-admin-campaigns-audience] input[type="radio"]').forEach((r) => {
        r.addEventListener('change', () => {
            if (r.checked) {
                slideoverState.fields.audience = r.value;
                updateReach();
            }
        });
    });

    // When (inline radio)
    $$('[data-abo-admin-campaigns-when] input[type="radio"]').forEach((r) => {
        r.addEventListener('change', () => {
            if (r.checked) {
                slideoverState.fields.when = r.value;
                $$('[data-abo-admin-campaigns-when] label').forEach((l) => l.classList.toggle('is-active', l.contains(r)));
                const schedFields = $('[data-abo-admin-campaigns-schedule-fields]');
                if (schedFields) schedFields.hidden = r.value !== 'later';
                const sendLabel = $('[data-abo-admin-campaigns-send-label]');
                if (sendLabel) sendLabel.textContent = r.value === 'later' ? 'SCHEDULE' : 'SEND NOW';
            }
        });
    });

    // Draft + Send
    const draftBtn = $('[data-abo-admin-campaigns-slideover-draft]');
    if (draftBtn) draftBtn.addEventListener('click', () => {
        const existingId = slideoverState.editingId;
        slideoverState.fields.when = 'now';
        saveCampaign(existingId).then((ok) => { if (ok) closeSlideover(); });
    });
    const sendBtn = $('[data-abo-admin-campaigns-slideover-send]');
    if (sendBtn) {
        sendBtn.addEventListener('click', () => {
            // Validate minimally
            if (!slideoverState.fields.name) {
                showPane(1);
                const nameInput = $('[data-abo-admin-campaigns-name]');
                if (nameInput) nameInput.focus();
                return;
            }
            const reach = ($('[data-abo-admin-campaigns-reach]') || {}).textContent || '—';
            const when = slideoverState.fields.when === 'later' ? 'Schedule' : 'Send';
            const existingId = slideoverState.editingId;
            openModal({
                title: `${when.toUpperCase()} CAMPAIGN`,
                body: `This will notify ${reach} customers.`,
                confirmLabel: when.toUpperCase(),
                danger: false,
                onConfirm: () => {
                    saveCampaign(existingId).then((ok) => { if (ok) closeSlideover(); });
                },
                onCancel: () => {},
            });
        });
    }

    // Esc closes slide-over (only if open and modal not visible)
    document.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape' && !slideover.hidden && !overlay.hidden) {
            // Don't override modal Esc
            const modal = $('[data-abo-admin-campaigns-modal]');
            if (modal && !modal.hidden) return;
            closeSlideover();
        }
    });

    // Progress click jumps to that step (only backwards or current)
    $$('[data-abo-admin-campaigns-progress] li').forEach((li) => {
        li.addEventListener('click', () => {
            const num = Number(li.dataset.aboAdminCampaignsStep);
            if (num <= slideoverState.step) showPane(num);
        });
    });
}

function initAddButtons() {
    $$('[data-abo-admin-campaigns-add]').forEach((btn) => {
        btn.addEventListener('click', () => openSlideover({}));
    });
}

// ─── Date label ────────────────────────────────────────────────

function setTodayDate() {
    const target = $('#abo-admin-campaigns-today-date');
    if (!target) return;
    const now = new Date();
    const fmt = new Intl.DateTimeFormat('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    target.textContent = fmt.format(now);
}

// ─── Boot ─────────────────────────────────────────────────────

function init() {
    initModal();
    initStats();
    initFilterBar();
    initSlideover();
    initAddButtons();
    renderTable();
    setTodayDate();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}