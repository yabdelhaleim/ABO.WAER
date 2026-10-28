// Runtime tests for the admin size guides screen.
//
// The screen was the last admin page that drew from a list written
// into the view: four invented guides with invented measurements, and
// a "Saved (demo)" toast for every change. It now draws from the
// database, and every change goes back to the server.
//
// These tests run public/admin/js/size-guides.js for real — against a
// small DOM and a fake server — and check that:
//
//   - the list is built from the guides the controller sends;
//   - the switch reaches the server and the row on screen is the
//     guide the server answered with;
//   - a refused switch leaves the row as the database still sees it;
//   - saving sends the chart as the grid the editor holds;
//   - duplicating and deleting reach the server too.
//
// No new dependency: node:test is part of Node itself.

import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

import { createBrowser, click } from './support/mini-dom.mjs';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const sizeGuidesScript = fs.readFileSync(
    path.join(projectRoot, 'public/admin/js/size-guides.js'),
    'utf8'
);

/**
 * One guide, as Admin\SizeGuideController sends it.
 */
const GUIDE = {
    id: '1',
    name: 'Hoodies and Jackets',
    name_ar: 'هوديز',
    description: '',
    note: 'Measurements are in cm.',
    note_ar: '',
    unit: 'cm',
    how_to: '',
    how_to_ar: '',
    image: null,
    image_path: '',
    category_id: '3',
    product_count: 24,
    active: true,
    measurements: ['Chest', 'Length'],
    sizes: ['S', 'M'],
    rows: [
        { id: '10', size_name: 'S', measurements: { Chest: '52', Length: '68' } },
        { id: '11', size_name: 'M', measurements: { Chest: '54', Length: '70' } },
    ],
};

/**
 * The endpoints the controller renders.
 */
const ROUTES = {
    store: { url: '/admin/size-guides' },
    update: { url: '/admin/size-guides/__GUIDE__' },
    destroy: { url: '/admin/size-guides/__GUIDE__' },
    toggle: { url: '/admin/size-guides/__GUIDE__/toggle' },
    duplicate: { url: '/admin/size-guides/__GUIDE__/duplicate' },
    image: { url: '/admin/size-guides/__GUIDE__/image' },
};

/**
 * The size guides page, with the hooks size-guides.js reads.
 */
function buildPage(browser, guides) {
    const { document, root, FakeElement } = browser;

    const el = (tag, attrs = {}, children = []) => {
        const node = new FakeElement(tag);
        for (const [k, v] of Object.entries(attrs)) {
            if (v == null || v === false) continue;
            if (k === 'class') node.className = v;
            else if (k === 'text') node.textContent = v;
            else if (k === 'hidden') {
                node.hidden = true;
                node.setAttribute('hidden', '');
            } else if (k === 'value') {
                node.value = v;
                node.setAttribute('value', String(v));
            } else node.setAttribute(k, v === true ? '' : String(v));
        }
        for (const c of [].concat(children)) {
            if (c == null) continue;
            node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        }
        return node;
    };

    const page = el('div', { class: 'abo-admin-page' });

    // The three JSON the controller renders.
    const data = el('script', { 'data-abo-admin-size-guides-data': '' });
    data.textContent = JSON.stringify(guides);
    page.appendChild(data);

    const sizes = el('script', { 'data-abo-admin-size-guides-sizes': '' });
    sizes.textContent = JSON.stringify(['S', 'M', 'L']);
    page.appendChild(sizes);

    const routes = el('script', { 'data-abo-admin-size-guides-routes': '' });
    routes.textContent = JSON.stringify(ROUTES);
    page.appendChild(routes);

    page.appendChild(el('p', { id: 'abo-admin-today-date' }));

    // The list.
    page.appendChild(el('input', { type: 'search', 'data-abo-admin-size-guides-search': '' }));
    page.appendChild(el('ul', { role: 'listbox', 'data-abo-admin-size-guides-list': '' }));
    page.appendChild(el('div', { 'data-abo-admin-size-guides-state': 'empty', hidden: true }));
    page.appendChild(el('div', { 'data-abo-admin-size-guides-state': 'noresults', hidden: true }));
    page.appendChild(el('div', { 'data-abo-admin-size-guides-state': 'loading', hidden: true }));
    page.appendChild(el('button', { type: 'button', 'data-abo-admin-size-guides-add': '' }));

    // The editor panel.
    const panel = el('aside', { 'data-abo-admin-size-guides-panel': '' });
    panel.appendChild(el('div', { 'data-abo-admin-size-guides-panel-overlay': '' }));
    panel.appendChild(el('div', { 'data-abo-admin-size-guides-panel-no-selection': '' }));
    page.appendChild(panel);

    const form = el('div', { 'data-abo-admin-size-guides-panel-form': '', hidden: true });
    form.appendChild(el('span', { 'data-abo-admin-size-guides-panel-title-text': '' }));
    form.appendChild(el('span', { 'data-abo-admin-size-guides-dirty-dot': '' }));
    form.appendChild(el('input', { 'data-abo-admin-size-guides-name': '' }));
    form.appendChild(el('input', { 'data-abo-admin-size-guides-name-ar': '' }));
    form.appendChild(el('select', { 'data-abo-admin-size-guides-category': '' }));
    form.appendChild(el('input', { type: 'checkbox', 'data-abo-admin-size-guides-active': '' }));
    form.appendChild(el('div', { 'data-abo-admin-size-guides-howto-preview': '' }));
    form.appendChild(el('button', { type: 'button', 'data-abo-admin-size-guides-howto-image-remove': '' }));
    form.appendChild(el('table', { 'data-abo-admin-size-guides-table': '' }));
    form.appendChild(el('button', { type: 'button', 'data-abo-admin-size-guides-save': '' }));
    form.appendChild(el('button', { type: 'button', 'data-abo-admin-size-guides-duplicate': '' }));
    form.appendChild(el('button', { type: 'button', 'data-abo-admin-size-guides-delete': '' }));
    page.appendChild(form);

    // The toast.
    const toast = el('div', { 'data-abo-admin-size-guides-toast': '', hidden: true });
    toast.appendChild(el('span', { 'data-abo-admin-size-guides-toast-dot': '' }));
    toast.appendChild(el('span', { 'data-abo-admin-size-guides-toast-text': '' }));
    page.appendChild(toast);

    // The delete-confirm modal.
    const modal = el('div', { 'data-abo-admin-size-guides-modal': '', hidden: true });
    modal.appendChild(el('p', { 'data-abo-admin-size-guides-modal-body': '' }));
    modal.appendChild(el('button', { type: 'button', 'data-abo-admin-size-guides-modal-close': '' }));
    modal.appendChild(el('button', { type: 'button', 'data-abo-admin-size-guides-modal-cancel': '' }));
    modal.appendChild(el('button', { type: 'button', 'data-abo-admin-size-guides-modal-confirm': '' }));
    page.appendChild(modal);

    root.appendChild(page);

    return { document, root, page };
}

/**
 * Run size-guides.js against the page.
 *
 * @param {{ guides?: object[], respond?: (call) => object }} options
 */
function run({ guides = [GUIDE], respond } = {}) {
    const browser = createBrowser();
    const page = buildPage(browser, guides);

    const calls = [];

    const fetchStub = async (url, options = {}) => {
        const body = options.body ? JSON.parse(options.body) : null;
        calls.push({ url, method: options.method, body });

        const answer = respond ? respond({ url, method: options.method, body }) : null;
        const payload = answer === null ? { success: true, guide: GUIDE } : answer;

        return {
            ok: payload.success !== false,
            status: payload.success === false ? 422 : 200,
            json: async () => payload,
        };
    };

    const sandbox = {
        document: page.document,
        window: browser.window,
        localStorage: browser.localStorage,
        fetch: fetchStub,
        setTimeout: (callback, delay) => setTimeout(callback, Math.min(delay || 0, 1)),
        clearTimeout,
        requestAnimationFrame: (callback) => setTimeout(callback, 0),
        console,
        CSS: browser.window.CSS,
        Intl,
        Date,
        JSON,
        Array,
        Object,
        String,
        Number,
        Boolean,
        Promise,
        Error,
        FormData: class {
            append() {}
        },
    };
    sandbox.globalThis = sandbox;

    const context = vm.createContext(sandbox);
    vm.runInContext(sizeGuidesScript, context);

    // The script waits for the page to be ready.
    page.document.dispatchEvent('DOMContentLoaded', {});

    return { ...page, calls, browser, sandbox };
}

/** Let the promise chains the screen starts settle. */
const tick = () => new Promise((resolve) => setTimeout(resolve, 5));

test('the list is built from the guides the controller sends', () => {
    const page = run();

    const rows = page.document.querySelectorAll('[data-abo-admin-size-guides-row]');

    assert.equal(rows.length, 1);
    assert.equal(rows[0].getAttribute('data-abo-admin-size-guides-row'), '1');
});

test('switching a guide reaches the server and the row shows its answer', async () => {
    const page = run({
        respond: () => ({
            success: true,
            message: 'Size guide updated successfully.',
            guide: { ...GUIDE, active: false, name: 'Hoodies & Jackets' },
        }),
    });

    const toggle = page.document.querySelector('[data-abo-admin-size-guides-row-toggle]');
    click(toggle);
    await tick();

    assert.equal(page.calls.length, 1);
    assert.equal(page.calls[0].url, '/admin/size-guides/1/toggle');
    assert.equal(page.calls[0].method, 'PATCH');
    assert.deepEqual(page.calls[0].body, { is_active: false });

    // The row on screen is the guide the server answered with.
    const row = page.document.querySelector('[data-abo-admin-size-guides-row]');
    assert.equal(row.textContent.includes('Hoodies & Jackets'), true);
});

test('a refused switch leaves the row as the database still sees it', async () => {
    const page = run({
        respond: () => ({ success: false, message: 'Not allowed.' }),
    });

    const toggle = page.document.querySelector('[data-abo-admin-size-guides-row-toggle]');
    click(toggle);
    await tick();

    assert.equal(page.calls.length, 1);

    // The guide is still active, because the server refused.
    const row = page.document.querySelector('[data-abo-admin-size-guides-row]');
    const input = page.document.querySelector('[data-abo-admin-size-guides-row-toggle]');
    assert.equal(input.checked, true);
    assert.equal(row.getAttribute('data-abo-admin-size-guides-row'), '1');
});

test('saving sends the chart as the grid the editor holds', async () => {
    const page = run();

    const save = page.document.querySelector('[data-abo-admin-size-guides-save]');
    click(save);
    await tick();

    assert.equal(page.calls.length, 1);
    assert.equal(page.calls[0].url, '/admin/size-guides/1');
    assert.equal(page.calls[0].method, 'PUT');

    const body = page.calls[0].body;

    assert.equal(body.name, 'Hoodies and Jackets');
    assert.equal(body.note, 'Measurements are in cm.');
    // The category is what puts the chart on the product page.
    assert.equal(body.category_id, '3');
    assert.deepEqual(body.measurements, ['Chest', 'Length']);
    assert.deepEqual(body.rows, [
        { id: '10', size_name: 'S', measurements: { Chest: '52', Length: '68' } },
        { id: '11', size_name: 'M', measurements: { Chest: '54', Length: '70' } },
    ]);
});

test('duplicating a guide reaches the server and the copy is the server answer', async () => {
    const page = run({
        respond: () => ({
            success: true,
            message: 'Size guide duplicated successfully.',
            guide: { ...GUIDE, id: '2', name: 'Hoodies and Jackets (copy)', active: false },
        }),
    });

    const duplicate = page.document.querySelector('[data-abo-admin-size-guides-duplicate]');
    click(duplicate);
    await tick();

    assert.equal(page.calls.length, 1);
    assert.equal(page.calls[0].url, '/admin/size-guides/1/duplicate');
    assert.equal(page.calls[0].method, 'POST');

    const rows = page.document.querySelectorAll('[data-abo-admin-size-guides-row]');
    assert.equal(rows.length, 2);
    assert.equal(rows[0].textContent.includes('(copy)'), true);
});

test('deleting a guide reaches the server and the row goes away', async () => {
    // A guide no product uses: the delete button is disabled while a
    // product still points at the guide.
    const page = run({
        guides: [{ ...GUIDE, product_count: 0 }],
        respond: () => ({ success: true, message: 'Size guide deleted successfully.' }),
    });

    const remove = page.document.querySelector('[data-abo-admin-size-guides-delete]');
    click(remove);

    const confirm = page.document.querySelector('[data-abo-admin-size-guides-modal-confirm]');
    click(confirm);
    await tick();

    assert.equal(page.calls.length, 1);
    assert.equal(page.calls[0].url, '/admin/size-guides/1');
    assert.equal(page.calls[0].method, 'DELETE');

    const rows = page.document.querySelectorAll('[data-abo-admin-size-guides-row]');
    assert.equal(rows.length, 0);
});
