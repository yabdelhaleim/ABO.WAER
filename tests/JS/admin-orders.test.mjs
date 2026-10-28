// Runtime tests for the admin orders screen.
//
// The EXPORT CSV button on the orders screen was the last admin
// control that was still a demo: it toasted "Export started (demo)"
// and never produced a file. It is now a link to the export endpoint,
// and the link carries the filters the screen is showing — so the
// file the admin downloads holds the rows they are looking at.
//
// These tests run public/admin/js/orders.js for real, against a small
// DOM, and check that:
//
//   - the export link points at the server's endpoint, not at "#";
//   - choosing a status tab puts that tab in the link;
//   - typing in the search box puts the search in the link;
//   - the customer the screen was narrowed to travels with the link;
//   - clearing the filters takes them back out of the link.
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
const ordersScript = fs.readFileSync(
    path.join(projectRoot, 'public/admin/js/orders.js'),
    'utf8'
);

/**
 * Two orders, in the shape Admin\OrderService serialises them.
 */
const ORDERS = [
    {
        id: 'ABO-2026-1001',
        createdAt: '2026-10-20T12:00:00',
        customer: {
            id: '7',
            name: 'Layla Hassan',
            phone: '01000000001',
            email: 'layla@example.com',
            address: {
                governorate: 'Cairo',
                city: 'Nasr City',
                street: '10 Mohamed Farid',
                building: '',
                notes: '',
            },
        },
        deliveryMethod: 'Standard delivery',
        items: [
            { name: 'Boxy T-Shirt', color: 'Black', size: 'L', qty: 2, unitPrice: 100, lineTotal: 200 },
        ],
        itemsCount: 2,
        subtotal: 200,
        shipping: 50,
        discount: 0,
        coupon: '',
        total: 250,
        payment: { method: 'cod', status: 'pending' },
        status: 'new',
        timeline: [{ at: '2026-10-20T12:00:00', label: 'New' }],
        notes: [],
    },
    {
        id: 'ABO-2026-1002',
        createdAt: '2026-10-21T09:30:00',
        customer: {
            id: '8',
            name: 'Omar Nabil',
            phone: '01000000002',
            email: 'omar@example.com',
            address: {
                governorate: 'Giza',
                city: 'Dokki',
                street: '5 Tahrir',
                building: '',
                notes: '',
            },
        },
        deliveryMethod: 'Standard delivery',
        items: [
            { name: 'Oversize Hoodie', color: 'Sand', size: 'M', qty: 1, unitPrice: 400, lineTotal: 400 },
        ],
        itemsCount: 1,
        subtotal: 400,
        shipping: 50,
        discount: 0,
        coupon: '',
        total: 450,
        payment: { method: 'cod', status: 'pending' },
        status: 'delivered',
        timeline: [{ at: '2026-10-21T09:30:00', label: 'Delivered' }],
        notes: [],
    },
];

/**
 * The route map Admin\OrderController renders.
 */
const ROUTES = {
    status: { url: '/admin/orders/__ORDER__/status', method: 'PATCH' },
    cancel: { url: '/admin/orders/__ORDER__/cancel', method: 'POST' },
    tracking: { url: '/admin/orders/__ORDER__/tracking', method: 'PATCH' },
    export: { url: '/admin/orders/export' },
};

/**
 * The orders page, with the hooks orders.js reads.
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

    const page = el('div', { class: 'abo-admin-page' });

    // The three JSON the controller renders.
    const data = el('script', { id: 'abo-admin-orders-data', type: 'application/json' });
    data.textContent = JSON.stringify(ORDERS);
    page.appendChild(data);

    const routes = el('script', { id: 'abo-admin-orders-routes', type: 'application/json' });
    routes.textContent = JSON.stringify(ROUTES);
    page.appendChild(routes);

    const customerFilter = el('script', { id: 'abo-admin-orders-customer-filter', type: 'application/json' });
    customerFilter.textContent = 'null';
    page.appendChild(customerFilter);

    page.appendChild(el('p', { id: 'abo-admin-today-date' }));

    // The export control — a link, the way the customers screen does it.
    page.appendChild(el('a', {
        href: ROUTES.export.url,
        'data-abo-admin-orders-action': 'export',
    }));

    // The status tabs.
    for (const tab of ['all', 'new', 'processing', 'shipped', 'delivered', 'cancelled', 'returned']) {
        const button = el('button', { type: 'button', 'data-abo-admin-orders-tab': tab });
        button.appendChild(el('span', { 'data-abo-admin-orders-tab-count': tab }));
        page.appendChild(button);
    }

    // The toolbar.
    page.appendChild(el('input', { type: 'search', 'data-abo-admin-orders-search': '' }));
    page.appendChild(el('select', { 'data-abo-admin-orders-filter': 'payment' }));
    page.appendChild(el('select', { 'data-abo-admin-orders-filter': 'governorate' }));
    page.appendChild(el('input', { type: 'date', 'data-abo-admin-orders-filter': 'dateFrom' }));
    page.appendChild(el('input', { type: 'date', 'data-abo-admin-orders-filter': 'dateTo' }));
    page.appendChild(el('button', { type: 'button', 'data-abo-admin-orders-clear': '' }));

    // The sortable headers.
    page.appendChild(el('th', { 'data-abo-admin-orders-sort': 'date' }));
    page.appendChild(el('th', { 'data-abo-admin-orders-sort': 'total' }));

    // The table and the card list.
    page.appendChild(el('input', { type: 'checkbox', 'data-abo-admin-orders-check-all': '' }));
    page.appendChild(el('tbody', { 'data-abo-admin-orders-tbody': '' }));
    page.appendChild(el('ul', { 'data-abo-admin-orders-cards': '' }));

    // The pagination and the states.
    page.appendChild(el('p', { 'data-abo-admin-orders-pagination-info': '' }));
    page.appendChild(el('ul', { 'data-abo-admin-orders-pagination-list': '' }));
    page.appendChild(el('p', { 'data-abo-admin-orders-result-count': '' }));
    page.appendChild(el('div', { 'data-abo-admin-orders-state': 'empty', hidden: true }));
    page.appendChild(el('div', { 'data-abo-admin-orders-state': 'noresults', hidden: true }));

    // The bulk bar.
    const bulk = el('div', { 'data-abo-admin-orders-bulk': '', hidden: true });
    bulk.appendChild(el('span', { 'data-abo-admin-orders-bulk-count': '' }));
    page.appendChild(bulk);

    // The toast.
    const toast = el('div', { 'data-abo-admin-orders-toast': '', hidden: true });
    toast.appendChild(el('span', { 'data-abo-admin-orders-toast-text': '' }));
    page.appendChild(toast);

    root.appendChild(page);

    return { document, root, page };
}

/**
 * Run orders.js against the page.
 *
 * @param {{ customer?: object|null }} options
 */
function run({ customer = null } = {}) {
    const browser = createBrowser();
    const page = buildPage(browser);

    const filterEl = page.document.getElementById('abo-admin-orders-customer-filter');
    filterEl.textContent = JSON.stringify(customer);

    const sandbox = {
        document: page.document,
        window: browser.window,
        localStorage: browser.localStorage,
        fetch: () => Promise.reject(new Error('no network in this test')),
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
        URLSearchParams,
    };
    sandbox.globalThis = sandbox;

    const context = vm.createContext(sandbox);
    vm.runInContext(ordersScript, context);

    return { ...page, browser, sandbox };
}

/**
 * The export link's href, with its query parsed back apart.
 */
function exportQuery(page) {
    const link = page.document.querySelector('[data-abo-admin-orders-action="export"]');
    const href = link.getAttribute('href');
    const query = href.includes('?') ? href.slice(href.indexOf('?') + 1) : '';

    return {
        href,
        params: new URLSearchParams(query),
    };
}

test('the export link points at the export endpoint', () => {
    const page = run();

    const { href, params } = exportQuery(page);

    // The link names the endpoint the server answers; the sort that
    // goes with it is the one the list is showing.
    assert.ok(href.startsWith('/admin/orders/export'));
    assert.equal(params.get('sort'), 'date');
    assert.equal(params.get('dir'), 'desc');
    assert.equal(params.get('status'), null);
});

test('choosing a status tab puts that tab in the export link', () => {
    const page = run();

    const tab = page.document.querySelector('[data-abo-admin-orders-tab="delivered"]');
    click(tab);

    const { params } = exportQuery(page);

    assert.equal(params.get('status'), 'delivered');
});

test('typing in the search box puts the search in the export link', () => {
    const page = run();

    const search = page.document.querySelector('[data-abo-admin-orders-search]');
    search.value = 'Layla';
    search.dispatchEvent('input', {});

    const { params } = exportQuery(page);

    assert.equal(params.get('q'), 'Layla');
});

test('the customer the screen was narrowed to travels with the export link', () => {
    const page = run({ customer: { id: '7', name: 'Layla Hassan' } });

    const { params } = exportQuery(page);

    assert.equal(params.get('customer'), '7');
});

test('clearing the filters takes them back out of the export link', () => {
    const page = run();

    const tab = page.document.querySelector('[data-abo-admin-orders-tab="delivered"]');
    click(tab);

    const search = page.document.querySelector('[data-abo-admin-orders-search]');
    search.value = 'Layla';
    search.dispatchEvent('input', {});

    const clear = page.document.querySelector('[data-abo-admin-orders-clear]');
    click(clear);

    const { params } = exportQuery(page);

    assert.equal(params.get('status'), null);
    assert.equal(params.get('q'), null);
});
