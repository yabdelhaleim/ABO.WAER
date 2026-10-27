// ABO Admin · Pages — DB-driven.
//
// Scope:
//   - The page list comes from the `pages` table. Admin\PageController
//     builds the dataset server-side and this script reads it from the
//     single <script type="application/json"> tag it renders.
//   - The editor is aligned to the columns that actually exist:
//     title → pages.title, title_ar → pages.title_ar,
//     content → pages.content, content_ar → pages.content_ar,
//     the Published switch → pages.is_active.
//   - SAVE is a real request: it PUTs to the row's `updateUrl`
//     (route admin.pages.update) and only reports success after the
//     server has confirmed it. A validation error, an expired session
//     or a server fault all leave the editor dirty and say so.
//
// The script wires seven interactive things and nothing else:
//   1. The list of pages (search + status filter + keyboard nav)
//   2. The editor (title EN/AR switch, slug, content toolbar with
//      EN/AR switch, published/draft switch, dirty dot)
//   3. Save (PUT to admin.pages.update, with error handling)
//   4. Preview modal (renders the current page in a store-like frame)
//   5. The single shared toast + slide-over on tablet / mobile

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
        catch (err) { console.error('[pages] failed to parse', id, err); return fallback; }
    }

    let PAGES = readJSON('abo-admin-pages-data', []);

    if (!root || PAGES.length === 0) {
        console.warn('[pages] no dataset found; leaving shell rendered.');
        return;
    }

    // ─── State ────────────────────────────────────────────────

    const state = {
        list:    { search: '', status: '' },
        editor:  { id: null, mode: 'idle', dirty: false, lang: { title: 'en', content: 'en' } },
        preview: { open: false },
    };

    // ─── Today / relative time ───────────────────────────────

    function relative(iso) {
        if (!iso) return '';
        const t = new Date(iso);
        if (isNaN(t.getTime())) return '';
        const diff = (Date.now() - t.getTime()) / 1000;
        if (diff < 60)        return 'just now';
        if (diff < 3600) {
            const m = Math.round(diff / 60);
            return m + (m === 1 ? ' minute ago' : ' minutes ago');
        }
        if (diff < 86400) {
            const h = Math.round(diff / 3600);
            return h + (h === 1 ? ' hour ago' : ' hours ago');
        }
        if (diff < 86400 * 7) {
            const d = Math.round(diff / 86400);
            return d + (d === 1 ? ' day ago' : ' days ago');
        }
        if (diff < 86400 * 30) {
            const w = Math.round(diff / (86400 * 7));
            return w + (w === 1 ? ' week ago' : ' weeks ago');
        }
        const mo = Math.round(diff / (86400 * 30));
        return mo + (mo === 1 ? ' month ago' : ' months ago');
    }

    function initials(title) {
        const t = String(title || '').trim();
        if (!t) return '·';
        return t.slice(0, 1).toUpperCase();
    }

    function escapeHTML(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[c]));
    }

    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function loginUrl() {
        return window.__PAGES_LOGIN_URL__ || '/admin/login';
    }

    // ─── Toast ────────────────────────────────────────────────

    let toastTimer;
    function toast(text) {
        const wrap = $('[data-abo-admin-pages-toast]');
        const txt  = $('[data-abo-admin-pages-toast-text]', wrap);
        if (!wrap) return;
        txt.textContent = text;
        wrap.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { wrap.hidden = true; }, 3200);
    }

    // ─── List filtering + render ─────────────────────────────

    function filteredPages() {
        const f = state.list;
        const q = f.search.trim().toLowerCase();
        return PAGES.filter((p) => {
            if (f.status && p.status !== f.status) return false;
            if (q) {
                const hay = ((p.title.en || '') + ' ' + (p.title.ar || '') + ' ' + p.slug).toLowerCase();
                if (hay.indexOf(q) === -1) return false;
            }
            return true;
        });
    }

    function rowHTML(p) {
        const title  = p.title.en || p.title.ar || 'Untitled';
        const ar     = p.title.ar || '';
        const path   = '/' + (p.slug || '');
        const type   = p.type;     // system | custom
        const status = p.status;   // published | draft
        const upd    = relative(p.updated);
        const typeCls = type === 'custom' ? ' abo-admin-pages__type--custom' : '';
        return ''
            + '<button type="button" class="abo-admin-pages__row" '
            +   'role="option" '
            +   'aria-selected="' + (state.editor.id === p.id ? 'true' : 'false') + '" '
            +   'data-abo-admin-pages-row="' + escapeHTML(p.id) + '">'
            +   '<span class="abo-admin-pages__row-icon" aria-hidden="true">' + escapeHTML(initials(title)) + '</span>'
            +   '<span class="abo-admin-pages__row-text">'
            +     '<span class="abo-admin-pages__row-title">' + escapeHTML(title)
            +       (ar ? ' <span style="color:var(--abo-admin-gray);font-weight:500;font-size:0.78rem;">· ' + escapeHTML(ar) + '</span>' : '')
            +     '</span>'
            +     '<span class="abo-admin-pages__row-path">' + escapeHTML(path) + '</span>'
            +   '</span>'
            +   '<span class="abo-admin-pages__row-meta">'
            +     '<span class="abo-admin-pages__type' + typeCls + '">' + escapeHTML(type) + '</span>'
            +     '<span class="abo-admin-pages__status abo-admin-pages__status--' + status + '">' + escapeHTML(status) + '</span>'
            +     (upd ? '<span class="abo-admin-pages__row-updated">Updated ' + escapeHTML(upd) + '</span>' : '')
            +   '</span>'
            + '</button>';
    }

    function renderList() {
        const list = $('[data-abo-admin-pages-list]');
        const nores = $('[data-abo-admin-pages-state="noresults"]');
        const empty = $('[data-abo-admin-pages-state="empty"]');
        if (!list) return;
        const items = filteredPages();
        list.innerHTML = items.map(rowHTML).join('');

        if (nores) nores.hidden = items.length > 0 || (PAGES.length === 0);
        if (empty) empty.hidden = PAGES.length !== 0;
        const count = $('[data-abo-admin-pages-result-count]');
        if (count) count.textContent = items.length + (items.length === 1 ? ' page' : ' pages');
    }

    function wireListToolbar() {
        const search = $('[data-abo-admin-pages-search]');
        const status = $('[data-abo-admin-pages-filter="status"]');
        const clear  = $('[data-abo-admin-pages-clear]');
        if (search) search.addEventListener('input', () => {
            state.list.search = search.value;
            renderList();
        });
        if (status) status.addEventListener('change', () => {
            state.list.status = status.value;
            renderList();
        });
        if (clear) clear.addEventListener('click', () => {
            state.list = { search: '', status: '' };
            if (search) search.value = '';
            if (status) status.value = '';
            renderList();
        });
    }

    function wireListClicks() {
        const list = $('[data-abo-admin-pages-list]');
        if (!list) return;
        list.addEventListener('click', (ev) => {
            const row = ev.target.closest('[data-abo-admin-pages-row]');
            if (!row) return;
            selectPage(row.getAttribute('data-abo-admin-pages-row'));
        });
        list.addEventListener('keydown', (ev) => {
            if (ev.key !== 'ArrowDown' && ev.key !== 'ArrowUp' && ev.key !== 'Home' && ev.key !== 'End') return;
            const rows = $$('[data-abo-admin-pages-row]', list);
            if (rows.length === 0) return;
            const active = document.activeElement && document.activeElement.closest('[data-abo-admin-pages-row]');
            let idx = active ? rows.indexOf(active) : -1;
            if (ev.key === 'ArrowDown') idx = Math.min(rows.length - 1, idx + 1);
            else if (ev.key === 'ArrowUp') idx = Math.max(0, idx - 1);
            else if (ev.key === 'Home') idx = 0;
            else if (ev.key === 'End') idx = rows.length - 1;
            if (idx < 0) idx = 0;
            ev.preventDefault();
            rows[idx].focus();
        });
    }

    // ─── Editor: open, populate, close ───────────────────────

    const editorEl       = $('[data-abo-admin-pages-editor]');
    const editorPlaceholder = $('[data-abo-admin-pages-editor-placeholder]');
    const editorPane      = $('[data-abo-admin-pages-editor-pane]');
    const editorTitle     = $('[data-abo-admin-pages-editor-title]');
    const editorMode      = $('[data-abo-admin-pages-editor-mode]');
    const editorDirty     = $('[data-abo-admin-pages-editor-dirty]');
    const editorCloseBtn  = $('[data-abo-admin-pages-editor-close]');
    const systemWarning   = $('[data-abo-admin-pages-system-warning]');
    const slugLock        = $('[data-abo-admin-pages-slug-lock]');
    const slugInput       = $('[data-abo-admin-pages-slug]');
    const slugNote        = $('[data-abo-admin-pages-slug-note]');

    function showEditor() {
        if (editorEl)       editorEl.hidden = false;
        if (editorPlaceholder) editorPlaceholder.hidden = true;
        if (editorPane)     editorPane.classList.add('is-open');
    }

    function hideEditor() {
        if (editorEl)       editorEl.hidden = true;
        if (editorPlaceholder) editorPlaceholder.hidden = false;
        if (editorPane)     editorPane.classList.remove('is-open');
        state.editor.id = null;
        state.editor.mode = 'idle';
    }

    function setDirty(d) {
        state.editor.dirty = d;
        if (editorDirty) editorDirty.hidden = !d;
    }

    function applyLang(lang, scope) {
        // scope: 'title' | 'content'
        const inputs = $$(scope === 'title'
            ? '[data-abo-admin-pages-title-lang]'
            : '[data-abo-admin-pages-content-lang]');
        inputs.forEach((el) => {
            const v = el.getAttribute('data-abo-admin-pages-' + scope + '-lang');
            el.hidden = v !== lang;
        });
        $$('[data-abo-admin-pages-lang="' + scope + '"]').forEach((b) => {
            const active = b.getAttribute('data-abo-admin-pages-lang-value') === lang;
            b.classList.toggle('is-active', active);
            b.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        state.editor.lang[scope] = lang;
    }

    function setSystemWarning(page) {
        if (!systemWarning) return;
        if (page && page.type === 'system' && page.status === 'draft') {
            systemWarning.textContent = 'This page is linked from the store footer and checkout. Unpublishing it makes it return 404 on the storefront.';
            systemWarning.hidden = false;
        } else {
            systemWarning.hidden = true;
        }
    }

    /**
     * The slug is read-only for every row.
     *
     * `slug` is not part of what Admin\PageRequest validates, so the
     * update endpoint never writes it — the five system pages are
     * addressed by fixed named routes, and a custom slug would have no
     * storefront route to resolve to. Offering an editable field that
     * silently does not persist would be worse than showing it as
     * read-only information.
     */
    function applySlugLock(page) {
        if (slugInput) {
            slugInput.readOnly = true;
            slugInput.classList.add('abo-admin-pages__field-input--slug');
        }
        if (slugLock) slugLock.hidden = false;
        if (slugNote) slugNote.textContent = 'Fixed — the storefront URL is the page slug.';
    }

    function selectPage(id) {
        const p = PAGES.find((x) => x.id === id);
        if (!p) return;
        // If we're editing another page and the current one is dirty,
        // ask before dropping the changes.
        if (state.editor.id && state.editor.id !== id && state.editor.dirty) {
            if (!confirm('You have unsaved changes. Discard them?')) {
                return;
            }
        }
        state.editor.id = id;
        state.editor.mode = 'edit';
        populateEditor(p);
        showEditor();
        renderList();
    }

    function populateEditor(p) {
        if (!p) return;
        // Editor head
        if (editorMode)  editorMode.textContent = 'EDIT PAGE';
        if (editorTitle) editorTitle.textContent = p.title.en || p.title.ar || 'Untitled';

        // Title EN/AR
        const tEn = $('[data-abo-admin-pages-title-lang="en"]');
        const tAr = $('[data-abo-admin-pages-title-lang="ar"]');
        if (tEn) tEn.value = p.title.en || '';
        if (tAr) tAr.value = p.title.ar || '';
        applyLang('en', 'title');

        // Slug
        if (slugInput) slugInput.value = p.slug || '';
        applySlugLock(p);

        // Content EN/AR
        const cEn = $('[data-abo-admin-pages-content-lang="en"]');
        const cAr = $('[data-abo-admin-pages-content-lang="ar"]');
        if (cEn) cEn.innerHTML = p.content.en || '';
        if (cAr) cAr.innerHTML = p.content.ar || '';
        applyLang('en', 'content');

        // Settings: Published (is_active)
        const statusEl = $('[data-abo-admin-pages-field="status"]');
        if (statusEl) statusEl.checked = p.status === 'published';
        setSystemWarning(p);

        setDirty(false);
    }

    // ─── Field wiring (title / content / status / toolbar) ────

    function wireFields() {
        // Title EN
        const tEn = $('[data-abo-admin-pages-title-lang="en"]');
        if (tEn) tEn.addEventListener('input', () => {
            const p = PAGES.find((x) => x.id === state.editor.id);
            if (!p) return;
            p.title.en = tEn.value;
            if (editorTitle) editorTitle.textContent = tEn.value || p.title.ar || 'Untitled';
            setDirty(true);
            renderList();
        });

        // Title AR
        const tAr = $('[data-abo-admin-pages-title-lang="ar"]');
        if (tAr) tAr.addEventListener('input', () => {
            const p = PAGES.find((x) => x.id === state.editor.id);
            if (!p) return;
            p.title.ar = tAr.value;
            setDirty(true);
            renderList();
        });

        // Content EN / AR (input bubbles from contenteditable)
        const cEn = $('[data-abo-admin-pages-content-lang="en"]');
        if (cEn) cEn.addEventListener('input', () => {
            const p = PAGES.find((x) => x.id === state.editor.id);
            if (!p) return;
            p.content.en = cEn.innerHTML;
            setDirty(true);
        });
        const cAr = $('[data-abo-admin-pages-content-lang="ar"]');
        if (cAr) cAr.addEventListener('input', () => {
            const p = PAGES.find((x) => x.id === state.editor.id);
            if (!p) return;
            p.content.ar = cAr.innerHTML;
            setDirty(true);
        });

        // Status (published / draft → pages.is_active)
        const statusEl = $('[data-abo-admin-pages-field="status"]');
        if (statusEl) statusEl.addEventListener('change', () => {
            const p = PAGES.find((x) => x.id === state.editor.id);
            if (!p) return;
            p.status = statusEl.checked ? 'published' : 'draft';
            setSystemWarning(p);
            setDirty(true);
            renderList();
        });

        // Language switches
        $$('[data-abo-admin-pages-lang="title"]').forEach((b) => {
            b.addEventListener('click', () => applyLang(b.getAttribute('data-abo-admin-pages-lang-value'), 'title'));
        });
        $$('[data-abo-admin-pages-lang="content"]').forEach((b) => {
            b.addEventListener('click', () => applyLang(b.getAttribute('data-abo-admin-pages-lang-value'), 'content'));
        });

        // Content toolbar
        $$('[data-abo-admin-pages-tb]').forEach((btn) => {
            btn.addEventListener('mousedown', (e) => {
                // Prevent losing the selection.
                e.preventDefault();
            });
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const cmd = btn.getAttribute('data-abo-admin-pages-tb');
                const arg = btn.getAttribute('data-abo-admin-pages-tb-arg');
                if (cmd === 'createLink') {
                    const url = prompt('Link URL (https://…)');
                    if (!url) return;
                    document.execCommand('createLink', false, url);
                } else if (cmd === 'formatBlock' && arg) {
                    document.execCommand('formatBlock', false, arg);
                } else {
                    document.execCommand(cmd, false, null);
                }
                // Sync the editor content back to the model.
                const activeLang = state.editor.lang.content;
                const cEl = $('[data-abo-admin-pages-content-lang="' + activeLang + '"]');
                if (cEl) {
                    const p = PAGES.find((x) => x.id === state.editor.id);
                    if (p) {
                        p.content[activeLang] = cEl.innerHTML;
                        setDirty(true);
                    }
                }
            });
        });
    }

    // ─── Save ────────────────────────────────────────────────

    /**
     * Persist the edited page.
     *
     * Only the fields App\Http\Requests\Admin\PageRequest validates are
     * sent: title, title_ar, content, content_ar and is_active. The
     * slug is NOT sent — the endpoint does not accept it.
     *
     * The success toast is shown only after the server has answered
     * 200 with the stored row. Every other outcome says what went
     * wrong and leaves the editor dirty so the change is not lost.
     */
    async function savePage() {
        const p = PAGES.find((x) => x.id === state.editor.id);
        if (!p) return;

        if (!p.updateUrl) {
            toast('This page cannot be saved — it has no update endpoint.');
            return;
        }

        const saveBtn = $('[data-abo-admin-pages-action="save"]');
        if (saveBtn) saveBtn.disabled = true;

        const payload = {
            title: (p.title.en || '').trim(),
            title_ar: (p.title.ar || '').trim() || null,
            content: p.content.en || null,
            content_ar: p.content.ar || null,
            is_active: p.status === 'published',
        };

        try {
            const res = await fetch(p.updateUrl, {
                method: 'PUT',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            });

            // The `admin` middleware answers a signed-out admin with a
            // redirect to the login page rather than a 401, and fetch
            // follows it. `redirected` is how we notice the request
            // never reached the controller — without this check the
            // login page's 200 would look like a successful save.
            if (res.redirected) {
                toast('Your session has ended. Please sign in again.');
                window.location.href = loginUrl();
                return;
            }

            if (res.status === 419) {
                toast('Your session expired. Please refresh the page and try again.');
                return;
            }

            if (res.status === 401) {
                toast('You are not signed in. Please sign in again.');
                window.location.href = loginUrl();
                return;
            }

            if (res.status === 403) {
                toast('You are not allowed to edit this page.');
                return;
            }

            if (res.status === 422) {
                const body = await res.json().catch(() => null);
                let message = 'Please fix the highlighted fields.';
                if (body && body.errors) {
                    const first = Object.values(body.errors)[0];
                    if (first && first.length) message = first[0];
                }
                toast(message);
                return;
            }

            if (!res.ok) {
                toast('Could not save the page. Please try again.');
                return;
            }

            const body = await res.json().catch(() => null);
            const saved = body && body.page ? body.page : null;

            // Adopt the server's copy so the row shows what was stored.
            if (saved) {
                p.title.en = saved.title;
                p.title.ar = saved.title_ar;
                p.content.en = saved.content;
                p.content.ar = saved.content_ar;
                p.status = saved.is_active ? 'published' : 'draft';
                p.updated = saved.updated_at || p.updated;
            }

            setDirty(false);
            renderList();
            populateEditor(p);
            toast('Saved');
        } catch (err) {
            console.error('[pages] save failed', err);
            toast('Could not reach the server. Your changes are still here.');
        } finally {
            if (saveBtn) saveBtn.disabled = false;
        }
    }

    function wireActions() {
        const saveBtn = $('[data-abo-admin-pages-action="save"]');
        if (saveBtn) saveBtn.addEventListener('click', () => { savePage(); });

        const previewBtn = $('[data-abo-admin-pages-action="preview"]');
        if (previewBtn) previewBtn.addEventListener('click', openPreview);

        // Close (slide-over on mobile)
        if (editorCloseBtn) editorCloseBtn.addEventListener('click', hideEditor);
    }

    // ─── Preview modal ────────────────────────────────────────

    const previewModal  = $('[data-abo-admin-pages-preview]');
    const previewFrame  = $('[data-abo-admin-pages-preview-frame]');

    function openPreview() {
        const p = PAGES.find((x) => x.id === state.editor.id);
        if (!p || !previewModal || !previewFrame) return;
        const lang = state.editor.lang.content;
        const title = (lang === 'ar' ? p.title.ar : p.title.en) || 'Untitled';
        const body  = (lang === 'ar' ? p.content.ar : p.content.en) || '';
        const isRtl = lang === 'ar';
        previewFrame.className = 'abo-admin-pages__preview-frame' + (isRtl ? ' abo-admin-pages__preview-frame--rtl' : '');
        let html = '<h2>' + escapeHTML(title) + '</h2>';
        html += '<p class="abo-admin-pages__preview-frame-meta">URL: /' + escapeHTML(p.slug || '') + ' · ' + escapeHTML(p.status) + '</p>';
        // The body is the admin's own HTML — the same trusted input the
        // storefront renders, shown here so the preview matches it.
        html += body;
        previewFrame.innerHTML = html;
        previewModal.hidden = false;
    }

    function closePreview() {
        if (previewModal) previewModal.hidden = true;
    }

    function wirePreview() {
        if (!previewModal) return;
        $$('[data-abo-admin-pages-preview-close]', previewModal).forEach((b) => {
            b.addEventListener('click', closePreview);
        });
        document.addEventListener('keydown', (ev) => {
            if (previewModal && !previewModal.hidden && ev.key === 'Escape') {
                ev.preventDefault();
                closePreview();
            }
        });
    }

    // ─── Boot ─────────────────────────────────────────────────

    function boot() {
        wireListToolbar();
        wireListClicks();
        wireFields();
        wireActions();
        wirePreview();
        // Hide skeletons.
        const load = $('[data-abo-admin-pages-state="loading"]');
        if (load) load.hidden = true;
        renderList();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
