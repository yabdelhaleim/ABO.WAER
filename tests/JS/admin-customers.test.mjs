// Runtime tests for the admin customers list.
//
// The customers screen used to be fed by a generator: forty invented
// customers, made-up order counts, and a segment rule that lived in the
// browser's JavaScript. These tests run public/admin/js/customers.js for
// real — against a small DOM and a fake server — so that:
//
//   - the list is built from the payload the controller sends;
//   - the segment on the badge is the one the server decided, not one
//     the browser worked out for itself;
//   - the search and the segment tabs narrow the list;
//   - saving the note and the tags reaches the server with a PUT;
//   - blocking reaches the server with a PATCH, and a block without a
//     reason never leaves the browser.
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
const customersScript = fs.readFileSync(
    path.join(projectRoot, 'public/admin/js/customers.js'),
    'utf8'
);

/**
 * Three customers, as the controller would send them: one VIP, one
 * with a single order, one who never ordered.
 *
 * The `segment` is part of the payload on purpose — the browser reads
 * it, it never derives it.
 */
const CUSTOMERS = [
    {
        id: '7', name: 'Layla Hassan', initials: 'LH',
        phone: '01000000001', email: 'layla@store.com',
        promotions: true, governorate: 'Cairo',
        ordersCount: 4, deliveredCount: 4, totalSpent: 3200,
        signupDate: '2026-01-14', lastOrder: '2026-10-02',
        blocked: false, blockReason: null, segment: 'vip',
        addresses: [
            {
                label: 'Default', name: 'Layla Hassan', phone: '01000000001',
                area: 'Dokki', address: '12 Talaat Harb Street',
                governorate: 'Cairo', isDefault: true,
            },
        ],
        recentOrders: [
            { number: 'ABO-2026-1001', date: '2026-10-02', total: 1500, status: 'delivered' },
        ],
        tags: ['Wholesale'], note: 'Calls on Fridays.',
    },
    {
        id: '8', name: 'Omar Nabil', initials: 'ON',
        phone: '01000000002', email: 'omar@store.com',
        promotions: false, governorate: 'Giza',
        ordersCount: 1, deliveredCount: 1, totalSpent: 450,
        signupDate: '2026-09-20', lastOrder: '2026-09-21',
        blocked: false, blockReason: null, segment: 'new',
        addresses: [],
        recentOrders: [],
        tags: [], note: '',
    },
    {
        id: '9', name: 'Sara Adel', initials: 'SA',
        phone: '01000000003', email: 'sara@store.com',
        promotions: false, governorate: 'Cairo',
        ordersCount: 0, deliveredCount: 0, totalSpent: 0,
        signupDate: '2026-10-10', lastOrder: null,
        blocked: true, blockReason: 'Chargeback', segment: 'blocked',
        addresses: [],
        recentOrders: [],
        tags: [], note: '',
    },
];

const ROUTES = {
    update: '/admin/customers/__CUSTOMER__',
    block: '/admin/customers/__CUSTOMER__/block',
    export: '/admin/customers/export',
    orders: '/admin/orders',
};

/**
 * Build the page the customers screen runs on.
 */
function buildPage(browser) {
    const { document, root, FakeElement } = browser;

    const el = (tag, attrs = {}, children = []) => {
        const node = new FakeElement(tag);
        for (const [k, v] of Object.entries(attrs)) {
            if (v == null || v === false) continue;
            if (k === 'class') node.className = v;
            else if (k === 'text') node.textContent = v;
            else node.setAttribute(k, v === true ? '' : String(v));
        }
        for (const c of [].concat(children)) {
            if (c == null) continue;
            node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        }
        return node;
    };

    const page = el('div', { class: 'abo-admin-page' });

    // The two payloads the page carries.
    const data = el('script', { id: 'abo-admin-customers-data' });
    const routes = el('script', { id: 'abo-admin-customers-routes' });
    page.appendChild(data);
    page.appendChild(routes);

    // The segment tabs, with their counts.
    const tabs = el('nav', {});
    for (const key of ['all', 'new', 'returning', 'vip', 'noorders', 'blocked']) {
        tabs.appendChild(el('button', {
            type: 'button', 'data-abo-admin-customers-tab': key, text: key,
        }));
        tabs.appendChild(el('span', { 'data-abo-admin-customers-tab-count': key, text: '0' }));
    }
    page.appendChild(tabs);

    // The toolbar.
    page.appendChild(el('input', { type: 'search', 'data-abo-admin-customers-search': '' }));
    page.appendChild(el('select', { 'data-abo-admin-customers-filter': 'governorate' }));
    page.appendChild(el('select', { 'data-abo-admin-customers-sort': '' }));
    page.appendChild(el('button', { type: 'button', 'data-abo-admin-customers-clear': '' }));

    // The list.
    page.appendChild(el('th', { 'data-abo-admin-customers-sort-col': 'orders' }));
    page.appendChild(el('th', { 'data-abo-admin-customers-sort-col': 'spend' }));
    const tbody = el('tbody', { 'data-abo-admin-customers-tbody': '' });
    const cards = el('ul', { 'data-abo-admin-customers-cards': '' });
    page.appendChild(tbody);
    page.appendChild(cards);

    page.appendChild(el('p', { 'data-abo-admin-customers-pagination-info': '' }));
    page.appendChild(el('ul', { 'data-abo-admin-customers-pagination-list': '' }));
    page.appendChild(el('p', { 'data-abo-admin-customers-result-count': '' }));
    page.appendChild(el('div', { 'data-abo-admin-customers-state': 'empty', hidden: true }));
    page.appendChild(el('div', { 'data-abo-admin-customers-state': 'noresults', hidden: true }));

    // The drawer.
    const drawer = el('div', { 'data-abo-admin-customers-drawer': '', hidden: true });
    drawer.appendChild(el('div', { class: 'abo-admin-customers__drawer-overlay' }));
    drawer.appendChild(el('div', { class: 'abo-admin-customers__drawer-panel' }));
    for (const name of ['avatar', 'name', 'since', 'badge', 'phone', 'email', 'promotions',
        'orders', 'spent', 'avg', 'pos']) {
        drawer.appendChild(el('span', { ['data-abo-admin-customers-drawer-' + name]: '' }));
    }
    drawer.appendChild(el('ul', { 'data-abo-admin-customers-drawer-addresses': '' }));
    drawer.appendChild(el('ul', { 'data-abo-admin-customers-drawer-orders-list': '' }));
    drawer.appendChild(el('p', { 'data-abo-admin-customers-drawer-orders-empty': '', hidden: true }));
    drawer.appendChild(el('ul', { 'data-abo-admin-customers-drawer-tags': '' }));
    drawer.appendChild(el('textarea', { 'data-abo-admin-customers-drawer-note-input': '' }));
    drawer.appendChild(el('button', { type: 'button', 'data-abo-admin-customers-drawer-note-save': '' }));
    drawer.appendChild(el('input', { 'data-abo-admin-customers-tag-input': '' }));
    drawer.appendChild(el('form', { 'data-abo-admin-customers-tag-form': '' }));
    drawer.appendChild(el('button', { type: 'button', 'data-abo-admin-customers-drawer-block': '' }));
    drawer.appendChild(el('span', { 'data-abo-admin-customers-drawer-block-label': '' }));
    drawer.appendChild(el('button', { type: 'button', 'data-abo-admin-customers-drawer-prev': '' }));
    drawer.appendChild(el('button', { type: 'button', 'data-abo-admin-customers-drawer-next': '' }));
    page.appendChild(drawer);

    // The block modal.
    const modal = el('div', { 'data-abo-admin-customers-modal': '', hidden: true });
    modal.appendChild(el('h2', { class: 'abo-admin-customers__modal-title' }));
    modal.appendChild(el('p', { 'data-abo-admin-customers-modal-body': '' }));
    modal.appendChild(el('div', { 'data-abo-admin-customers-modal-reason-field': '', hidden: true }));
    modal.appendChild(el('input', { 'data-abo-admin-customers-modal-reason': '' }));
    modal.appendChild(el('button', { type: 'button', 'data-abo-admin-customers-modal-close': '' }));
    modal.appendChild(el('button', { type: 'button', 'data-abo-admin-customers-modal-confirm': '' }));
    page.appendChild(modal);

    // The toast.
    const toast = el('div', { 'data-abo-admin-customers-toast': '', hidden: true });
    toast.appendChild(el('span', { 'data-abo-admin-customers-toast-text': '' }));
    page.appendChild(toast);

    root.appendChild(page);

    return { document, root, page, tbody, cards, drawer, modal, toast, tabs };
}

/**
 * Run the customers script against a fresh page.
 */
function run(customers = CUSTOMERS, { fetchImpl } = {}) {
    const browser = createBrowser();
    const page = buildPage(browser);

    const calls = [];
    const fetchStub = fetchImpl || ((url, options = {}) => {
        calls.push({ url, options });
        return Promise.resolve({
            ok: true,
            status: 200,
            json: () => Promise.resolve({ success: true, message: 'Customer updated.' }),
        });
    });

    page.document.getElementById('abo-admin-customers-data').textContent =
        JSON.stringify(customers);
    page.document.getElementById('abo-admin-customers-routes').textContent =
        JSON.stringify(ROUTES);

    const meta = page.document.createElement('meta');
    meta.setAttribute('name', 'csrf-token');
    meta.setAttribute('content', 'test-token');
    page.root.appendChild(meta);

    const sandbox = {
        document: page.document,
        window: browser.window,
        localStorage: browser.localStorage,
        fetch: fetchStub,
        setTimeout,
        clearTimeout,
        requestAnimationFrame: (callback) => setTimeout(callback, 0),
        console,
        URL,
        Intl,
        Date,
        JSON,
        Array,
        Object,
        String,
        Number,
        Boolean,
    };
    sandbox.globalThis = sandbox;

    const context = vm.createContext(sandbox);
    vm.runInContext(customersScript, context);

    return { ...page, calls, browser, sandbox };
}

const tabCount = (page, key) =>
    page.document.querySelector(`[data-abo-admin-customers-tab-count="${key}"]`).textContent;

/**
 * Open the drawer on one customer.
 *
 * The rows are markup, not elements — the script draws the table as a
 * string — so the drawer is opened the way the page opens it: a click
 * on something that carries the customer id.
 */
function openDrawer(page, id) {
    const button = page.document.createElement('button');
    button.setAttribute('data-abo-admin-customers-view', id);

    page.document.dispatchEvent('click', {
        target: button,
        preventDefault() {},
        stopPropagation() {},
    });
}

/**
 * Let the promises the script made settle.
 */
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

test('the list renders the customers the controller sent', () => {
    const { tbody } = run();

    assert.match(tbody.innerHTML, /Layla Hassan/);
    assert.match(tbody.innerHTML, /Omar Nabil/);
    assert.match(tbody.innerHTML, /Sara Adel/);
});

test('the segment on the badge is the one the server decided', () => {
    const { tbody } = run();

    // Layla is a VIP because the server said so — the browser has no
    // rule of its own about orders or spend.
    assert.match(tbody.innerHTML, /abo-admin-customers__badge--vip/);
    assert.match(tbody.innerHTML, /abo-admin-customers__badge--new/);
    assert.match(tbody.innerHTML, /abo-admin-customers__badge--blocked/);
});

test('a segment the server did not send is not invented in the browser', () => {
    // A customer with five delivered orders and no segment: the badge
    // falls back to the default rather than the browser deciding.
    const { tbody } = run([
        {
            ...CUSTOMERS[0], id: '99', name: 'No Segment', segment: undefined,
            ordersCount: 5, totalSpent: 99999,
        },
    ]);

    assert.doesNotMatch(tbody.innerHTML, /abo-admin-customers__badge--vip/);
});

test('the tab counts follow the payload', () => {
    const page = run();

    assert.equal(tabCount(page, 'all'), '(3)');
    assert.equal(tabCount(page, 'vip'), '(1)');
    assert.equal(tabCount(page, 'new'), '(1)');
    assert.equal(tabCount(page, 'blocked'), '(1)');
    assert.equal(tabCount(page, 'noorders'), '(0)');
});

test('the search narrows the list', () => {
    const { document, tbody } = run();

    const search = document.querySelector('[data-abo-admin-customers-search]');
    search.value = 'Omar';
    search.dispatchEvent('input', {});

    assert.match(tbody.innerHTML, /Omar Nabil/);
    assert.doesNotMatch(tbody.innerHTML, /Layla Hassan/);
});

test('a segment tab narrows the list', () => {
    const { document, tbody } = run();

    click(document.querySelector('[data-abo-admin-customers-tab="vip"]'));

    assert.match(tbody.innerHTML, /Layla Hassan/);
    assert.doesNotMatch(tbody.innerHTML, /Omar Nabil/);
});

test('saving the note sends a PUT with the note and the tags', async () => {
    const page = run();
    const { document, calls } = page;

    openDrawer(page, '7');

    const note = document.querySelector('[data-abo-admin-customers-drawer-note-input]');
    note.value = 'Ask for a discount on bulk orders.';

    click(document.querySelector('[data-abo-admin-customers-drawer-note-save]'));
    await settle();

    assert.equal(calls.length, 1);
    assert.equal(calls[0].options.method, 'PUT');
    assert.equal(calls[0].url, '/admin/customers/7');
    assert.equal(calls[0].options.headers['X-CSRF-TOKEN'], 'test-token');

    const body = JSON.parse(calls[0].options.body);
    assert.equal(body.note, 'Ask for a discount on bulk orders.');
    assert.deepEqual(body.tags, ['Wholesale']);
});

test('blocking without a reason never leaves the browser', async () => {
    const page = run();
    const { document, calls } = page;

    openDrawer(page, '8');
    click(document.querySelector('[data-abo-admin-customers-drawer-block]'));

    const confirm = document.querySelector('[data-abo-admin-customers-modal-confirm]');
    click(confirm);
    await settle();

    assert.equal(calls.length, 0);
});

test('blocking sends a PATCH with the reason', async () => {
    const page = run();
    const { document, calls } = page;

    openDrawer(page, '8');
    click(document.querySelector('[data-abo-admin-customers-drawer-block]'));

    const reason = document.querySelector('[data-abo-admin-customers-modal-reason]');
    reason.value = 'Chargeback';

    click(document.querySelector('[data-abo-admin-customers-modal-confirm]'));
    await settle();

    assert.equal(calls.length, 1);
    assert.equal(calls[0].options.method, 'PATCH');
    assert.equal(calls[0].url, '/admin/customers/8/block');

    const body = JSON.parse(calls[0].options.body);
    assert.equal(body.blocked, true);
    assert.equal(body.reason, 'Chargeback');
});

test('the order history links to the orders screen of that customer', () => {
    const page = run();

    openDrawer(page, '7');

    const orders = page.document.querySelector('[data-abo-admin-customers-drawer-orders-list]');

    assert.match(orders.innerHTML, /\/admin\/orders\?customer=7/);
});
