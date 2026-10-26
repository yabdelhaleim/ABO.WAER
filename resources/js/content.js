// ABO — Content pages front-end module.
//
// Scope (per spec §0):
//   - No backend, no network, no storage. All page data and the
//     page-template settings come from content.mock.js through two
//     wrappers: `getPage(slug)` and `getSettings()`. Replacing them
//     with real API calls later is a one-line change.
//   - The page template (content.blade.php) renders a single shell
//     for all 5 pages. JS hydrates the page from `getPage(slug)`.
//   - The contact form is UI only: client-side validation + success
//     state. It sends nothing anywhere.
//   - All text fields support the inline subset defined in spec §4
//     (`**bold**`, `*italic*`, `[label](url)`, `{{vars}}`); the
//     parser builds DOM nodes — raw HTML is never injected.
//
// Self-gates on `.content` like every other module in resources/js
// (orders.js, cart.js, checkout.js, …).

import { PAGES, SETTINGS } from './content.mock.js';

// ─── State ────────────────────────────────────────────────────────

/**
 * The CSRF token the Blade layout puts in the document head. Needed by
 * the contact form because it posts through `fetch` rather than a
 * native form submit.
 */
function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

const state = {
    settings: { ...SETTINGS },
    page: null,
    dev: false,
    forceState: null, // 'loading' | 'notfound' | 'empty' | 'draft' | null
    preview: false,
};

// ─── Single-source-of-truth data wrappers ─────────────────────────

/**
 * Returns the page object for the given slug, or null if it does
 * not exist.
 *
 * The page is rendered by the server from the `pages` table, so the
 * copy a customer reads is whatever the admin last saved. This reads
 * that rendered page back out of the DOM and hands it to the template
 * as a single HTML block.
 *
 * A page with no saved copy yet falls back to the bundled mock, so a
 * freshly created page still has a designed layout instead of a blank
 * screen. As soon as the admin saves, the saved copy wins.
 */
export async function getPage(slug) {
    await Promise.resolve();

    const titleEl = $('.content__title');
    const bodyEl = $('.content__body');

    if (titleEl && bodyEl) {
        const html = bodyEl.innerHTML.trim();
        if (html !== '') {
            return {
                slug,
                group: 'info',
                title: titleEl.textContent.trim(),
                summary: '',
                updatedAt: '',
                status: 'published',
                blocks: [{ type: 'html', html }],
            };
        }
    }

    const page = PAGES[slug] ?? null;
    return page ? structuredClone(page) : null;
}

/**
 * Returns the page-template settings. Today this returns the
 * static mock. A real backend can replace this with a `fetch()`
 * call without touching any view, component, or styling.
 */
export async function getSettings() {
    await Promise.resolve();
    return { ...state.settings };
}

/**
 * Internal: writes the new settings so subsequent calls to
 * `getSettings()` and the live variable resolver see the update.
 * The dev panel is the only caller.
 */
function setSettings(next) {
    state.settings = { ...state.settings, ...next };
}

// ─── Tiny DOM helpers (matches per-file convention in orders.js) ─

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

function el(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
        if (v == null || v === false) continue;
        if (k === 'class') node.className = v;
        else if (k === 'html') node.innerHTML = v;
        else if (k === 'text') node.textContent = v;
        else if (k === 'dataset') {
            for (const [dk, dv] of Object.entries(v)) node.dataset[dk] = dv;
        }
        else if (k === 'novalidate' || k === 'noValidate') {
            node.setAttribute('novalidate', '');
        }
        else if (k.startsWith('aria-') || k === 'role' || k === 'rel' || k === 'href' || k === 'src' || k === 'alt' || k === 'id' || k === 'type' || k === 'name' || k === 'for' || k === 'placeholder' || k === 'value' || k === 'autocomplete' || k === 'title' || k === 'lang' || k === 'inputmode' || k === 'required' || k === 'disabled' || k === 'multiple' || k === 'checked' || k === 'selected' || k === 'readonly' || k === 'hidden' || k === 'open') {
            node.setAttribute(k, v === true ? '' : v);
        }
        else node[k] = v;
    }
    for (const c of [].concat(children)) {
        if (c == null) continue;
        node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    }
    return node;
}

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
    ));
}

function escapeAttr(s) {
    return escapeHtml(s);
}

// ─── Variables (spec §5) ──────────────────────────────────────────

const VAR_NAMES = {
    free_shipping: () => `${Number(state.settings.freeShippingThreshold).toLocaleString('en-EG')} ${state.settings.currency}`,
    return_days: () => String(state.settings.returnDays),
    exchange_days: () => String(state.settings.exchangeDays),
};

function resolveVariables(text) {
    if (typeof text !== 'string' || !text.includes('{{')) return text;
    return text.replace(/\{\{\s*([a-z0-9_]+)\s*\}\}/gi, (match, name) => {
        const fn = VAR_NAMES[name];
        if (fn) return fn();
        if (import.meta.env && import.meta.env.DEV) {
            // eslint-disable-next-line no-console
            console.warn(`[content] unknown variable: ${match}`);
        }
        return match;
    });
}

// ─── Inline text parser (spec §4) ────────────────────────────────
//
// Supports: **bold**, *italic*, [label](url), {{vars}}.
// Never injects raw HTML — every text run goes through textContent
// and links are validated before href is set.

const INLINE_TOKEN = /(\*\*[^*]+\*\*|\*[^*]+\*|\[[^\]]+\]\([^)]+\)|\{\{[a-z0-9_]+\}\})/gi;
const URL_OK = /^(\/|#|https?:\/\/)/i;

function parseInline(text) {
    const resolved = resolveVariables(text);
    const parts = String(resolved).split(INLINE_TOKEN).filter((p) => p != null && p !== '');
    const frag = document.createDocumentFragment();
    for (const part of parts) {
        if (part.startsWith('**') && part.endsWith('**')) {
            frag.appendChild(el('strong', {}, part.slice(2, -2)));
        } else if (part.startsWith('*') && part.endsWith('*')) {
            frag.appendChild(el('em', {}, part.slice(1, -1)));
        } else if (part.startsWith('[')) {
            const m = part.match(/^\[([^\]]+)\]\(([^)]+)\)$/);
            if (m) {
                const [, label, url] = m;
                if (URL_OK.test(url.trim())) {
                    const isExternal = /^https?:\/\//i.test(url.trim());
                    const attrs = { href: url.trim() };
                    if (isExternal) {
                        attrs.rel = 'noopener noreferrer';
                        attrs.target = '_self';
                    }
                    frag.appendChild(el('a', attrs, label));
                } else {
                    frag.appendChild(document.createTextNode(part));
                }
            } else {
                frag.appendChild(document.createTextNode(part));
            }
        } else {
            frag.appendChild(document.createTextNode(part));
        }
    }
    return frag;
}

function appendInline(parent, text) {
    parent.appendChild(parseInline(text));
}

// ─── Heading + anchor ids ───────────────────────────────────────

function slugify(text) {
    return String(text ?? '')
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 60);
}

const usedIds = new Set();
function uniqueId(base) {
    let id = base || 'block';
    let n = 1;
    while (usedIds.has(id)) id = `${base}-${++n}`;
    usedIds.add(id);
    return id;
}

// H2 with a small skewed lime bar before the text (spec §1).
function renderHeading(text, anchorId) {
    const wrap = el('div', { class: 'content__block-heading', id: anchorId });
    wrap.appendChild(el('span', { class: 'content__heading-bar', 'aria-hidden': 'true' }));
    const h2 = el('h2', { class: 'content__h2' });
    appendInline(h2, text);
    wrap.appendChild(h2);
    return wrap;
}

// ─── Block library (13 types) ────────────────────────────────────

function buildStats(block) {
    const grid = el(
        'div',
        { class: 'content__stats', role: 'list' },
        block.items.map((it) => {
            const cell = el('div', { class: 'content__stat', role: 'listitem' });
            const v = el('span', { class: 'content__stat-value' });
            appendInline(v, it.value);
            const l = el('span', { class: 'content__stat-label' }, it.label);
            cell.appendChild(v);
            cell.appendChild(l);
            return cell;
        }),
    );
    return grid;
}

function buildText(block) {
    const wrap = el('div', { class: 'content__text' });
    for (const p of block.paragraphs || []) {
        const pEl = el('p', { class: 'content__paragraph' });
        appendInline(pEl, p);
        wrap.appendChild(pEl);
    }
    if (block.list && block.list.length) {
        const ul = el('ul', { class: 'content__list' });
        for (const item of block.list) {
            const li = el('li', { class: 'content__list-item' });
            appendInline(li, item);
            ul.appendChild(li);
        }
        wrap.appendChild(ul);
    }
    return wrap;
}

function buildSteps(block) {
    const grid = el('div', { class: 'content__steps', role: 'list' });
    let i = 0;
    for (const it of block.items || []) {
        i += 1;
        const card = el('div', { class: 'content__step', role: 'listitem' });
        card.appendChild(el('span', { class: 'content__step-number', 'aria-hidden': 'true' }, String(i).padStart(2, '0')));
        const title = el('h3', { class: 'content__step-title' });
        appendInline(title, it.title);
        card.appendChild(title);
        const desc = el('p', { class: 'content__step-desc' });
        appendInline(desc, it.description);
        card.appendChild(desc);
        grid.appendChild(card);
    }
    return grid;
}

function buildTable(block) {
    const wrap = el('div', { class: 'content__table-wrap' });
    const t = el('table', { class: 'content__table' });
    const thead = el('thead', {}, el('tr', {}, (block.columns || []).map((c) => el('th', { scope: 'col' }, c))));
    const tbody = el('tbody');
    for (const row of block.rows || []) {
        const tr = el('tr');
        for (const cell of row) {
            const td = el('td', {});
            appendInline(td, cell);
            tr.appendChild(td);
        }
        tbody.appendChild(tr);
    }
    t.appendChild(thead);
    t.appendChild(tbody);
    wrap.appendChild(t);
    if (block.footnote) {
        const f = el('p', { class: 'content__table-footnote' });
        appendInline(f, block.footnote);
        wrap.appendChild(f);
    }
    return wrap;
}

function buildNote(block) {
    const note = el('aside', { class: 'content__note', role: 'note' });
    const title = el('p', { class: 'content__note-title' }, block.title);
    const body = el('p', { class: 'content__note-body' });
    appendInline(body, block.body);
    note.appendChild(title);
    note.appendChild(body);
    return note;
}

function buildFaq(block) {
    const list = el('div', { class: 'content__faq' });
    for (const it of block.items || []) {
        const det = el('details', { class: 'content__faq-item' });
        const sum = el('summary', { class: 'content__faq-summary' });
        const q = el('span', { class: 'content__faq-q' });
        appendInline(q, it.question);
        const icon = el('span', { class: 'content__faq-icon', 'aria-hidden': 'true' });
        sum.appendChild(q);
        sum.appendChild(icon);
        const ans = el('div', { class: 'content__faq-a' });
        appendInline(ans, it.answer);
        det.appendChild(sum);
        det.appendChild(ans);
        list.appendChild(det);
    }
    return list;
}

function buildHero(block) {
    const hero = el('section', { class: 'content__hero' });
    hero.appendChild(el('span', { class: 'content__hero-line', 'aria-hidden': 'true' }));
    const text = el('div', { class: 'content__hero-text' });
    const h = el('h2', { class: 'content__hero-headline' });
    appendInline(h, block.headline);
    text.appendChild(h);
    const body = el('p', { class: 'content__hero-body' });
    appendInline(body, block.body);
    text.appendChild(body);
    if (block.cta && block.cta.label && block.cta.href && URL_OK.test(block.cta.href)) {
        const cta = el('a', { class: 'content__btn content__btn--primary', href: block.cta.href }, block.cta.label + ' →');
        text.appendChild(cta);
    }
    hero.appendChild(text);

    if (block.image && block.image.src) {
        const imgWrap = el('div', { class: 'content__hero-image' });
        imgWrap.appendChild(el('img', { src: block.image.src, alt: block.image.alt || '', loading: 'lazy' }));
        hero.appendChild(imgWrap);
    } else {
        const placeholder = el('div', { class: 'content__hero-image content__hero-image--placeholder', 'aria-hidden': 'true' });
        placeholder.appendChild(el('span', { class: 'content__hero-watermark' }, 'ABO'));
        hero.appendChild(placeholder);
    }
    return hero;
}

function buildValues(block) {
    const grid = el('div', { class: 'content__values', role: 'list' });
    for (const it of block.items || []) {
        const card = el('div', { class: 'content__value', role: 'listitem' });
        card.appendChild(el('span', { class: 'content__value-number', 'aria-hidden': 'true' }, it.number));
        const t = el('h3', { class: 'content__value-title' });
        appendInline(t, it.title);
        card.appendChild(t);
        const d = el('p', { class: 'content__value-desc' });
        appendInline(d, it.description);
        card.appendChild(d);
        grid.appendChild(card);
    }
    return grid;
}

function buildTimeline(block) {
    const wrap = el('ol', { class: 'content__timeline' });
    for (const it of block.items || []) {
        const li = el('li', { class: 'content__timeline-item' });
        const label = el('span', { class: 'content__timeline-label', 'aria-hidden': 'true' }, it.label);
        const dot = el('span', { class: 'content__timeline-dot', 'aria-hidden': 'true' });
        const body = el('div', { class: 'content__timeline-body' });
        const t = el('h3', { class: 'content__timeline-title' });
        appendInline(t, it.title);
        const d = el('p', { class: 'content__timeline-desc' });
        appendInline(d, it.description);
        body.appendChild(t);
        body.appendChild(d);
        li.appendChild(label);
        li.appendChild(dot);
        li.appendChild(body);
        wrap.appendChild(li);
    }
    return wrap;
}

function buildCards(block) {
    const grid = el('div', { class: 'content__cards', role: 'list' });
    for (const it of block.items || []) {
        const safeHref = URL_OK.test(String(it.href || '')) ? it.href : null;
        const attrs = { class: 'content__card', role: 'listitem' };
        if (safeHref) {
            attrs.href = safeHref;
            if (/^https?:\/\//i.test(safeHref)) attrs.rel = 'noopener noreferrer';
        }
        const card = el('a', attrs);
        const label = el('span', { class: 'content__card-label' }, it.label);
        const value = el('span', { class: 'content__card-value' });
        appendInline(value, it.value);
        const desc = el('span', { class: 'content__card-desc' });
        appendInline(desc, it.description);
        card.appendChild(label);
        card.appendChild(value);
        card.appendChild(desc);
        if (safeHref) card.appendChild(el('span', { class: 'content__card-arrow', 'aria-hidden': 'true' }, '→'));
        grid.appendChild(card);
    }
    return grid;
}

function buildForm(block) {
    const form = el('form', { class: 'content__form', novalidate: true });
    form.appendChild(el('input', { type: 'hidden', name: 'topics_catalog', value: JSON.stringify(block.topics || []) }));

    function field(name, label, control) {
        const wrap = el('div', { class: 'content__field', dataset: { field: name } });
        const lab = el('label', { class: 'content__label', for: `content-${name}` }, label);
        wrap.appendChild(lab);
        wrap.appendChild(control);
        const err = el('p', { class: 'content__field-error', 'aria-live': 'polite', id: `content-${name}-err` });
        wrap.appendChild(err);
        return wrap;
    }

    form.appendChild(field('name', 'Name', el('input', {
        class: 'content__input', type: 'text', name: 'name', id: 'content-name',
        autocomplete: 'name', required: true, maxlength: 100,
    })));

    form.appendChild(field('email', 'Email', el('input', {
        class: 'content__input', type: 'email', name: 'email', id: 'content-email',
        autocomplete: 'email', required: true, maxlength: 200,
    })));

    const select = el('select', { class: 'content__input', name: 'topic', id: 'content-topic', required: true });
    select.appendChild(el('option', { value: '' }, 'Choose a topic…'));
    for (const t of block.topics || []) select.appendChild(el('option', { value: t }, t));
    form.appendChild(field('topic', 'Topic', select));

    form.appendChild(field('order', 'Order number (optional)', el('input', {
        class: 'content__input', type: 'text', name: 'order', id: 'content-order',
        placeholder: 'ABO-10482', autocomplete: 'off', maxlength: 40,
    })));

    const textarea = el('textarea', {
        class: 'content__input content__textarea', name: 'message', id: 'content-message',
        rows: 5, required: true, maxlength: 2000, minlength: 10,
    });
    form.appendChild(field('message', 'Message', textarea));

    const actions = el('div', { class: 'content__form-actions' });
    const submit = el('button', { class: 'content__btn content__btn--primary', type: 'submit' }, 'Send message →');
    actions.appendChild(submit);
    form.appendChild(actions);

    form.addEventListener('submit', (ev) => onFormSubmit(ev, form));
    return form;
}

function onFormSubmit(ev, form) {
    ev.preventDefault();
    const data = new FormData(form);
    const name = String(data.get('name') || '').trim();
    const email = String(data.get('email') || '').trim();
    const topic = String(data.get('topic') || '').trim();
    const order = String(data.get('order') || '').trim();
    const message = String(data.get('message') || '').trim();
    const errors = {};

    if (!name) errors.name = 'Please enter your name.';
    if (!email) errors.email = 'Please enter your email.';
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errors.email = 'Please enter a valid email address.';
    if (!topic) errors.topic = 'Please pick a topic.';
    if (message.length < 10) errors.message = 'Message must be at least 10 characters.';

    // Clear + repaint errors
    for (const name of ['name', 'email', 'topic', 'order', 'message']) {
        const wrap = form.querySelector(`[data-field="${name}"]`);
        if (!wrap) continue;
        wrap.classList.remove('is-error');
        const input = wrap.querySelector('input,select,textarea');
        if (input) input.removeAttribute('aria-invalid');
        const err = wrap.querySelector('.content__field-error');
        if (err) err.textContent = '';
    }
    let firstInvalid = null;
    for (const [field, msg] of Object.entries(errors)) {
        const wrap = form.querySelector(`[data-field="${field}"]`);
        if (!wrap) continue;
        wrap.classList.add('is-error');
        const input = wrap.querySelector('input,select,textarea');
        if (input) {
            input.setAttribute('aria-invalid', 'true');
            input.setAttribute('aria-describedby', `content-${field}-err`);
        }
        const err = wrap.querySelector('.content__field-error');
        if (err) err.textContent = msg;
        if (!firstInvalid) firstInvalid = input;
    }
    if (firstInvalid) {
        firstInvalid.focus();
        return;
    }

    const submit = form.querySelector('button[type="submit"]');
    if (submit) {
        submit.disabled = true;
        submit.setAttribute('data-loading', 'true');
    }

    // The optional order number is not a column of its own — fold it
    // into the message body so the admin inbox still sees it.
    const body = order ? `${message}\n\nOrder number: ${order}` : message;

    const payload = new FormData(form);
    payload.set('message', body);
    payload.delete('order');

    fetch('/contact', {
        method: 'POST',
        body: payload,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrfToken(),
        },
    })
        .then(async (response) => {
            const payload = await response.json().catch(() => null);

            if (!response.ok) {
                const serverErrors = payload?.errors || {};
                let painted = false;
                for (const [field, msgs] of Object.entries(serverErrors)) {
                    const wrap = form.querySelector(`[data-field="${field}"]`);
                    if (!wrap) continue;
                    wrap.classList.add('is-error');
                    const input = wrap.querySelector('input,select,textarea');
                    if (input) {
                        input.setAttribute('aria-invalid', 'true');
                        input.setAttribute('aria-describedby', `content-${field}-err`);
                    }
                    const err = wrap.querySelector('.content__field-error');
                    if (err) err.textContent = Array.isArray(msgs) ? msgs[0] : String(msgs);
                    painted = true;
                }

                if (!painted) {
                    const err = form.querySelector('.content__field-error');
                    if (err) err.textContent = payload?.message || 'Something went wrong. Try again.';
                }

                if (submit) {
                    submit.disabled = false;
                    submit.removeAttribute('data-loading');
                }
                return;
            }

            renderFormSuccess(form, name, payload?.message);
        })
        .catch(() => {
            const err = form.querySelector('.content__field-error');
            if (err) err.textContent = 'Network error. Check your connection and try again.';
            if (submit) {
                submit.disabled = false;
                submit.removeAttribute('data-loading');
            }
        });
}

/**
 * Replace the form with the confirmation panel once the server has
 * accepted the message.
 */
function renderFormSuccess(form, name, serverMessage) {
    const success = el('div', { class: 'content__form-success', tabindex: '-1' });
    success.appendChild(el('h3', { class: 'content__form-success-title' }, 'MESSAGE SENT ✓'));
    const sub = el('p', { class: 'content__form-success-body' });
    sub.appendChild(document.createTextNode('Thanks '));
    sub.appendChild(el('strong', {}, name));
    sub.appendChild(document.createTextNode('. '));
    sub.appendChild(document.createTextNode(serverMessage || 'We will reply within 24 hours.'));
    success.appendChild(sub);
    form.replaceWith(success);
    success.focus();
}

function buildHours(block) {
    const wrap = el('div', { class: 'content__hours' });
    if (block.rows && block.rows.length) {
        const tbl = el('dl', { class: 'content__hours-table' });
        for (const [day, time] of block.rows) {
            const row = el('div', { class: 'content__hours-row' });
            const d = el('dt', { class: 'content__hours-day' });
            appendInline(d, day);
            const t = el('dd', { class: 'content__hours-time' });
            appendInline(t, time);
            row.appendChild(d);
            row.appendChild(t);
            tbl.appendChild(row);
        }
        wrap.appendChild(tbl);
    }
    if (block.social && block.social.length) {
        const social = el('div', { class: 'content__social' });
        for (const s of block.social) {
            if (!URL_OK.test(String(s.href || ''))) continue;
            const isExt = /^https?:\/\//i.test(s.href);
            const a = el('a', {
                class: 'content__social-btn',
                href: s.href,
                rel: isExt ? 'noopener noreferrer' : '',
            }, s.label);
            social.appendChild(a);
        }
        wrap.appendChild(social);
    }
    if (block.address) {
        const map = el('div', { class: 'content__map', 'aria-hidden': 'true' });
        map.appendChild(el('span', { class: 'content__map-watermark' }, 'ABO'));
        const addr = el('p', { class: 'content__map-addr' });
        appendInline(addr, block.address);
        map.appendChild(addr);
        map.appendChild(el('span', { class: 'content__map-note' }, 'Map goes here'));
        wrap.appendChild(map);
    }
    return wrap;
}

function buildCols(block) {
    const grid = el('div', { class: 'content__cols' });
    for (const child of block.items || []) {
        const cell = el('div', { class: 'content__col' });
        if (child.heading) cell.appendChild(renderHeading(child.heading, uniqueId(slugify(child.heading))));
        const inner = buildBlock(child);
        if (inner) cell.appendChild(inner);
        grid.appendChild(cell);
    }
    return grid;
}

/**
 * A block of admin-authored HTML, straight from the `pages` table.
 *
 * The copy is written by an authenticated admin through the protected
 * admin.pages.update route, so it is trusted the same way the server
 * trusts it when it echoes the body into the page.
 */
function buildHtml(block) {
    const wrap = el('div', { class: 'content__body' });
    wrap.innerHTML = block.html || '';
    return wrap;
}

const BLOCK_BUILDERS = {
    html: buildHtml,
    stats: buildStats,
    text: buildText,
    steps: buildSteps,
    table: buildTable,
    note: buildNote,
    faq: buildFaq,
    hero: buildHero,
    values: buildValues,
    timeline: buildTimeline,
    cards: buildCards,
    form: buildForm,
    hours: buildHours,
    cols: buildCols,
};

function buildBlock(block) {
    const fn = BLOCK_BUILDERS[block && block.type];
    if (!fn) {
        if (import.meta.env && import.meta.env.DEV) {
            // eslint-disable-next-line no-console
            console.warn(`[content] unknown block type: ${block && block.type}`);
        }
        return null;
    }
    return fn(block);
}

// ─── Page-level renderers ────────────────────────────────────────

function renderHeader(page) {
    const head = el('header', { class: 'content__head' });

    const eyebrow = el('p', { class: 'content__eyebrow' });
    eyebrow.appendChild(el('span', { class: 'content__eyebrow-line', 'aria-hidden': 'true' }));
    const eyebrowText = (page.group === 'policy' ? 'HELP CENTER' : (page.eyebrow || 'ABOUT ABO'));
    eyebrow.appendChild(el('span', {}, eyebrowText));
    head.appendChild(eyebrow);

    // Last word of title becomes lime.
    const words = String(page.title).trim().split(/\s+/);
    const last = words.pop();
    const h1 = el('h1', { class: 'content__title', id: 'content-title' });
    if (words.length) h1.appendChild(document.createTextNode(words.join(' ') + ' '));
    h1.appendChild(el('span', { class: 'content__title-accent' }, last));
    head.appendChild(h1);

    if (page.summary) {
        const sum = el('p', { class: 'content__summary' });
        appendInline(sum, page.summary);
        head.appendChild(sum);
    }
    if (page.updatedAt) {
        head.appendChild(el('p', { class: 'content__updated' }, `LAST UPDATED: ${page.updatedAt}`));
    }
    return head;
}

function renderTabs(page) {
    if (page.group !== 'policy') return null;
    const tabs = el('nav', { class: 'content__tabs', 'aria-label': 'Policies' });
    const items = [
        { slug: 'shipping', label: 'Shipping' },
        { slug: 'returns', label: 'Returns' },
        { slug: 'exchange', label: 'Exchange' },
    ];
    for (const it of items) {
        const a = el('a', { href: `/${it.slug}`, class: 'content__tab' }, it.label);
        if (it.slug === page.slug) {
            a.classList.add('is-active');
            a.setAttribute('aria-current', 'page');
        }
        tabs.appendChild(a);
    }
    return tabs;
}

function renderBlocks(page) {
    const list = el('div', { class: 'content__blocks' });
    const headings = [];
    let i = 0;
    for (const block of page.blocks || []) {
        i += 1;
        const wrap = el('section', { class: 'content__block', dataset: { reveal: '' } });
        const anchor = block.heading ? uniqueId(slugify(block.heading)) : uniqueId(`block-${i}`);
        if (block.heading) {
            wrap.appendChild(renderHeading(block.heading, anchor));
            headings.push({ id: anchor, label: block.heading });
        }
        const built = buildBlock(block);
        if (built) wrap.appendChild(built);
        list.appendChild(wrap);
    }
    return { list, headings };
}

function renderToc(headings) {
    if (!headings || headings.length === 0) return null;
    const nav = el('nav', { class: 'content__toc', 'aria-label': 'On this page' });
    const list = el('ol', { class: 'content__toc-list' });
    for (const h of headings) {
        const li = el('li', { class: 'content__toc-item' });
        const a = el('a', { href: `#${h.id}`, class: 'content__toc-link' }, h.label);
        li.appendChild(a);
        list.appendChild(li);
    }
    nav.appendChild(list);
    return nav;
}

function renderCta(page) {
    if (page.slug === 'contact') return null;
    const cta = el('section', { class: 'content__cta', dataset: { reveal: '' } });
    const h = el('h2', { class: 'content__cta-title' });
    h.appendChild(document.createTextNode('STILL NEED '));
    h.appendChild(el('span', { class: 'content__cta-accent' }, 'HELP?'));
    cta.appendChild(h);
    cta.appendChild(el('p', { class: 'content__cta-sub' }, 'Our team replies within 24 hours.'));
    cta.appendChild(el('a', { class: 'content__btn content__btn--primary', href: '/contact' }, 'Contact us →'));
    return cta;
}

// ─── UI states ──────────────────────────────────────────────────

function renderSkeleton() {
    const root = el('div', { class: 'content content--skeleton', 'aria-busy': 'true' });
    const head = el('div', { class: 'content__head' });
    head.appendChild(el('span', { class: 'content__skel content__skel--eyebrow' }));
    head.appendChild(el('span', { class: 'content__skel content__skel--title' }));
    head.appendChild(el('span', { class: 'content__skel content__skel--summary' }));
    root.appendChild(head);
    for (let i = 0; i < 3; i += 1) {
        const block = el('div', { class: 'content__skel-block' });
        for (let j = 0; j < 4; j += 1) {
            block.appendChild(el('span', { class: 'content__skel content__skel--bar' }));
        }
        root.appendChild(block);
    }
    return root;
}

function renderNotFound() {
    const root = el('div', { class: 'content content--notfound' });
    const box = el('div', { class: 'content__notfound' });
    box.appendChild(el('h3', { class: 'content__notfound-title' }, 'PAGE NOT FOUND'));
    box.appendChild(el('p', { class: 'content__notfound-body' }, 'The page you are looking for is not here.'));
    box.appendChild(el('a', { class: 'content__btn content__btn--primary', href: '/' }, 'Back to home →'));
    root.appendChild(box);
    return root;
}

function renderEmpty(page) {
    const root = el('div', { class: 'content content--empty' });
    root.appendChild(renderHeader(page));
    root.appendChild(el('p', { class: 'content__empty' }, 'Content coming soon.'));
    return root;
}

function renderDraftBar() {
    return el('div', { class: 'content__draft-bar' }, 'DRAFT PREVIEW — not visible to customers');
}

// ─── Page render (main entry) ───────────────────────────────────

function setMeta(page) {
    if (page.seo && page.seo.title) document.title = page.seo.title;
    if (page.seo && page.seo.description) {
        let m = document.querySelector('meta[name="description"]');
        if (!m) {
            m = document.createElement('meta');
            m.setAttribute('name', 'description');
            document.head.appendChild(m);
        }
        m.setAttribute('content', page.seo.description);
    }
}

function clearShell() {
    const shell = $('.content__shell');
    if (shell) shell.replaceChildren();
}

function renderAll() {
    const shell = $('.content__shell');
    if (!shell) return;
    const page = state.page;
    clearShell();
    setMeta(page);

    if (page.status === 'draft' && state.preview) {
        const bar = renderDraftBar();
        document.body.appendChild(bar);
    } else {
        document.querySelector('.content__draft-bar')?.remove();
    }

    if (!page.blocks || page.blocks.length === 0) {
        shell.appendChild(renderEmpty(page));
        return;
    }

    const root = el('div', { class: 'content' });
    root.appendChild(renderHeader(page));
    const tabs = renderTabs(page);
    if (tabs) root.appendChild(tabs);

    if (page.group === 'policy') {
        const body = el('div', { class: 'content__body' });
        const { list, headings } = renderBlocks(page);
        const toc = renderToc(headings);
        // TOC is the first grid item (left column = 220px on ≥900px).
        // Main is the second grid item (right column = 1fr).
        if (toc) body.appendChild(toc);
        const main = el('div', { class: 'content__main' });
        main.appendChild(list);
        body.appendChild(main);
        root.appendChild(body);
        initScrollSpy(toc);
    } else {
        const { list } = renderBlocks(page);
        root.appendChild(list);
    }

    const cta = renderCta(page);
    if (cta) root.appendChild(cta);

    shell.appendChild(root);
    initReveal();
    if (state.preview && page.status === 'draft') document.querySelector('.content__draft-bar')?.classList.add('is-visible');
    if (location.hash) jumpToHash();
}

// ─── TOC scroll-spy (spec §3) ───────────────────────────────────

let scrollSpyObs = null;
function initScrollSpy(tocNav) {
    if (!tocNav) return;
    const links = $$('.content__toc-link', tocNav);
    const targets = links.map((a) => document.getElementById(decodeURIComponent(a.getAttribute('href').slice(1)))).filter(Boolean);
    if (scrollSpyObs) scrollSpyObs.disconnect();
    if (!('IntersectionObserver' in window) || targets.length === 0) return;

    function setActive(id) {
        for (const a of links) {
            const match = a.getAttribute('href') === `#${id}`;
            a.classList.toggle('is-active', match);
            if (match) a.closest('.content__toc-item')?.classList.add('is-active');
            else a.closest('.content__toc-item')?.classList.remove('is-active');
        }
    }

    const visible = new Set();
    scrollSpyObs = new IntersectionObserver((entries) => {
        for (const e of entries) {
            if (e.isIntersecting) visible.add(e.target.id);
            else visible.delete(e.target.id);
        }
        // Pick the first visible (in document order).
        for (const t of targets) {
            if (visible.has(t.id)) {
                setActive(t.id);
                return;
            }
        }
    }, { rootMargin: '-15% 0px -75% 0px', threshold: 0 });
    targets.forEach((t) => scrollSpyObs.observe(t));

    for (const a of links) {
        a.addEventListener('click', (ev) => {
            ev.preventDefault();
            const id = a.getAttribute('href').slice(1);
            const t = document.getElementById(id);
            if (!t) return;
            const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            t.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
            history.replaceState(null, '', `#${id}`);
            setActive(id);
        });
    }
}

function jumpToHash() {
    const id = decodeURIComponent(location.hash.slice(1));
    if (!id) return;
    const t = document.getElementById(id);
    if (!t) return;
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    t.scrollIntoView({ behavior: reduce ? 'auto' : 'auto', block: 'start' });
}

// ─── Reveal on scroll (spec §3) ─────────────────────────────────

let revealObs = null;
function initReveal() {
    if (revealObs) revealObs.disconnect();
    const items = $$('[data-reveal]');
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce) {
        items.forEach((el) => el.classList.add('is-revealed'));
        return;
    }
    if (!('IntersectionObserver' in window)) {
        items.forEach((el) => el.classList.add('is-revealed'));
        return;
    }
    revealObs = new IntersectionObserver((entries) => {
        for (const e of entries) {
            if (e.isIntersecting) {
                e.target.classList.add('is-revealed');
                revealObs.unobserve(e.target);
            }
        }
    }, { threshold: 0.1 });
    items.forEach((el) => revealObs.observe(el));
}

// ─── Dev panel (spec §5) ────────────────────────────────────────

function initDevPanel() {
    if (!state.dev) return;
    const panel = el('aside', { class: 'content__dev-panel', 'aria-label': 'Dev settings' });
    panel.appendChild(el('h4', { class: 'content__dev-title' }, 'DEV SETTINGS'));
    panel.appendChild(el('p', { class: 'content__dev-hint' }, 'Live updates every {{variable}} on this page.'));

    function labeledInput(name, label, value) {
        const row = el('label', { class: 'content__dev-row' });
        row.appendChild(el('span', { class: 'content__dev-label' }, label));
        row.appendChild(el('input', { class: 'content__dev-input', type: 'number', name, value: String(value), min: 0 }));
        return row;
    }
    panel.appendChild(labeledInput('free_shipping', 'Free shipping threshold', state.settings.freeShippingThreshold));
    panel.appendChild(labeledInput('return_days', 'Return days', state.settings.returnDays));
    panel.appendChild(labeledInput('exchange_days', 'Exchange days', state.settings.exchangeDays));

    const actions = el('div', { class: 'content__dev-actions' });
    const apply = el('button', { class: 'content__btn content__btn--primary', type: 'button' }, 'Apply');
    const reset = el('button', { class: 'content__btn content__btn--ghost', type: 'button' }, 'Reset');
    const close = el('button', { class: 'content__btn content__btn--ghost', type: 'button' }, 'Close');
    actions.appendChild(apply);
    actions.appendChild(reset);
    actions.appendChild(close);
    panel.appendChild(actions);

    apply.addEventListener('click', () => {
        const fs = Number(panel.querySelector('input[name="free_shipping"]').value);
        const rd = Number(panel.querySelector('input[name="return_days"]').value);
        const ed = Number(panel.querySelector('input[name="exchange_days"]').value);
        setSettings({ freeShippingThreshold: fs, returnDays: rd, exchangeDays: ed });
        renderAll();
    });
    reset.addEventListener('click', () => {
        setSettings(SETTINGS);
        panel.querySelector('input[name="free_shipping"]').value = SETTINGS.freeShippingThreshold;
        panel.querySelector('input[name="return_days"]').value = SETTINGS.returnDays;
        panel.querySelector('input[name="exchange_days"]').value = SETTINGS.exchangeDays;
        renderAll();
    });
    close.addEventListener('click', () => {
        const url = new URL(location.href);
        url.searchParams.delete('dev');
        location.replace(url.toString());
    });

    document.body.appendChild(panel);
}

// ─── Init ───────────────────────────────────────────────────────

async function init() {
    const shell = $('.content__shell');
    if (!shell) return;
    const slug = shell.dataset.pageSlug || '';

    const params = new URLSearchParams(window.location.search);
    state.dev = params.get('dev') === '1';
    state.preview = params.get('preview') === '1';
    const forced = params.get('state');
    if (forced === 'loading' || forced === 'notfound' || forced === 'empty' || forced === 'draft') {
        state.forceState = forced;
    }

    usedIds.clear();

    // Loading state (~500ms) — only when the server did not already
    // render the page. When it did, the copy is on screen and showing
    // a skeleton over it would only cause a flash.
    const serverRendered = !!$('.content__body');
    if (!serverRendered) {
        const skel = renderSkeleton();
        shell.appendChild(skel);
        await new Promise((r) => setTimeout(r, 500));
        skel.remove();
    }

    // Force-state overrides for design review.
    if (state.forceState === 'notfound') {
        state.page = { slug, group: 'info', title: 'Not found', summary: '', updatedAt: '', status: 'published', blocks: [] };
        const root = renderNotFound();
        shell.appendChild(root);
        initDevPanel();
        return;
    }
    if (state.forceState === 'empty') {
        state.page = {
            slug,
            group: 'info',
            title: 'Page title',
            summary: 'A short summary of what this page is about.',
            updatedAt: '2026-10-12',
            status: 'published',
            blocks: [],
        };
        renderAll();
        initDevPanel();
        return;
    }
    if (state.forceState === 'draft') {
        state.page = {
            slug,
            group: 'info',
            title: 'Draft page',
            summary: 'Preview only — not visible to customers.',
            updatedAt: '2026-10-12',
            status: 'draft',
            blocks: [
                { type: 'text', heading: 'Work in progress', paragraphs: ['This page is still being written.'] },
            ],
        };
        renderAll();
        initDevPanel();
        return;
    }

    const page = await getPage(slug);
    if (!page) {
        state.page = { slug, group: 'info', title: 'Not found', summary: '', updatedAt: '', status: 'published', blocks: [] };
        shell.appendChild(renderNotFound());
        initDevPanel();
        return;
    }
    if (page.status !== 'published' && !state.preview) {
        shell.appendChild(renderNotFound());
        initDevPanel();
        return;
    }
    state.page = page;
    renderAll();
    initDevPanel();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
