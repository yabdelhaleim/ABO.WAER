// Runtime tests for the admin catalog list.
//
// The PHP suite can only read the JavaScript as text; it cannot tell a
// working table from a dead one. These tests run
// public/admin/js/products.js for real — against a small DOM, a fake
// server and Node's own clock — so "the list shows the products the
// database sent" and "the bulk bar writes to the server" are facts, not
// string matches.
//
// What is pinned here:
//   - the table is built from window.__PRODUCTS__, the payload the
//     controller sends, and not from a hard-coded sample array;
//   - the stat cards count the real rows;
//   - the search, the category filter and the status filter narrow the
//     list the way an operator expects;
//   - the active toggle and the bulk bar reach the server with the
//     right action and the right ids.
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
const productsScript = fs.readFileSync(
    path.join(projectRoot, 'public/admin/js/products.js'),
    'utf8'
);

const CATALOG = [
    {
        id: '96', name: 'ABO Oversized Heavy Hoodie', nameAr: 'هودي اوفرسايز ثقيل',
        slug: 'abo-oversized-heavy-hoodie', sku: 'ABO-HD-001',
        category: 'Winter Hoodies', categoryId: '95',
        price: 1890, compare: 2200, cost: 760,
        status: 'active', archived: false, active: true, featured: false,
        stock: 42, low: 10, variantsCount: 6,
        colors: [{ name: 'Onyx', hex: '#0D0F11' }, { name: 'Lime', hex: '#C6FF00' }],
        sizes: ['M', 'L'], cover: '/storage/products/hoodie.webp',
    },
    {
        id: '97', name: 'Cargo Pant Olive', nameAr: 'بنطلون كارجو',
        slug: 'cargo-pant-olive', sku: 'ABO-PT-002',
        category: 'Pants', categoryId: '96',
        price: 1450, compare: null, cost: 580,
        status: 'archived', archived: true, active: false, featured: false,
        stock: 4, low: 10, variantsCount: 4,
        colors: [{ name: 'Olive', hex: '#3E3D2B' }],
        sizes: ['S', 'M'], cover: null,
    },
    {
        id: '98', name: 'Wide-leg Pant', nameAr: 'بنطلون واسع',
        slug: 'wide-leg-pant', sku: 'ABO-PT-003',
        category: 'Pants', categoryId: '96',
        price: 1390, compare: null, cost: 540,
        status: 'active', archived: false, active: true, featured: false,
        stock: 4, low: 10, variantsCount: 4,
        colors: [{ name: 'Onyx', hex: '#0D0F11' }],
        sizes: ['S', 'M'], cover: null,
    },
];

/**
 * Build the page the admin list runs on.
 */
function buildPage(browser, catalog = CATALOG) {
    const { document, root, FakeElement } = browser;

    const el = (tag, attrs = {}, children = []) => {
        const node = new FakeElement(tag);
        for (const [k, v] of Object.entries(attrs)) {
            if (v == null || v === false) continue;
            if (k === 'class') node.className = v;
            else if (k === 'text') node.textContent = v;
            else if (k === 'dataset') {
                for (const [dk, dv] of Object.entries(v)) node.dataset[dk] = String(dv);
            } else node.setAttribute(k, v === true ? '' : String(v));
        }
        for (const c of [].concat(children)) {
            if (c == null) continue;
            node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        }
        return node;
    };

    const card = el('article', { class: 'abo-admin-card' });
    const tbody = el('tbody', { 'data-abo-admin-products-tbody': '' });
    card.appendChild(tbody);

    const stats = el('section', { 'data-abo-admin-products-stats': '' });
    for (const key of ['all', 'active', 'archived', 'low']) {
        stats.appendChild(el('span', { 'data-abo-admin-products-stat-count': key, text: '0' }));
        stats.appendChild(el('button', { type: 'button', 'data-abo-admin-products-stat': key, text: key }));
    }

    const filters = el('section', {});
    filters.appendChild(el('input', { type: 'search', 'data-abo-admin-products-search': '' }));
    filters.appendChild(el('select', { 'data-abo-admin-products-filter': 'category' }));
    filters.appendChild(el('select', { 'data-abo-admin-products-filter': 'status' }));
    filters.appendChild(el('select', { 'data-abo-admin-products-filter': 'stock' }));
    filters.appendChild(el('select', { 'data-abo-admin-products-sort': '' }));
    filters.appendChild(el('input', { type: 'checkbox', 'data-abo-admin-products-check-all': '' }));

    const bulk = el('section', { 'data-abo-admin-products-bulk': '', hidden: true });
    bulk.appendChild(el('span', { 'data-abo-admin-products-bulk-count': '0' }));
    for (const action of ['activate', 'archive', 'category', 'delete']) {
        bulk.appendChild(el('button', { type: 'button', 'data-abo-admin-products-bulk-action': action, text: action }));
    }

    const modal = el('div', { 'data-abo-admin-products-modal': '', hidden: true });
    modal.appendChild(el('h2', { id: 'abo-admin-products-modal-title' }));
    modal.appendChild(el('p', { 'data-abo-admin-products-modal-body': '' }));
    modal.appendChild(el('button', { type: 'button', 'data-abo-admin-products-modal-close': 'CANCEL' }));
    modal.appendChild(el('button', { type: 'button', 'data-abo-admin-products-modal-confirm': 'DELETE' }));

    const toast = el('div', { 'data-abo-admin-products-toast': '', hidden: true });
    toast.appendChild(el('span', { 'data-abo-admin-products-toast-text': '' }));

    root.appendChild(card);
    root.appendChild(stats);
    root.appendChild(filters);
    root.appendChild(bulk);
    root.appendChild(modal);
    root.appendChild(toast);
    root.appendChild(el('ul', { class: 'abo-admin-products__pagination-list' }));
    root.appendChild(el('p', { 'data-abo-admin-products-pagination-info': '' }));
    root.appendChild(el('div', { 'data-abo-admin-products-state': 'empty', hidden: true }));
    root.appendChild(el('div', { 'data-abo-admin-products-state': 'noresults', hidden: true }));

    return { document, root, tbody, stats, filters, bulk, modal, toast };
}

/**
 * Run the admin script against a fresh page and return the handles a
 * test needs.
 */
function run(catalog = CATALOG, { fetchImpl } = {}) {
    const browser = createBrowser();
    const page = buildPage(browser, catalog);

    const calls = [];
    const fetchStub = fetchImpl || ((url, options = {}) => {
        calls.push({ url, options });
        return Promise.resolve({
            json: () => Promise.resolve({ success: true, message: 'ok', affected: 1 }),
        });
    });

    browser.window.__PRODUCTS__ = catalog;
    browser.window.__PRODUCT_CATEGORIES__ = [
        { id: '95', name: 'Winter Hoodies' },
        { id: '96', name: 'Pants' },
    ];

    const sandbox = {
        document: page.document,
        window: browser.window,
        localStorage: browser.localStorage,
        fetch: fetchStub,
        setTimeout,
        clearTimeout,
        console,
        URL,
    };
    sandbox.globalThis = sandbox;

    const context = vm.createContext(sandbox);
    vm.runInContext(productsScript, context);

    page.document.dispatchEvent('DOMContentLoaded', {});

    return { ...page, calls, browser };
}

/**
 * The text of every rendered row.
 */
function rowNames(tbody) {
    return tbody.childNodes.map((tr) => tr.textContent);
}

/**
 * The row of one product, found by its id.
 */
function rowById(tbody, id) {
    return tbody.childNodes.find((tr) => tr.dataset.id === id) ?? null;
}

test('the list renders the products the controller sent', () => {
    const { tbody } = run();

    assert.equal(tbody.childNodes.length, 3);
    // Newest first: the ids are strings and must be compared as numbers.
    assert.equal(tbody.childNodes[0].dataset.id, '98');
    assert.match(rowNames(tbody)[0], /Wide-leg Pant/);
    assert.match(rowById(tbody, '96').textContent, /ABO Oversized Heavy Hoodie/);
    assert.match(rowById(tbody, '96').textContent, /Winter Hoodies/);
    assert.match(rowById(tbody, '96').textContent, /1,890/);
});

test('the list renders nothing when the database is empty', () => {
    const { tbody } = run([]);

    assert.equal(tbody.childNodes.length, 0);
});

test('the stat cards count the real rows', () => {
    const { document } = run();

    const count = (key) => document.querySelector(`[data-abo-admin-products-stat-count="${key}"]`).textContent;

    assert.equal(count('all'), '3');
    assert.equal(count('active'), '2');
    assert.equal(count('archived'), '1');
    // One product is under the low-stock threshold. The archived one is
    // not counted: it is not selling.
    assert.equal(count('low'), '1');
});

test('the search box narrows the list', async () => {
    const { document, tbody } = run();

    const search = document.querySelector('[data-abo-admin-products-search]');
    search.value = 'cargo';
    search.dispatchEvent('input', { target: search });

    // The search box is debounced, so the list repaints a moment later.
    await new Promise((resolve) => setTimeout(resolve, 250));

    assert.equal(tbody.childNodes.length, 1);
    assert.match(rowNames(tbody)[0], /Cargo Pant Olive/);
});

test('the category filter compares the category id', () => {
    const { document, tbody } = run();

    const filter = document.querySelector('[data-abo-admin-products-filter="category"]');
    filter.value = '96';
    filter.dispatchEvent('change', { target: filter });

    assert.equal(tbody.childNodes.length, 2);
    assert.equal(rowById(tbody, '96'), null);
});

test('the status filter finds the archived product', () => {
    const { document, tbody } = run();

    const filter = document.querySelector('[data-abo-admin-products-filter="status"]');
    filter.value = 'archived';
    filter.dispatchEvent('change', { target: filter });

    assert.equal(tbody.childNodes.length, 1);
    assert.match(rowNames(tbody)[0], /Cargo Pant Olive/);
});

test('the active toggle asks the server to flip the archive flag', async () => {
    const { tbody, calls } = run();

    const toggle = rowById(tbody, '96').querySelector('[data-abo-admin-products-toggle]');

    tbody.dispatchEvent('change', { target: toggle });

    await new Promise((resolve) => setImmediate(resolve));

    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, '/admin/products/96/toggle-archive');
    assert.equal(calls[0].options.method, 'PATCH');
});

test('the bulk bar sends the action and the selected ids', async () => {
    const { tbody, bulk, calls } = run();

    // Select one row through the row checkbox.
    const check = rowById(tbody, '96').querySelector('[data-abo-admin-products-check]');
    check.checked = true;
    tbody.dispatchEvent('change', { target: check });

    assert.equal(bulk.hidden, false);

    const archive = bulk.querySelector('[data-abo-admin-products-bulk-action="archive"]');
    click(archive);

    await new Promise((resolve) => setImmediate(resolve));

    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, '/admin/products/bulk');
    assert.equal(calls[0].options.method, 'POST');

    const body = JSON.parse(calls[0].options.body);
    assert.equal(body.action, 'archive');
    assert.deepEqual(body.ids, ['96']);
});
