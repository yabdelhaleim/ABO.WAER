// Runtime tests for the admin coupons screen.
//
// The coupons screen used to be a demo: the save / duplicate / delete
// buttons toasted "(demo)" and changed nothing but the page's own
// memory, so a coupon the admin "saved" was gone with the reload.
//
// These tests run public/admin/js/coupons.js for real — against a
// small DOM and a fake server — so that:
//
//   - the list is built from the payload the controller sends;
//   - saving sends the fields the coupons table holds;
//   - the row on screen is the row the server answered with;
//   - the on/off switch, the duplicate and the delete reach the
//     server;
//   - a refused change leaves the screen as the server still sees it.
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
const couponsScript = fs.readFileSync(
    path.join(projectRoot, 'public/admin/js/coupons.js'),
    'utf8'
);

/**
 * Two coupons, as Admin\Admin\CouponService sends them.
 */
const COUPONS = [
    {
        id: '1', code: 'WELCOME10', type: 'percentage', value: 10, cap: 200,
        minimum: 500, usesMax: 100, usesPerCustomer: 1, uses: 4,
        startsAt: '2026-01-01', endsAt: '2026-12-31',
        active: true, discountGiven: 640,
    },
    {
        id: '2', code: 'FREESHIP', type: 'shipping', value: 0, cap: null,
        minimum: null, usesMax: null, usesPerCustomer: null, uses: 0,
        startsAt: '', endsAt: '',
        active: false, discountGiven: 0,
    },
];

const ROUTES = {
    store: '/admin/coupons',
    update: '/admin/coupons/__COUPON__',
    toggle: '/admin/coupons/__COUPON__/toggle',
    destroy: '/admin/coupons/__COUPON__',
};

/**
 * Build the page the coupons screen runs on.
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

    const page = el('div', { 'data-abo-admin-coupons': '' });

    // The stat cards.
    const stats = el('div', { 'data-abo-admin-coupons-stats': '' });
    for (const key of ['active', 'redemptions', 'discount']) {
        stats.appendChild(el('span', { 'data-abo-admin-coupons-stat': key, text: '0' }));
    }
    page.appendChild(stats);

    // The status tabs.
    const tabs = el('div', { 'data-abo-admin-coupons-tabs': '' });
    for (const key of ['all', 'active', 'scheduled', 'expired', 'disabled']) {
        tabs.appendChild(el('button', { type: 'button', 'data-abo-admin-coupons-tab': key, text: key }));
        tabs.appendChild(el('span', { 'data-abo-admin-coupons-tab-count': key, text: '0' }));
    }
    page.appendChild(tabs);

    // The toolbar.
    page.appendChild(el('input', { type: 'search', 'data-abo-admin-coupons-search': '' }));
    page.appendChild(el('select', { 'data-abo-admin-coupons-type': '' }));
    page.appendChild(el('button', { type: 'button', 'data-abo-admin-coupons-clear-filters': '' }));
    page.appendChild(el('p', { 'data-abo-admin-coupons-results': '' }));

    // The list and its states.
    page.appendChild(el('ul', { 'data-abo-admin-coupons-list': '' }));
    page.appendChild(el('div', { 'data-abo-admin-coupons-skeleton': '' }));
    page.appendChild(el('div', { 'data-abo-admin-coupons-state': 'empty', hidden: true }));
    page.appendChild(el('div', { 'data-abo-admin-coupons-state': 'noresults', hidden: true }));

    // The header buttons.
    page.appendChild(el('button', { type: 'button', 'data-abo-admin-coupons-add': '' }));
    page.appendChild(el('button', { type: 'button', 'data-abo-admin-coupons-add-empty': '' }));

    // The edit panel.
    const panel = el('aside', { 'data-abo-admin-coupons-panel': '' });
    panel.appendChild(el('div', { 'data-abo-admin-coupons-panel-form': '' }));
    panel.appendChild(el('div', { 'data-abo-admin-coupons-panel-no-selection': '' }));
    panel.appendChild(el('div', { 'data-abo-admin-coupons-panel-overlay': '' }));
    panel.appendChild(el('button', { type: 'button', 'data-abo-admin-coupons-panel-close': '' }));
    panel.appendChild(el('span', { 'data-abo-admin-coupons-panel-title-text': '' }));
    panel.appendChild(el('span', { 'data-abo-admin-coupons-dirty-dot': '' }));
    panel.appendChild(el('input', { 'data-abo-admin-coupons-code': '' }));
    panel.appendChild(el('p', { 'data-abo-admin-coupons-code-error': '', hidden: true }));
    panel.appendChild(el('button', { type: 'button', 'data-abo-admin-coupons-generate': '' }));

    // The type chips.
    const typeChips = el('div', { 'data-abo-admin-coupons-type-chips': '' });
    for (const value of ['percentage', 'fixed', 'shipping']) {
        typeChips.appendChild(el('button', {
            type: 'button', 'data-abo-admin-coupons-type-value': value, text: value,
        }));
    }
    panel.appendChild(typeChips);

    panel.appendChild(el('div', { 'data-abo-admin-coupons-value-field': '' }));
    panel.appendChild(el('input', { 'data-abo-admin-coupons-value': '' }));
    panel.appendChild(el('span', { 'data-abo-admin-coupons-value-suffix': '' }));
    panel.appendChild(el('p', { 'data-abo-admin-coupons-value-hint': '' }));
    panel.appendChild(el('div', { 'data-abo-admin-coupons-cap-field': '' }));
    panel.appendChild(el('input', { 'data-abo-admin-coupons-cap': '' }));
    panel.appendChild(el('input', { 'data-abo-admin-coupons-minimum': '' }));
    panel.appendChild(el('input', { 'data-abo-admin-coupons-uses-total': '' }));
    panel.appendChild(el('input', { 'data-abo-admin-coupons-uses-customer': '' }));
    panel.appendChild(el('input', { 'data-abo-admin-coupons-starts': '' }));
    panel.appendChild(el('input', { 'data-abo-admin-coupons-ends': '' }));
    panel.appendChild(el('p', { 'data-abo-admin-coupons-date-error': '', hidden: true }));
    panel.appendChild(el('input', { type: 'checkbox', 'data-abo-admin-coupons-active': '' }));
    panel.appendChild(el('p', { 'data-abo-admin-coupons-summary': '' }));
    panel.appendChild(el('button', { type: 'button', 'data-abo-admin-coupons-save': '' }));
    panel.appendChild(el('button', { type: 'button', 'data-abo-admin-coupons-duplicate': '' }));
    panel.appendChild(el('button', { type: 'button', 'data-abo-admin-coupons-delete': '' }));
    panel.appendChild(el('p', { 'data-abo-admin-coupons-delete-note': '', hidden: true }));

    page.appendChild(panel);

    // The delete modal.
    const modal = el('div', { 'data-abo-admin-coupons-modal': '', hidden: true });
    modal.appendChild(el('p', { 'data-abo-admin-coupons-modal-body': '' }));
    modal.appendChild(el('button', { type: 'button', 'data-abo-admin-coupons-modal-close': '' }));
    modal.appendChild(el('button', { type: 'button', 'data-abo-admin-coupons-modal-cancel': '' }));
    modal.appendChild(el('button', { type: 'button', 'data-abo-admin-coupons-modal-confirm': '' }));
    page.appendChild(modal);

    // The toast.
    const toast = el('div', { 'data-abo-admin-coupons-toast': '', hidden: true });
    toast.appendChild(el('span', { 'data-abo-admin-coupons-toast-text': '' }));
    toast.appendChild(el('span', { 'data-abo-admin-coupons-toast-dot': '' }));
    page.appendChild(toast);

    root.appendChild(page);

    return { document, root, page, panel, modal, toast, tabs };
}

/**
 * Run the coupons script against a fresh page.
 */
function run({ coupons = COUPONS, fetchImpl } = {}) {
    const browser = createBrowser();
    const page = buildPage(browser);

    const calls = [];
    const fetchStub = fetchImpl || ((url, options = {}) => {
        calls.push({ url, options });
        return Promise.resolve({
            ok: true,
            status: 200,
            json: () => Promise.resolve({ success: true, message: 'Coupon saved.' }),
        });
    });

    browser.window.__COUPONS_DATA__ = JSON.parse(JSON.stringify(coupons));
    browser.window.__COUPON_ROUTES__ = ROUTES;
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
        // The screen waits 600 ms before it draws the list. The timers
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
    };
    sandbox.globalThis = sandbox;

    const context = vm.createContext(sandbox);
    vm.runInContext(couponsScript, context);

    return { ...page, calls, browser, sandbox };
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 20));

const list = (page) => page.document.querySelector('[data-abo-admin-coupons-list]');
const tabCount = (page, key) =>
    page.document.querySelector(`[data-abo-admin-coupons-tab-count="${key}"]`).textContent;

/**
 * Select the first coupon in the list.
 */
function selectFirst(page) {
    const row = list(page).querySelector('[data-abo-admin-coupons-item]');
    click(row);
}

test('the list is built from the coupons the controller sent', async () => {
    const page = run();
    await settle();

    assert.match(list(page).textContent, /WELCOME10/);
    assert.match(list(page).textContent, /FREESHIP/);
});

test('the tab counts come from the payload', async () => {
    const page = run();
    await settle();

    assert.equal(tabCount(page, 'all'), '2');
    assert.equal(tabCount(page, 'active'), '1');
    assert.equal(tabCount(page, 'disabled'), '1');
});

test('saving sends the fields the coupons table holds', async () => {
    const page = run();

    await settle();
    selectFirst(page);

    const code = page.document.querySelector('[data-abo-admin-coupons-code]');
    code.value = 'WELCOME15';
    code.dispatchEvent('input', {});

    click(page.document.querySelector('[data-abo-admin-coupons-save]'));
    await settle();

    assert.equal(page.calls.length, 1);

    const call = page.calls[0];
    assert.equal(call.url, '/admin/coupons/1');
    assert.equal(call.options.method, 'PUT');

    const body = JSON.parse(call.options.body);
    assert.equal(body.code, 'WELCOME15');
    assert.equal(body.type, 'percentage');
    assert.equal(body.value, 10);
    assert.equal(body.max_discount, 200);
    assert.equal(body.min_order_total, 500);
    assert.equal(body.total_usage_limit, 100);
    assert.equal(body.usage_per_customer_limit, 1);
    assert.equal(body.starts_at, '2026-01-01');
    assert.equal(body.expires_at, '2026-12-31');
    assert.equal(body.is_active, true);
    assert.equal(call.options.headers['X-CSRF-TOKEN'], 'test-token');
});

test('the row on screen is the row the server answered with', async () => {
    const page = run({
        fetchImpl: (url, options) => {
            page.calls.push({ url, options });
            return Promise.resolve({
                ok: true,
                status: 200,
                json: () => Promise.resolve({
                    success: true,
                    message: 'Coupon saved.',
                    coupon: { ...COUPONS[0], code: 'WELCOME15' },
                }),
            });
        },
    });

    await settle();
    selectFirst(page);
    click(page.document.querySelector('[data-abo-admin-coupons-save]'));
    await settle();

    assert.match(list(page).textContent, /WELCOME15/);
});

test('a refused save shows the message the server sent', async () => {
    const page = run({
        fetchImpl: (url, options) => {
            page.calls.push({ url, options });
            return Promise.resolve({
                ok: false,
                status: 422,
                json: () => Promise.resolve({
                    success: false,
                    message: 'The code has already been taken.',
                    errors: { code: ['The code has already been taken.'] },
                }),
            });
        },
    });

    await settle();
    selectFirst(page);
    click(page.document.querySelector('[data-abo-admin-coupons-save]'));
    await settle();

    const toast = page.document.querySelector('[data-abo-admin-coupons-toast-text]');
    assert.equal(toast.textContent, 'The code has already been taken.');
    assert.match(list(page).textContent, /WELCOME10/);
});

test('the on/off switch reaches the server', async () => {
    const page = run({
        fetchImpl: (url, options) => {
            page.calls.push({ url, options });
            return Promise.resolve({
                ok: true,
                status: 200,
                json: () => Promise.resolve({
                    success: true,
                    message: 'Coupon status updated.',
                    coupon: { ...COUPONS[1], active: true },
                }),
            });
        },
    });

    await settle();

    const toggle = list(page).querySelector('[data-abo-admin-coupons-row-toggle="2"]');
    toggle.checked = true;

    click(toggle);
    await settle();

    assert.equal(page.calls.length, 1);
    assert.equal(page.calls[0].url, '/admin/coupons/2/toggle');
    assert.equal(page.calls[0].options.method, 'PATCH');
});

test('deleting asks the server and drops the row when it answers', async () => {
    const page = run({
        fetchImpl: (url, options) => {
            page.calls.push({ url, options });
            return Promise.resolve({
                ok: true,
                status: 200,
                json: () => Promise.resolve({ success: true, message: 'Coupon deleted successfully.' }),
            });
        },
    });

    await settle();
    selectFirst(page);
    click(page.document.querySelector('[data-abo-admin-coupons-delete]'));
    click(page.document.querySelector('[data-abo-admin-coupons-modal-confirm]'));
    await settle();

    assert.equal(page.calls.length, 1);
    assert.equal(page.calls[0].url, '/admin/coupons/1');
    assert.equal(page.calls[0].options.method, 'DELETE');
    assert.doesNotMatch(list(page).textContent, /WELCOME10/);
});

test('duplicating creates a second coupon on the server', async () => {
    const page = run({
        fetchImpl: (url, options) => {
            page.calls.push({ url, options });
            return Promise.resolve({
                ok: true,
                status: 201,
                json: () => Promise.resolve({
                    success: true,
                    message: 'Coupon created successfully.',
                    coupon: { ...COUPONS[0], id: '3', code: 'WELCOME10-COPY', active: false },
                }),
            });
        },
    });

    await settle();
    selectFirst(page);
    click(page.document.querySelector('[data-abo-admin-coupons-duplicate]'));
    await settle();

    assert.equal(page.calls.length, 1);
    assert.equal(page.calls[0].url, '/admin/coupons');
    assert.equal(page.calls[0].options.method, 'POST');
    assert.equal(JSON.parse(page.calls[0].options.body).code, 'WELCOME10-COPY');
    assert.equal(JSON.parse(page.calls[0].options.body).is_active, false);
    assert.match(list(page).textContent, /WELCOME10-COPY/);
});
