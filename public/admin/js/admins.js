// ABO Admin · Admins — the back-office team, wired to the database.
//
// The page is fed by the server: the list of accounts, the role each
// one holds, the map of what each role reaches, and the trail of what
// the team has changed all arrive as JSON in the page itself.
//
// Every action here is a real request against a real endpoint:
//
//   add an admin  → POST   /admin/admins
//   edit an admin → PUT    /admin/admins/{admin}
//   suspend       → PATCH  /admin/admins/{admin}/toggle
//   remove        → DELETE /admin/admins/{admin}
//   reset link    → POST   /admin/password/email
//
// The server has the last word on every one of them: a role that may
// not reach a module, an account that may not be removed, an owner who
// may not be the last one — those are refused there, and the answer
// comes back as a message this script shows.

/* eslint-disable no-console */

(function () {
    'use strict';

    // ─── Shortcuts ────────────────────────────────────────────

    const $  = (sel, root) => (root || document).querySelector(sel);
    const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

    const root = document.querySelector('.abo-admin-page') || document.body;

    // ─── Data ─────────────────────────────────────────────────

    function readJSON(id, fallback) {
        const el = document.getElementById(id);
        try { return JSON.parse(el ? el.textContent : '') || fallback; }
        catch (err) { console.error('[admins] failed to parse', id, err); return fallback; }
    }

    const ROLES     = readJSON('abo-admin-admins-data-roles',     {});
    const STATUSES  = readJSON('abo-admin-admins-data-statuses',  {});
    const ADMINS    = readJSON('abo-admin-admins-data-admins',    []);
    const ACTIVITY  = readJSON('abo-admin-admins-data-activity',  []);
    const MODULES   = readJSON('abo-admin-admins-data-modules',   {});
    const MATRIX    = readJSON('abo-admin-admins-data-matrix',    {});
    const ROUTES    = readJSON('abo-admin-admins-data-routes',    {});

    if (!root || ADMINS.length === 0) {
        console.warn('[admins] no dataset found; leaving shell rendered.');
        return;
    }

    // ─── State ────────────────────────────────────────────────

    const state = {
        list: {
            search: '',
            role:   '',
            status: '',
        },
        drawer: {
            open:        false,
            id:          null,
            dirty:       false,
            returnFocus: null,
        },
        invite: {
            open: false,
        },
        remove: {
            open: false,
            id:   null,
        },
    };

    // ─── Relative time ────────────────────────────────────────

    /**
     * How long ago a moment was, in words.
     *
     * The clock is the browser's own — the page is a live view of the
     * accounts, not a snapshot taken on a fixed day.
     */
    function relative(iso) {
        if (!iso) return '';
        const then = new Date(iso);
        if (isNaN(then.getTime())) return '';

        const diff = (Date.now() - then.getTime()) / 1000; // seconds
        if (diff < 60)        return 'just now';
        if (diff < 3600) {
            const m = Math.round(diff / 60);
            return m + (m === 1 ? ' minute ago' : ' minutes ago');
        }
        if (diff < 86400) {
            const h = Math.round(diff / 3600);
            return h + (h === 1 ? ' hour ago' : ' hours ago');
        }
        if (diff < 604800) {
            const d = Math.round(diff / 86400);
            return d + (d === 1 ? ' day ago' : ' days ago');
        }
        const mo = Math.round(diff / (86400 * 30));
        return mo + (mo === 1 ? ' month ago' : ' months ago');
    }

    function initials(name) {
        return String(name || '')
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((w) => w[0].toUpperCase())
            .join('');
    }

    function roleLabel(key)  { return (ROLES[key] || { label: key }).label; }
    function roleDesc(key)   { return (ROLES[key] || { description: '' }).description; }
    function statusLabel(k)  { return (STATUSES[k] || k); }
    function moduleLabel(k)  { return (MODULES[k] || k); }

    function ownerCount() {
        return ADMINS.filter((a) => a.role === 'owner' && a.status === 'active').length;
    }

    // ─── Requests ─────────────────────────────────────────────

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
     * Run one admin mutation against the server.
     *
     * Rejects with the server's own message when the action is refused,
     * so the caller can show it without having to know the rules.
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
     * The URL of one of the admin endpoints, with the account id put in.
     */
    function urlFor(key, adminId) {
        const url = ROUTES[key];
        if (!url) throw new Error('No route configured for: ' + key);
        return adminId === undefined ? url : url.replace('__ADMIN__', encodeURIComponent(adminId));
    }

    // ─── Toast ────────────────────────────────────────────────

    let toastTimer;
    function toast(text) {
        const wrap = $('[data-abo-admin-admins-toast]');
        const txt  = $('[data-abo-admin-admins-toast-text]', wrap);
        if (!wrap) return;
        txt.textContent = text;
        wrap.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { wrap.hidden = true; }, 2400);
    }

    // ─── Main tabs (Admins / Roles) ───────────────────────────

    function setMainTab(name) {
        $$('[data-abo-admin-admins-main-tab]').forEach((btn) => {
            const active = btn.getAttribute('data-abo-admin-admins-main-tab') === name;
            btn.classList.toggle('is-active', active);
            btn.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        $$('[data-abo-admin-admins-pane]').forEach((pane) => {
            pane.hidden = pane.getAttribute('data-abo-admin-admins-pane') !== name;
        });
    }

    function wireMainTabs() {
        $$('[data-abo-admin-admins-main-tab]').forEach((btn) => {
            btn.addEventListener('click', () => {
                setMainTab(btn.getAttribute('data-abo-admin-admins-main-tab'));
            });
        });
    }

    // ─── List filtering ───────────────────────────────────────

    function filteredAdmins() {
        const f = state.list;
        const q  = f.search.trim().toLowerCase();
        return ADMINS.filter((a) => {
            if (f.role   && a.role   !== f.role)   return false;
            if (f.status && a.status !== f.status) return false;
            if (q) {
                const h = (a.name + ' ' + a.email).toLowerCase();
                if (h.indexOf(q) === -1) return false;
            }
            return true;
        });
    }

    function updateListClear() {
        const btn = $('[data-abo-admin-admins-clear]');
        if (!btn) return;
        const f = state.list;
        const active = !!(f.search || f.role || f.status);
        btn.hidden = !active;
    }

    function updateResultCount(n) {
        $$('[data-abo-admin-admins-result-count]').forEach((el) => {
            el.textContent = n + (n === 1 ? ' admin' : ' admins');
        });
        $$('[data-abo-admin-admins-main-tab-count="list"]').forEach((el) => {
            el.textContent = '(' + ADMINS.length + ')';
        });
    }

    // ─── Render: admins table rows (desktop) ───────────────────

    function escapeHTML(s) {
        return String(s).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[c]));
    }

    function roleBadgeHTML(role) {
        const variant = role === 'owner' ? ' abo-admin-admins__role--owner' : '';
        return '<span class="abo-admin-admins__role' + variant + '">'
             + escapeHTML(roleLabel(role)) + '</span>';
    }

    function statusBadgeHTML(status) {
        return '<span class="abo-admin-admins__status abo-admin-admins__status--' + status + '">'
             + escapeHTML(statusLabel(status)) + '</span>';
    }

    function lastLoginHTML(iso) {
        if (!iso) {
            return '<span class="abo-admin-admins__last-login abo-admin-admins__last-login--never">Never</span>';
        }
        return '<span class="abo-admin-admins__last-login" title="' + escapeHTML(iso) + '">'
             + escapeHTML(relative(iso)) + '</span>';
    }

    function editBtnHTML(a) {
        return '<button type="button" class="abo-admin-admins__edit-btn" '
             + 'aria-label="Edit ' + escapeHTML(a.name) + '" '
             + 'data-abo-admin-admins-edit="' + escapeHTML(a.id) + '">'
             + '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
             +   '<path d="M4 20h4l10-10-4-4L4 16v4zM14 6l4 4" '
             +   'stroke="currentColor" stroke-width="1.6" fill="none" '
             +   'stroke-linecap="round" stroke-linejoin="round"/>'
             + '</svg></button>';
    }

    function rowHTML(a) {
        const youPill = a.signedIn
            ? '<span class="abo-admin-admins__you-pill" aria-label="Signed-in admin">YOU</span>'
            : '';
        return ''
            + '<tr tabindex="0" data-abo-admin-admins-row="' + escapeHTML(a.id) + '">'
            +   '<td>'
            +     '<div class="abo-admin-admins__admin">'
            +       '<span class="abo-admin-admins__avatar" aria-hidden="true">' + escapeHTML(initials(a.name)) + '</span>'
            +       '<div class="abo-admin-admins__admin-text">'
            +         '<div class="abo-admin-admins__name-row">'
            +           '<span>' + escapeHTML(a.name) + '</span>'
            +           youPill
            +         '</div>'
            +         '<p class="abo-admin-admins__email">' + escapeHTML(a.email) + '</p>'
            +       '</div>'
            +     '</div>'
            +   '</td>'
            +   '<td>' + roleBadgeHTML(a.role) + '</td>'
            +   '<td>' + statusBadgeHTML(a.status) + '</td>'
            +   '<td>' + lastLoginHTML(a.lastLogin) + '</td>'
            +   '<td style="text-align:right; padding-right: 1.1rem;">' + editBtnHTML(a) + '</td>'
            + '</tr>';
    }

    function cardHTML(a) {
        const youPill = a.signedIn
            ? '<span class="abo-admin-admins__you-pill">YOU</span>'
            : '';
        return ''
            + '<li class="abo-admin-admins__card" tabindex="0" '
            + 'data-abo-admin-admins-row="' + escapeHTML(a.id) + '">'
            +   '<div class="abo-admin-admins__card-top">'
            +     '<span class="abo-admin-admins__avatar" aria-hidden="true">' + escapeHTML(initials(a.name)) + '</span>'
            +     '<span class="abo-admin-admins__card-name">' + escapeHTML(a.name) + ' ' + youPill + '</span>'
            +     statusBadgeHTML(a.status)
            +   '</div>'
            +   '<div class="abo-admin-admins__card-row">'
            +     roleBadgeHTML(a.role)
            +     '<span class="abo-admin-admins__card-email">' + escapeHTML(a.email) + '</span>'
            +   '</div>'
            +   '<div class="abo-admin-admins__card-last-login">'
            +     (a.lastLogin
            +         ? 'Last login ' + escapeHTML(relative(a.lastLogin))
            +         : 'Never signed in')
            +   '</div>'
            + '</li>';
    }

    function renderList() {
        const list  = filteredAdmins();
        const tbody = $('[data-abo-admin-admins-tbody]');
        const cards = $('[data-abo-admin-admins-cards]');
        const nores = $('[data-abo-admin-admins-state="noresults"]');

        if (tbody) tbody.innerHTML = list.map(rowHTML).join('');
        if (cards) cards.innerHTML = list.map(cardHTML).join('');

        if (nores) nores.hidden = list.length > 0;
        updateResultCount(list.length);
    }

    function wireListToolbar() {
        const search = $('[data-abo-admin-admins-search]');
        const role   = $('[data-abo-admin-admins-filter="role"]');
        const status = $('[data-abo-admin-admins-filter="status"]');
        const clear  = $('[data-abo-admin-admins-clear]');

        if (search) search.addEventListener('input', () => {
            state.list.search = search.value;
            updateListClear();
            renderList();
        });
        if (role) role.addEventListener('change', () => {
            state.list.role = role.value;
            updateListClear();
            renderList();
        });
        if (status) status.addEventListener('change', () => {
            state.list.status = status.value;
            updateListClear();
            renderList();
        });
        if (clear) clear.addEventListener('click', () => {
            state.list = { search: '', role: '', status: '' };
            if (search) search.value = '';
            if (role)   role.value   = '';
            if (status) status.value = '';
            updateListClear();
            renderList();
        });
    }

    function wireRowClicks() {
        document.addEventListener('click', (ev) => {
            const row = ev.target.closest('[data-abo-admin-admins-row]');
            if (!row) return;
            // If the click came from the Edit button, only open.
            if (ev.target.closest('[data-abo-admin-admins-edit]')) {
                openDrawer(row.getAttribute('data-abo-admin-admins-row'),
                           ev.target.closest('[data-abo-admin-admins-edit]'));
                return;
            }
            // Avoid the row's own action button swallowing the click.
            openDrawer(row.getAttribute('data-abo-admin-admins-row'),
                       row.querySelector('[data-abo-admin-admins-edit]') || row);
        });

        document.addEventListener('keydown', (ev) => {
            if (ev.key !== 'Enter' && ev.key !== ' ') return;
            const row = ev.target.closest && ev.target.closest('[data-abo-admin-admins-row]');
            if (!row) return;
            if (ev.target.tagName === 'INPUT' || ev.target.tagName === 'SELECT') return;
            ev.preventDefault();
            openDrawer(row.getAttribute('data-abo-admin-admins-row'),
                       row.querySelector('[data-abo-admin-admins-edit]') || row);
        });
    }

    // ─── Slide-over (edit admin) ──────────────────────────────

    const drawer     = $('[data-abo-admin-admins-drawer]');
    const drawerName = $('[data-abo-admin-admins-drawer-name]');
    const drawerName2 = $('[data-abo-admin-admins-drawer-name-2]');
    const drawerEmail = $('[data-abo-admin-admins-drawer-email]');
    const drawerEmailInput = $('[data-abo-admin-admins-drawer-email-input]');
    const drawerAvatar = $('[data-abo-admin-admins-drawer-avatar]');
    const drawerYou    = $('[data-abo-admin-admins-drawer-you]');
    const drawerFullname = $('[data-abo-admin-admins-drawer-fullname]');
    const drawerRole     = $('[data-abo-admin-admins-drawer-role]');
    const drawerRoleDesc = $('[data-abo-admin-admins-drawer-role-desc]');
    const drawerChips    = $('[data-abo-admin-admins-drawer-chips]');
    const drawerActive   = $('[data-abo-admin-admins-drawer-active]');
    const drawerStatusNote = $('[data-abo-admin-admins-drawer-status-note]');
    const drawerActivity = $('[data-abo-admin-admins-drawer-activity]');
    const drawerActivityEmpty = $('[data-abo-admin-admins-drawer-activity-empty]');
    const drawerSave     = $('[data-abo-admin-admins-drawer-save]');
    const drawerRemove   = $('[data-abo-admin-admins-drawer-remove]');
    const drawerDirty    = $('[data-abo-admin-admins-drawer-dirty]');

    function roleSummaryHTML(role) {
        const levels = MATRIX[role] || {};
        const out = [];
        Object.keys(levels).forEach((mod) => {
            const lvl = levels[mod];
            if (!lvl) return;
            const cls = lvl === 2 ? 'abo-admin-admins__chip--manage' : 'abo-admin-admins__chip--view';
            const lbl = lvl === 2 ? 'Manage' : 'View';
            out.push(
                '<li class="abo-admin-admins__chip ' + cls + '">'
                + '<span>' + escapeHTML(moduleLabel(mod)) + '</span>'
                + '<span class="abo-admin-admins__chip-level">' + lbl + '</span>'
                + '</li>'
            );
        });
        if (out.length === 0) {
            return '<li class="abo-admin-admins__chip" style="color: var(--abo-admin-gray);">No access to any module</li>';
        }
        return out.join('');
    }

    function setDrawerDirty(d) {
        state.drawer.dirty = d;
        if (drawerDirty) drawerDirty.hidden = !d;
        if (drawerSave)   drawerSave.disabled = !d;
    }

    function populateDrawer(a) {
        if (!a) return;
        if (drawerName)     drawerName.textContent     = a.name;
        if (drawerName2)    drawerName2.textContent    = a.name;
        if (drawerEmail)    drawerEmail.textContent    = a.email;
        if (drawerEmailInput) drawerEmailInput.value   = a.email;
        if (drawerAvatar)   drawerAvatar.textContent   = initials(a.name);
        if (drawerYou)      drawerYou.hidden           = !a.signedIn;
        if (drawerFullname) drawerFullname.value       = a.name;
        if (drawerRole)     drawerRole.value           = a.role;
        if (drawerRoleDesc) drawerRoleDesc.textContent = roleDesc(a.role);
        if (drawerChips)    drawerChips.innerHTML      = roleSummaryHTML(a.role);
        if (drawerActive)   drawerActive.checked       = a.status === 'active';

        // The trail: what has happened to this account, and to the team
        // around it.
        const list = ACTIVITY
            .filter((x) => x.subjectId === a.id || x.adminId === a.id)
            .slice(0, 5);
        if (drawerActivity) drawerActivity.innerHTML = list.map((x) =>
            '<li><span>' + escapeHTML(activityLabel(x)) + '</span>'
            + '<time>' + escapeHTML(relative(x.at)) + '</time></li>'
        ).join('');
        if (drawerActivityEmpty) drawerActivityEmpty.hidden = list.length > 0;

        applyDrawerSafety(a);

        setDrawerDirty(false);
    }

    /**
     * The wording of a trail row.
     */
    function activityLabel(entry) {
        const who = entry.adminName || 'Someone';
        const action = entry.action || '';

        if (action === 'admin.created')      return who + ' created an account';
        if (action === 'admin.updated')      return who + ' changed an account';
        if (action === 'admin.deleted')      return who + ' removed an account';
        if (action === 'admin.activated')    return who + ' reactivated an account';
        if (action === 'admin.suspended')    return who + ' suspended an account';

        return who + ' — ' + action.replace(/[._]/g, ' ');
    }

    function applyDrawerSafety(a) {
        const isYou      = !!a.signedIn;
        const isOwner    = a.role === 'owner';
        const isLastOwner = isOwner && (ownerCount() <= 1);

        const lockNote = isYou
            ? "You can't remove or suspend your own account."
            : isLastOwner
                ? 'At least one active Owner is required.'
                : '';

        // Role select
        if (drawerRole) {
            drawerRole.disabled = false;
            if (drawerRoleDesc && lockNote) {
                drawerRoleDesc.textContent = roleDesc(drawerRole.value);
            }
        }

        // Status switch
        if (drawerActive) {
            drawerActive.disabled = isYou || isLastOwner;
        }

        // Remove button
        if (drawerRemove) {
            drawerRemove.disabled = isYou || isLastOwner;
        }

        // Status note (only when controls are locked)
        if (drawerStatusNote) {
            if (lockNote && (isYou || isLastOwner)) {
                drawerStatusNote.textContent = lockNote;
                drawerStatusNote.hidden = false;
            } else {
                drawerStatusNote.hidden = true;
            }
        }
    }

    function openDrawer(id, returnFocus) {
        const a = ADMINS.find((x) => x.id === id);
        if (!a || !drawer) return;
        state.drawer.id = id;
        state.drawer.returnFocus = returnFocus || null;
        populateDrawer(a);
        drawer.hidden = false;
        // Force reflow then add is-open so the transition runs.
        // eslint-disable-next-line no-unused-expressions
        drawer.offsetHeight;
        drawer.classList.add('is-open');
        // Focus the close button after the panel has slid in.
        setTimeout(() => {
            const closeBtn = drawer.querySelector('[data-abo-admin-admins-drawer-close]');
            if (closeBtn) closeBtn.focus();
        }, 60);
    }

    function closeDrawer() {
        if (!drawer) return;
        drawer.classList.remove('is-open');
        setTimeout(() => {
            drawer.hidden = true;
            state.drawer.id = null;
            if (state.drawer.returnFocus && state.drawer.returnFocus.focus) {
                state.drawer.returnFocus.focus();
            }
            state.drawer.returnFocus = null;
        }, 240);
    }

    function focusTrap(container, ev) {
        if (ev.key !== 'Tab') return;
        const focusables = $$('button:not([disabled]):not([hidden]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])', container)
            .filter((el) => el.offsetParent !== null);
        if (focusables.length === 0) return;
        const first = focusables[0];
        const last  = focusables[focusables.length - 1];
        if (ev.shiftKey && document.activeElement === first) {
            ev.preventDefault();
            last.focus();
        } else if (!ev.shiftKey && document.activeElement === last) {
            ev.preventDefault();
            first.focus();
        }
    }

    function wireDrawer() {
        if (!drawer) return;

        $$('[data-abo-admin-admins-drawer-close]', drawer).forEach((b) => {
            b.addEventListener('click', closeDrawer);
        });

        // Editable fields — set dirty on change.
        if (drawerFullname) drawerFullname.addEventListener('input', () => setDrawerDirty(true));
        if (drawerEmailInput) drawerEmailInput.addEventListener('input', () => setDrawerDirty(true));
        if (drawerActive)   drawerActive.addEventListener('change', () => setDrawerDirty(true));

        if (drawerRole) drawerRole.addEventListener('change', () => {
            if (drawerRoleDesc) drawerRoleDesc.textContent = roleDesc(drawerRole.value);
            if (drawerChips)    drawerChips.innerHTML = roleSummaryHTML(drawerRole.value);
            setDrawerDirty(true);
        });

        // Password — a reset link, sent to the account's own address.
        const resetPwd = $('[data-abo-admin-admins-drawer-reset-pwd]');
        if (resetPwd) resetPwd.addEventListener('click', sendResetLink);

        // Save
        if (drawerSave) drawerSave.addEventListener('click', saveAdmin);

        // Remove → confirm modal
        if (drawerRemove) drawerRemove.addEventListener('click', () => {
            if (drawerRemove.disabled) return;
            openRemove(state.drawer.id);
        });

        // Esc + focus trap
        document.addEventListener('keydown', (ev) => {
            if (ev.key === 'Escape') {
                if (state.invite.open) { ev.preventDefault(); closeInvite(); return; }
                if (state.remove.open) { ev.preventDefault(); closeRemove(); return; }
                if (state.drawer.open || (drawer && !drawer.hidden)) {
                    ev.preventDefault();
                    closeDrawer();
                    return;
                }
            }
        });
    }

    /**
     * Save the account the drawer is showing.
     */
    async function saveAdmin() {
        const a = ADMINS.find((x) => x.id === state.drawer.id);
        if (!a) return;

        const body = {
            name: drawerFullname ? drawerFullname.value.trim() : a.name,
            email: drawerEmailInput ? drawerEmailInput.value.trim() : a.email,
            role: drawerRole ? drawerRole.value : a.role,
        };

        const wantsSuspended = drawerActive ? !drawerActive.checked : a.status !== 'active';

        if (drawerSave) drawerSave.disabled = true;

        try {
            await request('PUT', urlFor('update', a.id), body);

            // The status has its own endpoint: it is a switch the panel
            // guards on its own rules.
            if (wantsSuspended !== (a.status !== 'active')) {
                await request('PATCH', urlFor('toggle', a.id));
            }

            toast('Saved.');
            window.location.reload();
        } catch (err) {
            toast(err.message);
            if (drawerSave) drawerSave.disabled = false;
        }
    }

    /**
     * Ask the server to send a reset link to the account.
     *
     * The endpoint never says whether the address belongs to an admin,
     * so the message here does not either.
     */
    async function sendResetLink() {
        const a = ADMINS.find((x) => x.id === state.drawer.id);
        if (!a) return;

        try {
            await request('POST', urlFor('passwordEmail'), { email: a.email });
            toast('If that account exists, a reset link is on its way.');
        } catch (err) {
            toast(err.message);
        }
    }

    // ─── Add admin modal ───────────────────────────────────────

    const inviteModal  = $('[data-abo-admin-admins-invite]');
    const inviteName   = $('[data-abo-admin-admins-invite-name]');
    const inviteEmail  = $('[data-abo-admin-admins-invite-email]');
    const inviteRole   = $('[data-abo-admin-admins-invite-role]');
    const inviteRoleDesc = $('[data-abo-admin-admins-invite-role-desc]');
    const inviteEmailErr = $('[data-abo-admin-admins-invite-email-err]');
    const invitePassword = $('[data-abo-admin-admins-invite-password]');
    const invitePasswordConfirm = $('[data-abo-admin-admins-invite-password-confirm]');

    function openInvite() {
        if (!inviteModal) return;
        if (inviteName)   inviteName.value   = '';
        if (inviteEmail)  inviteEmail.value  = '';
        if (inviteRole)   inviteRole.value   = 'viewer';
        if (invitePassword) invitePassword.value = '';
        if (invitePasswordConfirm) invitePasswordConfirm.value = '';
        if (inviteEmailErr) inviteEmailErr.hidden = true;
        if (inviteRoleDesc) inviteRoleDesc.textContent = roleDesc(inviteRole ? inviteRole.value : 'viewer');
        inviteModal.hidden = false;
        state.invite.open = true;
        setTimeout(() => {
            if (inviteName) inviteName.focus();
        }, 30);
    }

    function closeInvite() {
        if (!inviteModal) return;
        inviteModal.hidden = true;
        state.invite.open = false;
    }

    function validEmail(s) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(s);
    }

    async function sendInvite() {
        if (!inviteName || !inviteEmail || !inviteRole) return;
        const name  = inviteName.value.trim();
        const email = inviteEmail.value.trim();
        const role  = inviteRole.value;
        const password = invitePassword ? invitePassword.value : '';
        const confirmation = invitePasswordConfirm ? invitePasswordConfirm.value : '';

        if (!validEmail(email)) {
            if (inviteEmailErr) inviteEmailErr.hidden = false;
            inviteEmail.focus();
            return;
        }
        if (inviteEmailErr) inviteEmailErr.hidden = true;

        if (password.length < 8) {
            toast('The password needs at least 8 characters.');
            if (invitePassword) invitePassword.focus();
            return;
        }

        if (password !== confirmation) {
            toast('The two passwords do not match.');
            if (invitePasswordConfirm) invitePasswordConfirm.focus();
            return;
        }

        try {
            await request('POST', urlFor('store'), {
                name: name,
                email: email,
                role: role,
                password: password,
                password_confirmation: confirmation,
            });
            closeInvite();
            toast('Admin added.');
            window.location.reload();
        } catch (err) {
            toast(err.message);
        }
    }

    function wireInvite() {
        if (!inviteModal) return;
        $$('[data-abo-admin-admins-invite-close]', inviteModal).forEach((b) => {
            b.addEventListener('click', closeInvite);
        });
        const trigger = $('[data-abo-admin-admins-action="invite"]');
        if (trigger) trigger.addEventListener('click', openInvite);

        const send = $('[data-abo-admin-admins-invite-send]');
        if (send) send.addEventListener('click', sendInvite);

        if (inviteEmail) inviteEmail.addEventListener('input', () => {
            if (inviteEmailErr && !inviteEmailErr.hidden) inviteEmailErr.hidden = true;
        });
        if (inviteRole)  inviteRole.addEventListener('change', () => {
            if (inviteRoleDesc) inviteRoleDesc.textContent = roleDesc(inviteRole.value);
        });
    }

    // ─── Remove modal ──────────────────────────────────────────

    const removeModal = $('[data-abo-admin-admins-remove]');
    const removeBody  = $('[data-abo-admin-admins-remove-body]');
    let removeId = null;

    function openRemove(id) {
        const a = ADMINS.find((x) => x.id === id);
        if (!a || !removeModal) return;
        removeId = id;
        if (removeBody) {
            removeBody.textContent = a.name + ' will lose access immediately. Their past activity stays in the log.';
        }
        removeModal.hidden = false;
        state.remove.open = true;
        setTimeout(() => {
            const cancel = removeModal.querySelector('[data-abo-admin-admins-remove-close]');
            if (cancel) cancel.focus();
        }, 30);
    }

    function closeRemove() {
        if (!removeModal) return;
        removeModal.hidden = true;
        state.remove.open = false;
        removeId = null;
    }

    async function confirmRemove() {
        if (!removeId) return;
        const id = removeId;

        try {
            await request('DELETE', urlFor('destroy', id));
            closeRemove();
            closeDrawer();
            toast('Admin removed.');
            window.location.reload();
        } catch (err) {
            toast(err.message);
        }
    }

    function wireRemove() {
        if (!removeModal) return;
        $$('[data-abo-admin-admins-remove-close]', removeModal).forEach((b) => {
            b.addEventListener('click', closeRemove);
        });
        const confirm = $('[data-abo-admin-admins-remove-confirm]');
        if (confirm) confirm.addEventListener('click', confirmRemove);
    }

    // ─── Boot ─────────────────────────────────────────────────

    function boot() {
        wireMainTabs();
        wireListToolbar();
        wireRowClicks();
        wireDrawer();
        wireInvite();
        wireRemove();
        setDrawerDirty(false);
        updateListClear();
        // The skeleton is only there for the first paint; the data is
        // already in the page.
        const load = $('[data-abo-admin-admins-state="loading"]');
        if (load) load.hidden = true;
        renderList();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
