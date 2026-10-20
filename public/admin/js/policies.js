/*
 * ABO Admin · Policies — page-local script (front-end only).
 *
 * Hydrates the policies editor from the static JSON datasets
 * baked into policies.blade.php and wires:
 *   - tab navigation (mouse + ArrowLeft/ArrowRight + URL hash)
 *   - status pill + "Last updated" per tab
 *   - EN/AR language switch for title, content and SEO
 *   - rich-text toolbar (Bold, Italic, H2, H3, lists, link, undo, redo)
 *   - character / word counter below the editor
 *   - "Insert default template" button + confirm modal
 *   - unsaved-changes dirty dot on each tab
 *   - live preview (title, body + summary line built from rules)
 *   - SEO counters
 *   - toast on save ("Saved (demo)")
 *   - Esc closes the confirm modal
 *
 * No fetch / XHR / storage. The only persistence is the optional
 * `state` object that holds transient UI (current tab, dirty set,
 * per-editor undo/redo history) — it is reset on every page load.
 *
 * Markup anchor points (every selector uses data-* attributes):
 *   [data-abo-admin-policies-tablist]               — <div role="tablist">
 *   [data-abo-admin-policies-tab="{slug}"]          — one tab per policy
 *   [data-abo-admin-policies-tab-dirty="{slug}"]    — dirty dot
 *   [data-abo-admin-policies-status-pill]           — Published / Draft pill
 *   [data-abo-admin-policies-updated]               — "Last updated …"
 *   [data-abo-admin-policies-layout]                — panel container
 *   [data-abo-admin-policies-panel="{slug}"]        — one panel per policy
 *   [data-abo-admin-policies-skeleton]              — loading skeleton
 *   [data-abo-admin-policies-editor="{slug}"]       — editor card
 *   [data-abo-admin-policies-counter="{slug}"]      — word/char counter
 *   [data-abo-admin-policies-empty="{slug}"]        — empty placeholder
 *   [data-abo-admin-policies-template="{slug}"]     — insert-default button
 *   [data-abo-admin-policies-template-modal]        — confirm modal
 *   [data-abo-admin-policies-toast]                 — shared toast
 *   [data-abo-admin-policies-preview="{slug}"]      — live preview card
 *   [data-abo-admin-policies-tb="{cmd}"]            — toolbar button
 *
 * No external dependencies. Loaded only on /admin/pages/policies.
 */

(function () {
    'use strict';

    /* ─── Datasets (read once from the Blade view) ─────────────── */

    const root = document.querySelector('[data-abo-admin-policies-layout]');
    if (!root) return;

    const policies = readJson('abo-admin-policies-data', {});
    const templateTpl = readJson('abo-admin-policies-templates', {});
    const tabOrder = readJson('abo-admin-policies-tab-order',
        Object.keys(policies));

    if (!Array.isArray(tabOrder) || tabOrder.length === 0) return;

    /* ─── DOM lookups (after the layout is confirmed present) ─── */

    const tablist       = document.querySelector('[data-abo-admin-policies-tablist]');
    const statusPill    = document.querySelector('[data-abo-admin-policies-status-pill]');
    const updatedEl     = document.querySelector('[data-abo-admin-policies-updated]');
    const skeleton      = document.querySelector('[data-abo-admin-policies-skeleton]');
    const templateModal = document.querySelector('[data-abo-admin-policies-template-modal]');
    const templateBody  = document.querySelector('[data-abo-admin-policies-template-body]');
    const toast         = document.querySelector('[data-abo-admin-policies-toast]');
    const toastText     = document.querySelector('[data-abo-admin-policies-toast-text]');

    /* Per-policy DOM caches — populated on init. */
    const panels   = {};   // slug → section element
    const editors  = {};   // slug → editor card
    const titles   = {};   // slug → { en: input, ar: input }
    const contents = {};   // slug → { en: div, ar: div }
    const previews = {};   // slug → preview card
    const counters = {};   // slug → counter span
    const empty    = {};   // slug → empty-state paragraph

    tabOrder.forEach((slug) => {
        panels[slug]   = document.querySelector('[data-abo-admin-policies-panel="' + slug + '"]');
        editors[slug]  = document.querySelector('[data-abo-admin-policies-editor="' + slug + '"]');
        titles[slug]   = {
            en: document.querySelector('[data-abo-admin-policies-title="en"][data-abo-admin-policies-policy="' + slug + '"]'),
            ar: document.querySelector('[data-abo-admin-policies-title="ar"][data-abo-admin-policies-policy="' + slug + '"]'),
        };
        contents[slug] = {
            en: document.querySelector('[data-abo-admin-policies-content="en"][data-abo-admin-policies-policy="' + slug + '"]'),
            ar: document.querySelector('[data-abo-admin-policies-content="ar"][data-abo-admin-policies-policy="' + slug + '"]'),
        };
        previews[slug] = document.querySelector('[data-abo-admin-policies-preview="' + slug + '"]');
        counters[slug] = document.querySelector('[data-abo-admin-policies-counter="' + slug + '"]');
        empty[slug]    = document.querySelector('[data-abo-admin-policies-empty="' + slug + '"]');
    });

    /* ─── In-memory UI state ───────────────────────────────────── */

    const state = {
        active: tabOrder[0],
        activeTitleLang: 'en',   // for the active tab only
        activeSeoLang:   'en',
        dirty: Object.create(null), // slug → true
        pendingTemplate: null,       // slug when the confirm modal is open
        undo: Object.create(null),   // slug → html stack
        redo: Object.create(null),   // slug → html stack
    };

    /* ─── Helpers ───────────────────────────────────────────────── */

    function readJson(id, fallback) {
        const el = document.getElementById(id);
        if (!el) return fallback;
        try {
            const parsed = JSON.parse(el.textContent || 'null');
            return parsed == null ? fallback : parsed;
        } catch (_err) {
            return fallback;
        }
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function setText(el, text) {
        if (el) el.textContent = text;
    }

    function toggleAttr(el, attr, on) {
        if (!el) return;
        if (on) el.setAttribute(attr, '');
        else el.removeAttribute(attr);
    }

    function toggleHidden(el, on) {
        if (!el) return;
        el.hidden = !!on;
    }

    function setActive(btn, on) {
        if (!btn) return;
        btn.classList.toggle('is-active', !!on);
        btn.setAttribute('aria-selected', on ? 'true' : 'false');
        btn.tabIndex = on ? 0 : -1;
    }

    function getRules(slug) {
        return (policies[slug] && policies[slug].rules) || {};
    }

    /* ─── Status pill + "Last updated" ──────────────────────────── */

    function formatDate(iso) {
        // ISO 8601 → "8 Oct 2026 · 13:22" in en-GB (matches the rest of admin)
        if (!iso) return '—';
        const d = new Date(iso);
        if (isNaN(d.getTime())) return '—';
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
                        'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const day   = d.getDate();
        const month = months[d.getMonth()];
        const year  = d.getFullYear();
        const hh    = String(d.getHours()).padStart(2, '0');
        const mm    = String(d.getMinutes()).padStart(2, '0');
        return day + ' ' + month + ' ' + year + ' · ' + hh + ':' + mm;
    }

    function refreshStatus(slug) {
        const p = policies[slug];
        if (!p) return;
        const isPub = p.status === 'published';
        if (statusPill) {
            statusPill.textContent = isPub ? 'Published' : 'Draft';
            statusPill.classList.toggle('abo-admin-policies__status--published', isPub);
            statusPill.classList.toggle('abo-admin-policies__status--draft', !isPub);
        }
        if (updatedEl) {
            updatedEl.textContent = 'Last updated ' + formatDate(p.updated);
        }
    }

    /* ─── Tab switching (mouse + arrow keys + hash) ─────────────── */

    function activate(slug, opts) {
        if (!panels[slug]) return;
        state.active = slug;
        state.activeTitleLang = 'en';
        state.activeSeoLang = 'en';

        tablist.querySelectorAll('[data-abo-admin-policies-tab]').forEach((tab) => {
            setActive(tab, tab.getAttribute('data-abo-admin-policies-tab') === slug);
        });

        tabOrder.forEach((s) => {
            toggleHidden(panels[s], s !== slug);
        });

        refreshStatus(slug);
        syncLangButtons(slug, 'title');
        syncLangButtons(slug, 'seo');

        // Sync URL hash without scrolling.
        if (!opts || opts.updateHash !== false) {
            const target = '#' + slug;
            if (window.location.hash !== target) {
                history.replaceState(null, '', target);
            }
        }

        // Focus the tab for keyboard users.
        const btn = tablist.querySelector('[data-abo-admin-policies-tab="' + slug + '"]');
        if (btn && opts && opts.focus) btn.focus();
    }

    if (tablist) {
        tablist.addEventListener('click', (e) => {
            const tab = e.target.closest('[data-abo-admin-policies-tab]');
            if (!tab) return;
            const slug = tab.getAttribute('data-abo-admin-policies-tab');
            activate(slug);
        });

        tablist.addEventListener('keydown', (e) => {
            const key = e.key;
            if (key !== 'ArrowLeft' && key !== 'ArrowRight' &&
                key !== 'Home' && key !== 'End') return;

            e.preventDefault();
            const idx = tabOrder.indexOf(state.active);
            let next = idx;
            if (key === 'ArrowLeft') next = (idx - 1 + tabOrder.length) % tabOrder.length;
            if (key === 'ArrowRight') next = (idx + 1) % tabOrder.length;
            if (key === 'Home') next = 0;
            if (key === 'End')  next = tabOrder.length - 1;
            activate(tabOrder[next], { focus: true });
        });
    }

    // Pick initial tab from hash (if recognised), else first tab.
    function slugFromHash() {
        const raw = (window.location.hash || '').replace(/^#/, '').trim();
        return tabOrder.indexOf(raw) !== -1 ? raw : tabOrder[0];
    }

    window.addEventListener('hashchange', () => {
        activate(slugFromHash(), { updateHash: false, focus: false });
    });

    /* ─── EN / AR language switch (title + content + SEO) ──────── */

    function syncLangButtons(slug, scope) {
        const sel = '[data-abo-admin-policies-lang-scope="' + scope + '"]' +
                    '[data-abo-admin-policies-policy="' + slug + '"]';
        document.querySelectorAll(sel).forEach((b) => {
            const lang = b.getAttribute('data-abo-admin-policies-lang');
            const isOn = (scope === 'title'
                ? state.activeTitleLang
                : state.activeSeoLang) === lang;
            b.classList.toggle('is-active', isOn);
            b.setAttribute('aria-selected', isOn ? 'true' : 'false');
        });

        if (scope === 'title' && titles[slug]) {
            toggleHidden(titles[slug].en, state.activeTitleLang !== 'en');
            toggleHidden(titles[slug].ar, state.activeTitleLang !== 'ar');
            if (contents[slug]) {
                toggleHidden(contents[slug].en, state.activeTitleLang !== 'en');
                toggleHidden(contents[slug].ar, state.activeTitleLang !== 'ar');
            }
        }

        if (scope === 'seo') {
            const enFields = document.querySelector(
                '[data-abo-admin-policies-seo-fields="en"][data-abo-admin-policies-policy="' + slug + '"]');
            const arFields = document.querySelector(
                '[data-abo-admin-policies-seo-fields="ar"][data-abo-admin-policies-policy="' + slug + '"]');
            toggleHidden(enFields, state.activeSeoLang !== 'en');
            toggleHidden(arFields, state.activeSeoLang !== 'ar');
        }
    }

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-abo-admin-policies-lang-scope]');
        if (!btn) return;
        const scope  = btn.getAttribute('data-abo-admin-policies-lang-scope');
        const lang   = btn.getAttribute('data-abo-admin-policies-lang');
        const pSlug  = btn.getAttribute('data-abo-admin-policies-policy');
        if (scope === 'title' && pSlug === state.active) state.activeTitleLang = lang;
        if (scope === 'seo'   && pSlug === state.active) state.activeSeoLang   = lang;
        syncLangButtons(state.active, scope);
        refreshPreview(state.active);
    });

    /* ─── Rich-text toolbar ────────────────────────────────────── */

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-abo-admin-policies-tb]');
        if (!btn) return;
        e.preventDefault();

        const cmd   = btn.getAttribute('data-abo-admin-policies-tb');
        const slug  = btn.getAttribute('data-abo-admin-policies-policy');
        const lang  = state.activeTitleLang;
        const ed    = contents[slug] && contents[slug][lang];
        if (!ed) return;

        ed.focus();
        pushUndo(slug, ed);

        try {
            if (cmd === 'bold')   document.execCommand('bold');
            else if (cmd === 'italic') document.execCommand('italic');
            else if (cmd === 'h2') {
                document.execCommand('formatBlock', false, 'H2');
            }
            else if (cmd === 'h3') {
                document.execCommand('formatBlock', false, 'H3');
            }
            else if (cmd === 'ul') document.execCommand('insertUnorderedList');
            else if (cmd === 'ol') document.execCommand('insertOrderedList');
            else if (cmd === 'link') {
                const url = window.prompt('Link URL', 'https://');
                if (url && url !== 'https://') {
                    document.execCommand('createLink', false, url);
                }
            }
            else if (cmd === 'undo') doUndo(slug, ed);
            else if (cmd === 'redo') doRedo(slug, ed);
        } catch (_err) { /* execCommand not supported: ignore */ }

        afterEdit(slug, lang);
    });

    function pushUndo(slug, ed) {
        const stack = state.undo[slug] = state.undo[slug] || [];
        stack.push(ed.innerHTML);
        if (stack.length > 50) stack.shift();
        state.redo[slug] = [];
    }

    function doUndo(slug, ed) {
        const stack = state.undo[slug];
        if (!stack || !stack.length) return;
        const redoStack = state.redo[slug] = state.redo[slug] || [];
        redoStack.push(ed.innerHTML);
        ed.innerHTML = stack.pop();
    }

    function doRedo(slug, ed) {
        const stack = state.redo[slug];
        if (!stack || !stack.length) return;
        const undoStack = state.undo[slug] = state.undo[slug] || [];
        undoStack.push(ed.innerHTML);
        ed.innerHTML = stack.pop();
    }

    /* ─── Dirty tracking + counter + empty state + preview ─────── */

    function countWords(text) {
        const cleaned = String(text || '').replace(/<[^>]*>/g, ' ')
                                          .replace(/&nbsp;/g, ' ')
                                          .replace(/\s+/g, ' ')
                                          .trim();
        if (!cleaned) return 0;
        return cleaned.split(' ').length;
    }

    function plainText(html) {
        const tmp = document.createElement('div');
        tmp.innerHTML = html || '';
        return tmp.textContent || '';
    }

    function refreshCounter(slug) {
        const lang = state.activeTitleLang;
        const ed = contents[slug] && contents[slug][lang];
        const el = counters[slug];
        if (!ed || !el) return;
        const text = ed.innerText || ed.textContent || '';
        const words = countWords(ed.innerHTML);
        const chars = text.length;
        el.textContent = words + ' word' + (words === 1 ? '' : 's') + ' · ' + chars + ' character' + (chars === 1 ? '' : 's');
    }

    function refreshEmpty(slug) {
        const lang = state.activeTitleLang;
        const ed = contents[slug] && contents[slug][lang];
        const el = empty[slug];
        if (!ed || !el) return;
        const text = plainText(ed.innerHTML).trim();
        toggleHidden(el, text.length > 0);
    }

    function setDirty(slug, on) {
        state.dirty[slug] = !!on;
        const dot = document.querySelector(
            '[data-abo-admin-policies-tab-dirty="' + slug + '"]');
        toggleHidden(dot, !on);
    }

    function refreshPreview(slug) {
        const p = policies[slug];
        const card = previews[slug];
        if (!p || !card) return;
        const lang = state.activeTitleLang;

        const titleEl   = card.querySelector('[data-abo-admin-policies-preview-title]');
        const bodyEl    = card.querySelector('[data-abo-admin-policies-preview-body]');
        const summaryEl = card.querySelector('[data-abo-admin-policies-preview-summary]');

        const liveTitle = titles[slug] && titles[slug][lang];
        if (titleEl && liveTitle) titleEl.textContent = liveTitle.value;

        const liveEd = contents[slug] && contents[slug][lang];
        if (bodyEl && liveEd) bodyEl.innerHTML = liveEd.innerHTML;

        if (summaryEl) summaryEl.textContent = buildSummary(slug);

        // Update the preview card's dir/lang to match.
        if (lang === 'ar') {
            card.setAttribute('dir', 'rtl');
            card.setAttribute('lang', 'ar');
        } else {
            card.removeAttribute('dir');
            card.removeAttribute('lang');
        }
        card.setAttribute('data-abo-admin-policies-preview-lang', lang);
    }

    function buildSummary(slug) {
        const r = getRules(slug);
        const t = (policies[slug] && policies[slug].title) || {};
        const enTitle = (t.en || '').toLowerCase();

        if (slug === 'shipping') {
            const from = r.deliveryMinDays;
            const to   = r.deliveryMaxDays;
            if (typeof from === 'number' && typeof to === 'number' && from > 0 && to >= from) {
                const base = 'Estimated delivery: ' + from + (from === to ? '' : '–' + to) + ' days.';
                return r.freeShippingEnabled && r.freeShippingThreshold
                    ? base + ' Free shipping over EGP ' + r.freeShippingThreshold + '.'
                    : base;
            }
            return 'Delivery options and cut-off times.';
        }

        if (slug === 'returns') {
            if (!r.acceptReturns) return 'Returns are not currently accepted.';
            const w = r.windowDays;
            if (typeof w === 'number' && w > 0) {
                const who = r.returnShippingPaidBy === 'store' ? 'Store' : 'Customer';
                return 'Returns accepted within ' + w + ' days. ' + who + ' pays return shipping.';
            }
            return 'Returns accepted.';
        }

        if (slug === 'exchange') {
            if (!r.acceptExchange) return 'Exchanges are not currently accepted.';
            const w = r.windowDays;
            const same = r.sameProductOnly ? 'same product' : 'any product';
            const diff = r.priceDifference === 'store-credit' ? 'store credit' : 'paid by the customer';
            if (typeof w === 'number' && w > 0) {
                return 'Exchanges accepted within ' + w + ' days for ' + same + '. Price difference: ' + diff + '.';
            }
            return 'Exchanges accepted for ' + same + '.';
        }

        return enTitle ? ('Summary for ' + enTitle + '.') : '—';
    }

    function afterEdit(slug, lang) {
        refreshCounter(slug);
        refreshEmpty(slug);
        refreshPreview(slug);
        setDirty(slug, true);
    }

    /* Wire input events on titles + contenteditable. */
    tabOrder.forEach((slug) => {
        ['en', 'ar'].forEach((lang) => {
            const tEl = titles[slug] && titles[slug][lang];
            if (tEl) tEl.addEventListener('input', () => {
                setDirty(slug, true);
                refreshPreview(slug);
            });

            const cEl = contents[slug] && contents[slug][lang];
            if (cEl) {
                cEl.addEventListener('input', () => {
                    refreshCounter(slug);
                    refreshEmpty(slug);
                    setDirty(slug, true);
                    refreshPreview(slug);
                });
                cEl.addEventListener('blur', () => {
                    pushUndo(slug, cEl);
                });
            }
        });

        // Initial counter / empty / preview render.
        refreshCounter(slug);
        refreshEmpty(slug);
        refreshPreview(slug);
    });

    /* ─── Toggles, switches, checklist, selects → dirty + preview */

    document.addEventListener('change', (e) => {
        const el = e.target;
        if (!el || !el.matches(
            '[data-abo-admin-policies-field], [data-abo-admin-policies-toggle] input, [data-abo-admin-policies-checklist] input, [data-abo-admin-policies-field]'
        )) return;

        // Toggle aria-checked sync (visual toggle uses aria-checked too).
        if (el.matches('[data-abo-admin-policies-toggle] input')) {
            el.setAttribute('aria-checked', el.checked ? 'true' : 'false');
        }

        // Field path → memory.
        const path = el.getAttribute('data-abo-admin-policies-field');
        const slug = el.getAttribute('data-abo-admin-policies-policy');
        if (path && slug && policies[slug]) {
            setDeep(policies[slug], path, el.type === 'checkbox' ? el.checked : el.value);
        }

        if (slug) {
            setDirty(slug, true);
            refreshPreview(slug);
        }

        // SEO counters
        if (el.matches('[data-abo-admin-policies-seo-counter-input]')) {
            const name = el.getAttribute('data-abo-admin-policies-seo-counter-name');
            const max  = Number(el.getAttribute('data-abo-admin-policies-seo-counter-max') || 0);
            const cur  = (el.value || '').length;
            const tag  = document.querySelector('[data-abo-admin-policies-seo-counter="' + name + '"]');
            if (tag) {
                tag.textContent = cur + ' / ' + max;
                tag.classList.toggle('abo-admin-policies__counter--coral', max > 0 && cur > max);
            }
        }
    });

    function setDeep(obj, path, value) {
        const parts = path.split('.');
        let o = obj;
        for (let i = 0; i < parts.length - 1; i++) {
            const k = parts[i];
            if (o[k] == null || typeof o[k] !== 'object') o[k] = {};
            o = o[k];
        }
        o[parts[parts.length - 1]] = value;
    }

    /* Initial SEO counters (from pre-rendered meta values). */
    document.querySelectorAll('[data-abo-admin-policies-seo-counter-input]').forEach((el) => {
        el.dispatchEvent(new Event('change', { bubbles: true }));
    });

    /* ─── Insert default template ───────────────────────────────── */

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-abo-admin-policies-template]');
        if (!btn) return;
        const slug = btn.getAttribute('data-abo-admin-policies-template');
        if (!templateTpl[slug]) return;

        const lang  = state.activeTitleLang;
        const ed    = contents[slug] && contents[slug][lang];
        const text  = plainText(ed ? ed.innerHTML : '').trim();

        if (text.length === 0) {
            applyTemplate(slug, lang);
            return;
        }

        state.pendingTemplate = { slug, lang };
        if (templateBody) {
            templateBody.textContent =
                'This will replace the current ' + (policies[slug].label) +
                ' content with the default template. You can edit it afterwards.';
        }
        openModal(templateModal);
    });

    function applyTemplate(slug, lang) {
        const html = templateTpl[slug];
        const ed = contents[slug] && contents[slug][lang];
        if (!ed || !html) return;
        pushUndo(slug, ed);
        ed.innerHTML = html;
        afterEdit(slug, lang);
    }

    document.querySelectorAll('[data-abo-admin-policies-template-confirm]').forEach((b) => {
        b.addEventListener('click', () => {
            if (state.pendingTemplate) {
                applyTemplate(state.pendingTemplate.slug, state.pendingTemplate.lang);
            }
            closeModal(templateModal);
            state.pendingTemplate = null;
        });
    });

    document.querySelectorAll('[data-abo-admin-policies-template-close]').forEach((b) => {
        b.addEventListener('click', () => {
            closeModal(templateModal);
            state.pendingTemplate = null;
        });
    });

    /* ─── Modal helpers + Esc ───────────────────────────────────── */

    function openModal(modal) {
        if (!modal) return;
        modal.hidden = false;
        // Focus first focusable inside the dialog.
        const f = modal.querySelector('button, [href], input, select, textarea');
        if (f) setTimeout(() => f.focus(), 0);
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.hidden = true;
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && templateModal && !templateModal.hidden) {
            closeModal(templateModal);
            state.pendingTemplate = null;
        }
    });

    /* ─── Toast ─────────────────────────────────────────────────── */

    let toastTimer = null;
    function showToast(msg) {
        if (!toast) return;
        if (toastText && msg) toastText.textContent = msg;
        toast.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { toast.hidden = true; }, 2200);
    }

    /* ─── Top buttons (Discard / Save as draft / Save and publish) */

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-abo-admin-policies-action]');
        if (!btn) return;
        const action = btn.getAttribute('data-abo-admin-policies-action');
        const slug = state.active;
        const p = policies[slug];
        if (!p) return;

        if (action === 'save-draft') {
            p.status = 'draft';
            refreshStatus(slug);
            setDirty(slug, false);
            showToast('Draft saved (demo)');
        }
        else if (action === 'save-publish') {
            p.status = 'published';
            refreshStatus(slug);
            setDirty(slug, false);
            showToast('Saved (demo)');
        }
        else if (action === 'discard') {
            // Reset titles + content + rules to the static demo data.
            tabOrder.forEach((s) => {
                ['en', 'ar'].forEach((lang) => {
                    const t = titles[s] && titles[s][lang];
                    const c = contents[s] && contents[s][lang];
                    if (t) t.value = (policies[s].title && policies[s].title[lang]) || '';
                    // We don't have a "pristine HTML" cache so we keep current.
                });
                setDirty(s, false);
                refreshCounter(s);
                refreshEmpty(s);
                refreshPreview(s);
            });
            showToast('Discarded (demo)');
        }
    });

    /* ─── Boot ──────────────────────────────────────────────────── */

    // Hide skeleton, show layout, activate tab from hash.
    function boot() {
        if (skeleton) skeleton.hidden = true;
        if (root) root.hidden = false;

        const initial = slugFromHash();
        activate(initial, { updateHash: false, focus: false });

        // Refresh SEO counters (input value already there, but events were
        // dispatched before layout was unhidden so tags exist already).
        document.querySelectorAll('[data-abo-admin-policies-seo-counter-input]').forEach((el) => {
            el.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }

    // Defer one frame so the layout is visible before the skeleton hides.
    requestAnimationFrame(boot);
})();