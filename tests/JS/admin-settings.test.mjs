// Runtime tests for the admin settings screen.
//
// The screen used to draw a static array out of its Blade view and
// keep every change in the browser: SAVE showed a "Saved (demo)"
// toast and the values were gone with the page. It now draws the
// settings the server stored, sends the operator's changes back, and
// puts the server's answer on screen.
//
// These tests run public/admin/js/settings.js for real — against a
// small DOM and a fake server — and check that:
//
//   - the fields carry the values the server sent;
//   - SAVE asks the server and sends what the operator changed;
//   - a saved screen shows the server's answer and closes the bar;
//   - a refused save keeps the change and says why;
//   - RESTORE DEFAULTS asks the server to put the defaults back.
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
const settingsScript = fs.readFileSync(
    path.join(projectRoot, 'public/admin/js/settings.js'),
    'utf8'
);

/**
 * The settings, as Admin\SettingsService sends them.
 */
const PAYLOAD = {
    store: {
        name: 'ABO',
        phone: '',
        whatsapp: '',
        email: '',
        address: '',
        logo: '',
        favicon: '',
        social: { instagram: '', facebook: '', tiktok: '' },
        maintenance: false,
    },
    orders: {
        minimum: 0,
        autoCancelHours: 24,
        guestCheckout: true,
        prefix: 'ABO-',
        nextNumber: 'ABO-2026-1001',
    },
    inventory: { lowStockThreshold: 5, hideOutOfStock: false },
    reasons: [
        { id: 1, en: 'Changed my mind', ar: 'غيّرت رأيي', active: true },
        { id: 2, en: 'Wrong size ordered', ar: 'مقاس خاطئ', active: true },
    ],
    receipts: {
        showLogo: true,
        headerText: 'ABO Store',
        footerNote: '',
        fields: {
            orderNumber: true,
            date: true,
            customerPhone: true,
            address: false,
            paymentMethod: true,
            shippingFee: true,
        },
        paper: 'a4',
    },
    notifications: {
        newOrder: true,
        lowStock: true,
        returnRequest: true,
        shipmentFailed: false,
        couponExpiring: false,
        browserSound: true,
    },
};

/**
 * The routes the controller renders.
 */
const ROUTES = {
    save: '/admin/settings',
    branding: '/admin/settings/branding',
    reset: '/admin/settings/reset',
};

/**
 * The settings page, with the hooks settings.js reads.
 */
function buildPage(browser, payload) {
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
            node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
        }
        return node;
    };

    const page = el('div', { class: 'abo-admin-page' });

    // The CSRF token the request carries.
    page.appendChild(el('meta', { name: 'csrf-token', content: 'test-token' }));

    const payloadTag = el('script', { 'data-abo-admin-settings-payload': '' });
    payloadTag.textContent = JSON.stringify(payload);
    page.appendChild(payloadTag);

    const routesTag = el('script', { 'data-abo-admin-settings-routes': '' });
    routesTag.textContent = JSON.stringify(ROUTES);
    page.appendChild(routesTag);

    page.appendChild(el('section', { 'data-abo-admin-settings-skeleton': '' }));

    const settings = el('section', { 'data-abo-admin-settings-root': '' });
    settings.hidden = true;

    // The sub-nav.
    const nav = el('nav', { 'data-abo-admin-settings-nav': '' });
    nav.appendChild(el('p', { class: 'abo-admin-settings__nav-meta' }));
    nav.appendChild(el('span', { 'data-abo-admin-settings-status': '' }));
    for (const id of ['store-info', 'orders', 'inventory', 'cancellation', 'receipts', 'notifications']) {
        nav.appendChild(el('a', { 'data-abo-admin-settings-tab': id, href: '#' + id }));
    }
    settings.appendChild(nav);

    // The panels.
    for (const id of ['store-info', 'orders', 'inventory', 'cancellation', 'receipts', 'notifications']) {
        settings.appendChild(el('article', { 'data-abo-admin-settings-panel': id }));
    }

    // The fields the screen edits.
    const field = (path, type = 'text') => {
        const input = el('input', { type, 'data-abo-admin-settings-field': path });
        settings.appendChild(input);
        return input;
    };

    field('store.name');
    field('store.phone');
    field('store.email');
    field('store.maintenance', 'checkbox');
    field('orders.minimum', 'number');
    field('orders.autoCancelHours', 'number');
    field('orders.guestCheckout', 'checkbox');
    field('orders.prefix');
    field('inventory.lowStockThreshold', 'number');
    field('inventory.hideOutOfStock', 'checkbox');
    field('receipts.showLogo', 'checkbox');
    field('receipts.headerText');
    field('receipts.footerNote');
    field('receipts.paper', 'radio');
    field('receipts.fields.orderNumber', 'checkbox');
    field('receipts.fields.date', 'checkbox');
    field('notifications.newOrder', 'checkbox');
    field('notifications.lowStock', 'checkbox');

    // The preview bits the screen keeps in sync. The order-number
    // preview is rendered by the view with the number the server
    // measured; the screen only replaces it when the prefix changes.
    const nextNumber = el('code', { 'data-abo-admin-settings-preview': 'orders.nextNumber' });
    nextNumber.textContent = payload.orders.nextNumber;
    settings.appendChild(nextNumber);
    settings.appendChild(el('span', { 'data-abo-admin-settings-receipt-store': '' }));
    settings.appendChild(el('span', { 'data-abo-admin-settings-receipt-footer': '' }));
    settings.appendChild(el('div', { 'data-abo-admin-settings-receipt-logo': '' }));
    settings.appendChild(el('aside', { 'data-abo-admin-settings-receipt-preview': '' }));
    settings.appendChild(el('span', { 'data-abo-admin-settings-banner': 'maintenance' }));

    // The cancellation reasons.
    settings.appendChild(el('ul', { 'data-abo-admin-settings-reasons': '' }));
    settings.appendChild(el('button', { type: 'button', 'data-abo-admin-settings-action': 'add-reason' }));

    // The save bar.
    const savebar = el('section', { 'data-abo-admin-settings-savebar': '' });
    savebar.hidden = true;
    settings.appendChild(savebar);
    settings.appendChild(el('button', { type: 'button', 'data-abo-admin-settings-action': 'discard' }));
    settings.appendChild(el('button', { type: 'button', 'data-abo-admin-settings-action': 'save' }));
    settings.appendChild(el('button', { type: 'button', 'data-abo-admin-settings-action': 'restore' }));

    // The toast.
    const toast = el('div', { 'data-abo-admin-settings-toast': '' });
    toast.hidden = true;
    toast.appendChild(el('span', { 'data-abo-admin-settings-toast-dot': '' }));
    toast.appendChild(el('span', { 'data-abo-admin-settings-toast-text': '' }));
    settings.appendChild(toast);

    // The confirm modal.
    const modal = el('div', { 'data-abo-admin-settings-modal': '' });
    modal.hidden = true;
    modal.appendChild(el('p', { 'data-abo-admin-settings-modal-body': '' }));
    modal.appendChild(el('button', { type: 'button', 'data-abo-admin-settings-modal-close': '' }));
    modal.appendChild(el('button', { type: 'button', 'data-abo-admin-settings-modal-confirm': '' }));
    settings.appendChild(modal);

    page.appendChild(settings);
    root.appendChild(page);

    return { document, root, page };
}

/**
 * Run settings.js against the page.
 *
 * @param {{ payload?: object, respond?: (call) => object }} options
 */
function run({ payload = PAYLOAD, respond } = {}) {
    const browser = createBrowser();
    const page = buildPage(browser, payload);

    const calls = [];

    const fetchStub = async (url, options = {}) => {
        const body = options.body ? JSON.parse(options.body) : null;
        calls.push({ url, method: options.method || 'GET', body });

        const answer = respond ? respond({ url, method: options.method, body }) : null;
        const payload = answer === null
            ? { success: true, message: 'Settings saved.', settings: PAYLOAD }
            : answer;

        return {
            ok: payload.success !== false,
            status: payload.success === false ? 422 : 200,
            json: async () => payload,
        };
    };

    const sandbox = {
        document: page.document,
        window: { ...browser.window, location: { href: '' } },
        localStorage: browser.localStorage,
        fetch: fetchStub,
        setTimeout: (callback, delay) => setTimeout(callback, Math.min(delay || 0, 1)),
        clearTimeout,
        requestAnimationFrame: (callback) => setTimeout(callback, 0),
        history: { replaceState() {} },
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
        Math,
        Promise,
        Error,
        encodeURIComponent,
    };
    sandbox.globalThis = sandbox;

    const context = vm.createContext(sandbox);
    vm.runInContext(settingsScript, context);

    // The screen boots when the document is ready.
    page.document.dispatchEvent('DOMContentLoaded', {});

    return { ...page, calls, browser, sandbox };
}

/** Let the promise chains the screen starts settle. */
const tick = () => new Promise((resolve) => setTimeout(resolve, 5));

/**
 * The text of the toast.
 */
function toastText(page) {
    const node = page.document.querySelector('[data-abo-admin-settings-toast-text]');
    return node ? node.textContent : null;
}

test('the fields carry the values the server sent', () => {
    const page = run();

    const name = page.document.querySelector('[data-abo-admin-settings-field="store.name"]');
    const prefix = page.document.querySelector('[data-abo-admin-settings-field="orders.prefix"]');
    const threshold = page.document.querySelector('[data-abo-admin-settings-field="inventory.lowStockThreshold"]');

    assert.equal(name.value, 'ABO');
    assert.equal(prefix.value, 'ABO-');
    assert.equal(threshold.value, 5);
});

test('the order-number preview is the number the server measured', () => {
    const page = run();

    const preview = page.document.querySelector('[data-abo-admin-settings-preview="orders.nextNumber"]');
    assert.equal(preview.textContent, 'ABO-2026-1001');
});

test('a new prefix moves in front of the number the server measured', () => {
    const page = run();

    const prefix = page.document.querySelector('[data-abo-admin-settings-field="orders.prefix"]');
    prefix.value = 'ABOX-';
    prefix.dispatchEvent('input', {});

    const preview = page.document.querySelector('[data-abo-admin-settings-preview="orders.nextNumber"]');
    assert.equal(preview.textContent, 'ABOX-2026-1001');
});

test('SAVE asks the server and sends what the operator changed', async () => {
    const page = run();

    const name = page.document.querySelector('[data-abo-admin-settings-field="store.name"]');
    name.value = 'ABO Streetwear';
    name.dispatchEvent('input', {});

    click(page.document.querySelector('[data-abo-admin-settings-action="save"]'));
    await tick();

    assert.equal(page.calls.length, 1);
    assert.equal(page.calls[0].method, 'PUT');
    assert.equal(page.calls[0].url, '/admin/settings');
    assert.equal(page.calls[0].body.store.name, 'ABO Streetwear');
    assert.equal(page.calls[0].body.orders.prefix, 'ABO-');
});

test('a saved screen shows the server’s answer and closes the bar', async () => {
    const page = run({
        respond: () => ({
            success: true,
            message: 'Settings saved.',
            settings: {
                ...PAYLOAD,
                store: { ...PAYLOAD.store, name: 'ABO Streetwear' },
            },
        }),
    });

    const name = page.document.querySelector('[data-abo-admin-settings-field="store.name"]');
    name.value = 'ABO Streetwear';
    name.dispatchEvent('input', {});

    click(page.document.querySelector('[data-abo-admin-settings-action="save"]'));
    await tick();

    assert.equal(toastText(page), 'Settings saved.');

    const bar = page.document.querySelector('[data-abo-admin-settings-savebar]');
    assert.equal(bar.hidden, true);

    // The field shows the server's answer.
    assert.equal(name.value, 'ABO Streetwear');
});

test('a refused save keeps the change and says why', async () => {
    const page = run({
        respond: () => ({ success: false, message: 'The minimum order value is required.' }),
    });

    const name = page.document.querySelector('[data-abo-admin-settings-field="store.name"]');
    name.value = 'ABO Streetwear';
    name.dispatchEvent('input', {});

    click(page.document.querySelector('[data-abo-admin-settings-action="save"]'));
    await tick();

    assert.equal(toastText(page), 'The minimum order value is required.');

    // The change is still on screen — nothing was thrown away.
    assert.equal(name.value, 'ABO Streetwear');

    const bar = page.document.querySelector('[data-abo-admin-settings-savebar]');
    assert.equal(bar.hidden, false);
});

test('RESTORE DEFAULTS asks the server to put the defaults back', async () => {
    const page = run({
        respond: () => ({
            success: true,
            message: 'Settings restored to their defaults.',
            settings: {
                ...PAYLOAD,
                store: { ...PAYLOAD.store, name: 'ABO' },
                reasons: [],
            },
        }),
    });

    click(page.document.querySelector('[data-abo-admin-settings-action="restore"]'));
    await tick();

    // The modal asks before anything is written.
    assert.equal(page.calls.length, 0);

    click(page.document.querySelector('[data-abo-admin-settings-modal-confirm]'));
    await tick();

    assert.equal(page.calls.length, 1);
    assert.equal(page.calls[0].method, 'POST');
    assert.equal(page.calls[0].url, '/admin/settings/reset');
    assert.equal(toastText(page), 'Settings restored to their defaults.');
});

test('the save bar appears only when something changed', () => {
    const page = run();

    const bar = page.document.querySelector('[data-abo-admin-settings-savebar]');
    assert.equal(bar.hidden, true);

    const name = page.document.querySelector('[data-abo-admin-settings-field="store.name"]');
    name.value = 'ABO Streetwear';
    name.dispatchEvent('input', {});

    assert.equal(bar.hidden, false);
});
