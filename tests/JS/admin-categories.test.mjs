// Runtime tests for the admin categories screen.
//
// The tree on the categories page was drawn from the database, but two
// of its controls were still a demo: switching a category on or off
// toasted "(demo)" and dragged a row into a new place toasted "Order
// updated (demo)" — neither reached the server, so the tree the admin
// saw was gone with the reload.
//
// These tests run public/admin/js/categories.js for real — against a
// small DOM and a fake server — so that:
//
//   - the tree is built from the categories the controller sends;
//   - the switch reaches the server and the row on screen is the row
//     the server answered with;
//   - a refused switch leaves the tree as the database still sees it;
//   - dropping a row sends the new order and the new nesting.
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
const categoriesScript = fs.readFileSync(
    path.join(projectRoot, 'public/admin/js/categories.js'),
    'utf8'
);

/**
 * Three categories, as Admin\CategoryController sends them.
 */
const CATEGORIES = [
    {
        id: '1', parentId: null, name: 'Hoodies', nameAr: 'هوديز', slug: 'hoodies',
        description: 'Warm layers', products: 12, active: true,
        visibleInStore: true, showInHomepage: true, cover: null,
    },
    {
        id: '2', parentId: '1', name: 'Oversize', nameAr: 'أوفرسايز', slug: 'oversize',
        description: '', products: 4, active: true,
        visibleInStore: true, showInHomepage: false, cover: null,
    },
    {
        id: '3', parentId: null, name: 'Pants', nameAr: 'بناطيل', slug: 'pants',
        description: '', products: 8, active: false,
        visibleInStore: true, showInHomepage: false, cover: null,
    },
];

/**
 * Build the page the categories screen runs on.
 */
function buildPage(browser) {
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

    const page = el('div', { 'data-abo-admin-categories': '' });

    // The toolbar.
    page.appendChild(el('input', { type: 'search', 'data-abo-admin-categories-search': '' }));
    page.appendChild(el('button', { type: 'button', 'data-abo-admin-categories-expand-all': '' }));
    page.appendChild(el('button', { type: 'button', 'data-abo-admin-categories-add': '' }));

    // The tree and its states.
    page.appendChild(el('ul', { role: 'tree', 'data-abo-admin-categories-tree': '' }));
    page.appendChild(el('p', { 'data-abo-admin-categories-helper': '' }));
    page.appendChild(el('div', { 'data-abo-admin-categories-skeleton': '' }));
    page.appendChild(el('div', { 'data-abo-admin-categories-state': 'empty', hidden: true }));
    page.appendChild(el('div', { 'data-abo-admin-categories-state': 'noresults', hidden: true }));
    page.appendChild(el('button', { type: 'button', 'data-abo-admin-categories-add-empty': '' }));

    // The edit panel.
    const panel = el('aside', { 'data-abo-admin-categories-panel': '' });
    panel.appendChild(el('div', { 'data-abo-admin-categories-panel-overlay': '' }));
    panel.appendChild(el('div', { 'data-abo-admin-categories-panel-no-selection': '' }));
    panel.appendChild(el('button', { type: 'button', 'data-abo-admin-categories-panel-close': '' }));

    const form = el('div', { 'data-abo-admin-categories-panel-form': '', hidden: true });
    form.appendChild(el('span', { 'data-abo-admin-categories-panel-title-text': '' }));
    form.appendChild(el('span', { 'data-abo-admin-categories-dirty-dot': '' }));
    form.appendChild(el('div', { 'data-abo-admin-categories-cover-preview': '' }));
    form.appendChild(el('input', { type: 'file', 'data-abo-admin-categories-cover-file': '' }));
    form.appendChild(el('button', { type: 'button', 'data-abo-admin-categories-cover-change': '' }));
    form.appendChild(el('button', { type: 'button', 'data-abo-admin-categories-cover-remove': '' }));

    const lang = el('div', { 'data-abo-admin-categories-lang': '' });
    lang.appendChild(el('button', { type: 'button', 'data-lang': 'en', text: 'EN' }));
    lang.appendChild(el('button', { type: 'button', 'data-lang': 'ar', text: 'AR' }));
    form.appendChild(lang);

    form.appendChild(el('input', { 'data-abo-admin-categories-name': '' }));
    form.appendChild(el('input', { 'data-abo-admin-categories-slug': '' }));
    form.appendChild(el('select', { 'data-abo-admin-categories-parent': '' }));
    form.appendChild(el('textarea', { 'data-abo-admin-categories-description': '' }));
    form.appendChild(el('input', { type: 'checkbox', 'data-abo-admin-categories-visible': '' }));
    form.appendChild(el('input', { type: 'checkbox', 'data-abo-admin-categories-homepage': '' }));
    form.appendChild(el('button', { type: 'button', 'data-abo-admin-categories-save': '' }));
    form.appendChild(el('button', { type: 'button', 'data-abo-admin-categories-delete': '' }));
    form.appendChild(el('p', { 'data-abo-admin-categories-delete-note': '', hidden: true }));

    panel.appendChild(form);
    page.appendChild(panel);

    // The delete modal.
    const modal = el('div', { 'data-abo-admin-categories-modal': '', hidden: true });
    modal.appendChild(el('p', { 'data-abo-admin-categories-modal-body': '' }));
    modal.appendChild(el('button', { type: 'button', 'data-abo-admin-categories-modal-close': '' }));
    modal.appendChild(el('button', { type: 'button', 'data-abo-admin-categories-modal-cancel': '' }));
    modal.appendChild(el('button', { type: 'button', 'data-abo-admin-categories-modal-confirm': '' }));
    page.appendChild(modal);

    // The toast.
    const toast = el('div', { 'data-abo-admin-categories-toast': '', hidden: true });
    toast.appendChild(el('span', { 'data-abo-admin-categories-toast-dot': '' }));
    toast.appendChild(el('span', { 'data-abo-admin-categories-toast-text': '' }));
    page.appendChild(toast);

    root.appendChild(page);

    return { document, root, page, panel, modal, toast };
}

const jsonResponse = (status, payload) => Promise.resolve({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(payload),
});

/**
 * A server that answers the way Admin\CategoryController does.
 */
function defaultFetch(calls) {
    return (url, options = {}) => {
        calls.push({ url, options });

        const method = options.method;
        const body = options.body ? JSON.parse(options.body) : null;

        if (method === 'DELETE') {
            return jsonResponse(200, { success: true, message: 'Category deleted successfully.' });
        }

        if (url === '/admin/categories/reorder') {
            const order = body.categories.map((row) => {
                const category = CATEGORIES.find((c) => c.id === row.id);

                return {
                    ...category,
                    parentId: row.parent_id,
                };
            });

            return jsonResponse(200, {
                success: true,
                message: 'Category order updated.',
                categories: order,
            });
        }

        if (method === 'PATCH' && url.endsWith('/toggle')) {
            const id = url.split('/')[3];
            const category = CATEGORIES.find((c) => c.id === id);

            return jsonResponse(200, {
                success: true,
                message: category.active ? 'Category deactivated successfully.' : 'Category activated successfully.',
                category: { ...category, active: !category.active },
            });
        }

        return jsonResponse(200, {
            success: true,
            message: 'Category saved successfully.',
            category: CATEGORIES[0],
        });
    };
}

/**
 * Run the categories script against a fresh page.
 */
function run({ categories = CATEGORIES, fetchImpl } = {}) {
    const browser = createBrowser();
    const page = buildPage(browser);

    const calls = [];
    const fetchStub = fetchImpl || defaultFetch(calls);

    browser.window.__CATEGORIES__ = JSON.parse(JSON.stringify(categories));
    browser.window.matchMedia = () => ({ matches: false });

    const meta = page.document.createElement('meta');
    meta.setAttribute('name', 'csrf-token');
    meta.setAttribute('content', 'test-token');
    page.root.appendChild(meta);

    const sandbox = {
        document: page.document,
        window: browser.window,
        localStorage: browser.localStorage,
        fetch: fetchStub,
        // The screen waits 600 ms before it draws the tree. The timers
        // are clamped so the test does not have to wait for them.
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
        Event: class {
            constructor(type, options = {}) {
                this.type = type;
                this.bubbles = Boolean(options.bubbles);
            }
        },
    };
    sandbox.globalThis = sandbox;

    const context = vm.createContext(sandbox);
    vm.runInContext(categoriesScript, context);

    return { ...page, calls, browser, sandbox };
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 20));

const tree = (page) => page.document.querySelector('[data-abo-admin-categories-tree]');
const row = (page, id) => tree(page).querySelector(`li[data-id="${id}"]`);
const toggleOf = (page, id) => tree(page).querySelector(`[data-abo-admin-categories-toggle="${id}"]`);
const toastText = (page) =>
    page.document.querySelector('[data-abo-admin-categories-toast-text]').textContent;

test('the tree is built from the categories the controller sent', async () => {
    const page = run();
    await settle();

    assert.match(tree(page).textContent, /Hoodies/);
    assert.match(tree(page).textContent, /Pants/);
    assert.match(tree(page).textContent, /Oversize/);
});

test('switching a category off reaches the server', async () => {
    const page = run();
    await settle();

    const toggle = toggleOf(page, '1');
    toggle.checked = false;

    click(toggle);
    await settle();

    assert.equal(page.calls.length, 1);
    assert.equal(page.calls[0].url, '/admin/categories/1/toggle');
    assert.equal(page.calls[0].options.method, 'PATCH');
    assert.equal(page.calls[0].options.headers['X-CSRF-TOKEN'], 'test-token');
});

test('the row on screen is the row the server answered with', async () => {
    const page = run({
        fetchImpl: (url, options) => {
            page.calls.push({ url, options });
            return jsonResponse(200, {
                success: true,
                message: 'Category deactivated successfully.',
                category: { ...CATEGORIES[0], active: false },
            });
        },
    });
    await settle();

    const toggle = toggleOf(page, '1');
    toggle.checked = false;

    click(toggle);
    await settle();

    assert.equal(toggleOf(page, '1').checked, false);
    assert.equal(toastText(page), 'Category deactivated successfully.');
});

test('a refused switch leaves the tree as the database sees it', async () => {
    const page = run({
        fetchImpl: (url, options) => {
            page.calls.push({ url, options });
            return jsonResponse(422, {
                success: false,
                message: 'You cannot deactivate the last category.',
            });
        },
    });
    await settle();

    const toggle = toggleOf(page, '1');
    toggle.checked = false;

    click(toggle);
    await settle();

    assert.equal(toastText(page), 'You cannot deactivate the last category.');
    assert.equal(toggleOf(page, '1').checked, true);
});

test('dropping a row sends the new order and the new nesting', async () => {
    const page = run();
    await settle();

    // "Pants" is dropped before "Hoodies".
    const target = row(page, '1');
    target.classList.add('is-drop-before');
    target.dispatchEvent('drop', {
        target,
        preventDefault() {},
        dataTransfer: { getData: () => '3' },
    });
    await settle();

    assert.equal(page.calls.length, 1);
    assert.equal(page.calls[0].url, '/admin/categories/reorder');
    assert.equal(page.calls[0].options.method, 'POST');

    const body = JSON.parse(page.calls[0].options.body);
    assert.deepEqual(body.categories, [
        { id: '3', parent_id: null, sort_order: 0 },
        { id: '1', parent_id: null, sort_order: 1 },
        { id: '2', parent_id: '1', sort_order: 0 },
    ]);
});

test('a refused order puts the server tree back', async () => {
    const page = run({
        fetchImpl: (url, options) => {
            page.calls.push({ url, options });
            return jsonResponse(422, {
                success: false,
                message: 'A category cannot be moved under itself.',
            });
        },
    });
    await settle();

    const target = row(page, '1');
    target.classList.add('is-drop-before');
    target.dispatchEvent('drop', {
        target,
        preventDefault() {},
        dataTransfer: { getData: () => '3' },
    });
    await settle();

    assert.equal(toastText(page), 'A category cannot be moved under itself.');

    // The tree is back to the order the server confirmed.
    const first = tree(page).querySelector('li[data-id]');
    assert.equal(first.dataset.id, '1');
});
