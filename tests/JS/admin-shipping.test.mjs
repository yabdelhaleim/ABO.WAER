// Runtime tests for the admin shipping screen.
//
// The shipping screen used to be a demo: saving a zone or a
// governorate toasted "(demo)" and changed nothing but the page's own
// memory, so the tree the admin saw was gone with the reload.
//
// These tests run public/admin/js/shipping.js for real — against a
// small DOM and a fake server — so that:
//
//   - the tree is built from the zones the controller sends;
//   - saving a zone or a governorate sends the columns the tables hold;
//   - the row on screen is the row the server answered with;
//   - the on/off switches, the delete and the drag-to-reorder reach
//     the server;
//   - the global settings are written to the shipping_settings table.
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
const shippingScript = fs.readFileSync(
    path.join(projectRoot, 'public/admin/js/shipping.js'),
    'utf8'
);

/**
 * Two zones and two governorates, as Admin\ShippingController sends them.
 */
const ZONES = [
    {
        id: 'z-1', dbId: 1, name: 'Greater Cairo', nameAr: 'القاهرة الكبرى',
        fee: 50, timeFrom: 1, timeTo: 3, active: true,
        governorates: [
            {
                id: 'g-11', dbId: 11, parentId: 'z-1', name: 'Cairo', nameAr: 'القاهرة',
                fee: null, timeFrom: null, timeTo: null, cod: true, active: true,
            },
            {
                id: 'g-12', dbId: 12, parentId: 'z-1', name: 'Giza', nameAr: 'الجيزة',
                fee: 60, timeFrom: 2, timeTo: 4, cod: false, active: false,
            },
        ],
    },
    {
        id: 'z-2', dbId: 2, name: 'Upper Egypt', nameAr: 'الوجه القبلي',
        fee: 75, timeFrom: 3, timeTo: 6, active: false,
        governorates: [],
    },
];

/**
 * The global shipping settings, as the shipping_settings table holds them.
 */
const SETTINGS = {
    free_shipping_enabled: true,
    free_shipping_threshold: 1500,
    cod_fee: 20,
    default_fee: 75,
    note_en: 'Delivery within 2 to 5 working days.',
    note_ar: 'التوصيل خلال 2 إلى 5 أيام عمل.',
};

const ROUTES = {
    settings: '/admin/shipping/settings',
    zoneStore: '/admin/shipping/zones',
    zoneUpdate: '/admin/shipping/zones/__ZONE__',
    zoneDestroy: '/admin/shipping/zones/__ZONE__',
    zoneReorder: '/admin/shipping/zones/__ZONE__/reorder',
    governorateStore: '/admin/shipping/governorates',
    governorateUpdate: '/admin/shipping/governorates/__GOVERNORATE__',
    governorateDestroy: '/admin/shipping/governorates/__GOVERNORATE__',
};

/**
 * Build the page the shipping screen runs on.
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

    const page = el('div', { 'data-abo-admin-shipping': '' });

    // The toolbar.
    page.appendChild(el('input', { type: 'search', 'data-abo-admin-shipping-search': '' }));
    page.appendChild(el('button', { type: 'button', 'data-abo-admin-shipping-expand-all': '' }));
    page.appendChild(el('button', { type: 'button', 'data-abo-admin-shipping-add': '' }));

    // The tree and its states.
    page.appendChild(el('ul', { role: 'tree', 'data-abo-admin-shipping-tree': '' }));
    page.appendChild(el('p', { 'data-abo-admin-shipping-helper': '' }));
    page.appendChild(el('div', { 'data-abo-admin-shipping-skeleton': '' }));
    page.appendChild(el('div', { 'data-abo-admin-shipping-state': 'empty', hidden: true }));
    page.appendChild(el('div', { 'data-abo-admin-shipping-state': 'noresults', hidden: true }));
    page.appendChild(el('button', { type: 'button', 'data-abo-admin-shipping-add-empty': '' }));

    // The edit panel.
    const panel = el('aside', { 'data-abo-admin-shipping-panel': '' });
    panel.appendChild(el('div', { 'data-abo-admin-shipping-panel-overlay': '' }));
    panel.appendChild(el('div', { 'data-abo-admin-shipping-panel-no-selection': '' }));
    panel.appendChild(el('button', { type: 'button', 'data-abo-admin-shipping-panel-close': '' }));
    panel.appendChild(el('span', { 'data-abo-admin-shipping-panel-title-text': '' }));
    panel.appendChild(el('span', { 'data-abo-admin-shipping-dirty-dot': '' }));

    const form = el('div', { 'data-abo-admin-shipping-panel-form': '', hidden: true });

    // Zone fields.
    for (const group of ['zone-name', 'zone-fee', 'zone-time', 'zone-chips', 'gov-header',
        'gov-zone', 'gov-fee', 'gov-time']) {
        form.appendChild(el('div', { 'data-abo-admin-shipping-field': group, hidden: true }));
    }
    for (const group of ['zone-active', 'gov-cod', 'gov-active']) {
        form.appendChild(el('div', { 'data-abo-admin-shipping-switch': group, hidden: true }));
    }

    const lang = el('div', { 'data-abo-admin-shipping-zone-lang': '' });
    lang.appendChild(el('button', { type: 'button', 'data-lang': 'en', text: 'EN' }));
    lang.appendChild(el('button', { type: 'button', 'data-lang': 'ar', text: 'AR' }));
    form.appendChild(lang);

    form.appendChild(el('input', { 'data-abo-admin-shipping-zone-name': '', 'data-lang': 'en' }));
    form.appendChild(el('input', { 'data-abo-admin-shipping-zone-name': '', 'data-lang': 'ar', hidden: true }));
    form.appendChild(el('input', { 'data-abo-admin-shipping-zone-fee': '' }));
    form.appendChild(el('input', { 'data-abo-admin-shipping-time-from': '' }));
    form.appendChild(el('input', { 'data-abo-admin-shipping-time-to': '' }));
    form.appendChild(el('input', { type: 'checkbox', 'data-abo-admin-shipping-zone-active': '' }));
    form.appendChild(el('ul', { 'data-abo-admin-shipping-zone-chips': '' }));

    // Governorate fields.
    form.appendChild(el('p', { 'data-abo-admin-shipping-gov-name': '' }));
    form.appendChild(el('select', { 'data-abo-admin-shipping-gov-zone': '' }));

    const feeMode = el('div', {});
    const inherit = el('input', {
        type: 'radio', value: 'inherit', 'data-abo-admin-shipping-gov-fee-mode': '',
    });
    const custom = el('input', {
        type: 'radio', value: 'custom', 'data-abo-admin-shipping-gov-fee-mode': '',
    });
    feeMode.appendChild(inherit);
    feeMode.appendChild(custom);
    form.appendChild(feeMode);

    form.appendChild(el('span', { 'data-abo-admin-shipping-gov-fee-inherit': '' }));
    form.appendChild(el('div', { 'data-abo-admin-shipping-gov-fee-wrap': '', hidden: true }));
    form.appendChild(el('input', { 'data-abo-admin-shipping-gov-fee': '' }));
    form.appendChild(el('input', { 'data-abo-admin-shipping-gov-time-from': '' }));
    form.appendChild(el('input', { 'data-abo-admin-shipping-gov-time-to': '' }));
    form.appendChild(el('input', { type: 'checkbox', 'data-abo-admin-shipping-gov-cod': '' }));
    form.appendChild(el('input', { type: 'checkbox', 'data-abo-admin-shipping-gov-active': '' }));

    form.appendChild(el('button', { type: 'button', 'data-abo-admin-shipping-save': '' }));
    form.appendChild(el('button', { type: 'button', 'data-abo-admin-shipping-delete': '' }));
    form.appendChild(el('p', { 'data-abo-admin-shipping-delete-note': '', hidden: true }));

    panel.appendChild(form);
    page.appendChild(panel);

    // The delete modal.
    const modal = el('div', { 'data-abo-admin-shipping-modal': '', hidden: true });
    modal.appendChild(el('p', { 'data-abo-admin-shipping-modal-body': '' }));
    modal.appendChild(el('button', { type: 'button', 'data-abo-admin-shipping-modal-close': '' }));
    modal.appendChild(el('button', { type: 'button', 'data-abo-admin-shipping-modal-cancel': '' }));
    modal.appendChild(el('button', { type: 'button', 'data-abo-admin-shipping-modal-confirm': '' }));
    page.appendChild(modal);

    // The global settings card.
    const settings = el('section', { 'data-abo-admin-shipping-settings': '' });
    settings.appendChild(el('span', { 'data-abo-admin-shipping-settings-dirty-dot': '' }));
    settings.appendChild(el('button', { type: 'button', 'data-abo-admin-shipping-settings-save': '' }));
    settings.appendChild(el('input', { type: 'checkbox', 'data-abo-admin-shipping-free-shipping': '' }));
    settings.appendChild(el('div', { 'data-abo-admin-shipping-threshold-wrap': '' }));
    settings.appendChild(el('input', { 'data-abo-admin-shipping-threshold': '' }));
    settings.appendChild(el('p', { 'data-abo-admin-shipping-threshold-error': '', hidden: true }));
    settings.appendChild(el('input', { 'data-abo-admin-shipping-cod-fee': '' }));
    settings.appendChild(el('p', { 'data-abo-admin-shipping-cod-fee-error': '', hidden: true }));
    settings.appendChild(el('input', { 'data-abo-admin-shipping-default-fee': '' }));
    settings.appendChild(el('p', { 'data-abo-admin-shipping-default-fee-error': '', hidden: true }));

    const noteLang = el('div', { 'data-abo-admin-shipping-note-lang': '' });
    noteLang.appendChild(el('button', { type: 'button', 'data-lang': 'en', text: 'EN' }));
    noteLang.appendChild(el('button', { type: 'button', 'data-lang': 'ar', text: 'AR' }));
    settings.appendChild(noteLang);
    settings.appendChild(el('textarea', { 'data-abo-admin-shipping-note': '' }));
    settings.appendChild(el('p', { 'data-abo-admin-shipping-note-hint': '' }));
    page.appendChild(settings);

    // The toast.
    const toast = el('div', { 'data-abo-admin-shipping-toast': '', hidden: true });
    toast.appendChild(el('span', { 'data-abo-admin-shipping-toast-dot': '' }));
    toast.appendChild(el('span', { 'data-abo-admin-shipping-toast-text': '' }));
    page.appendChild(toast);

    root.appendChild(page);

    return { document, root, page, panel, modal, toast, settings };
}

const jsonResponse = (status, payload) => Promise.resolve({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(payload),
});

/**
 * A server that answers the way Admin\ShippingController does.
 */
function defaultFetch(calls) {
    return (url, options = {}) => {
        calls.push({ url, options });

        const method = options.method;
        const body = options.body ? JSON.parse(options.body) : null;

        if (method === 'DELETE') {
            return jsonResponse(200, { success: true, message: 'Deleted.' });
        }

        if (url === ROUTES.settings) {
            return jsonResponse(200, { success: true, message: 'Settings saved.' });
        }

        if (url.endsWith('/reorder')) {
            return jsonResponse(200, {
                success: true,
                message: 'Order updated.',
                zone: {
                    ...ZONES[1],
                    governorates: [{ ...ZONES[0].governorates[0], parentId: ZONES[1].id }],
                },
            });
        }

        if (url === ROUTES.zoneStore) {
            return jsonResponse(201, {
                success: true,
                message: 'Zone created.',
                zone: {
                    id: 'z-9', dbId: 9, name: body.name, nameAr: body.name_ar || '',
                    fee: body.fee, timeFrom: body.time_from, timeTo: body.time_to,
                    active: body.is_active, governorates: [],
                },
            });
        }

        if (url.startsWith('/admin/shipping/zones')) {
            return jsonResponse(200, {
                success: true,
                message: 'Zone saved.',
                zone: {
                    ...ZONES[0],
                    name: body.name,
                    nameAr: body.name_ar ?? ZONES[0].nameAr,
                    fee: body.fee,
                    timeFrom: body.time_from,
                    timeTo: body.time_to,
                    active: body.is_active,
                },
            });
        }

        return jsonResponse(200, {
            success: true,
            message: 'Governorate saved.',
            governorate: {
                ...ZONES[0].governorates[0],
                parentId: ZONES[0].id,
                name: body.name ?? ZONES[0].governorates[0].name,
                fee: body.fee ?? null,
                timeFrom: body.time_from ?? null,
                timeTo: body.time_to ?? null,
                cod: body.allows_cod,
                active: body.is_active,
            },
        });
    };
}

/**
 * Run the shipping script against a fresh page.
 */
function run({ zones = ZONES, settings = SETTINGS, fetchImpl } = {}) {
    const browser = createBrowser();
    const page = buildPage(browser);

    const calls = [];
    const fetchStub = fetchImpl || defaultFetch(calls);

    browser.window.__SHIPPING_ZONES__ = JSON.parse(JSON.stringify(zones));
    browser.window.__SHIPPING_SETTINGS__ = JSON.parse(JSON.stringify(settings));
    browser.window.__SHIPPING_ROUTES__ = ROUTES;
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
    };
    sandbox.globalThis = sandbox;

    const context = vm.createContext(sandbox);
    vm.runInContext(shippingScript, context);

    return { ...page, calls, browser, sandbox };
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 20));

const tree = (page) => page.document.querySelector('[data-abo-admin-shipping-tree]');
const zoneRow = (page, id) => tree(page).querySelector(`li[data-kind="zone"][data-id="${id}"]`);
const govRow = (page, id) => tree(page).querySelector(`li[data-kind="gov"][data-id="${id}"]`);
const toastText = (page) =>
    page.document.querySelector('[data-abo-admin-shipping-toast-text]').textContent;

/**
 * Select a row the way the admin does — by clicking it.
 */
function selectZone(page, id) {
    click(zoneRow(page, id));
}

function selectGovernorate(page, id) {
    click(govRow(page, id));
}

/**
 * Expand a zone so its governorates are on screen.
 */
function expandZone(page, id) {
    click(tree(page).querySelector(`[data-abo-admin-shipping-zone-caret="${id}"]`));
}

test('the tree is built from the zones the controller sent', async () => {
    const page = run();
    await settle();

    assert.match(tree(page).textContent, /Greater Cairo/);
    assert.match(tree(page).textContent, /Upper Egypt/);
    assert.match(tree(page).textContent, /Cairo/);
    assert.match(tree(page).textContent, /Giza/);
});

test('saving a zone sends the fields the shipping_zones table holds', async () => {
    const page = run();
    await settle();

    selectZone(page, 'z-1');

    const name = page.document.querySelector('[data-abo-admin-shipping-zone-name][data-lang="en"]');
    name.value = 'Cairo & Giza';
    name.dispatchEvent('input', {});

    const fee = page.document.querySelector('[data-abo-admin-shipping-zone-fee]');
    fee.value = '65';
    fee.dispatchEvent('input', {});

    click(page.document.querySelector('[data-abo-admin-shipping-save]'));
    await settle();

    assert.equal(page.calls.length, 1);

    const call = page.calls[0];
    assert.equal(call.url, '/admin/shipping/zones/1');
    assert.equal(call.options.method, 'PUT');

    const body = JSON.parse(call.options.body);
    assert.equal(body.name, 'Cairo & Giza');
    assert.equal(body.name_ar, 'القاهرة الكبرى');
    assert.equal(body.fee, 65);
    assert.equal(body.time_from, 1);
    assert.equal(body.time_to, 3);
    assert.equal(body.is_active, true);
    assert.equal(call.options.headers['X-CSRF-TOKEN'], 'test-token');
});

test('the row on screen is the row the server answered with', async () => {
    const page = run({
        fetchImpl: (url, options) => {
            page.calls.push({ url, options });
            return jsonResponse(200, {
                success: true,
                message: 'Zone saved.',
                zone: { ...ZONES[0], name: 'Cairo & Giza' },
            });
        },
    });
    await settle();

    selectZone(page, 'z-1');
    click(page.document.querySelector('[data-abo-admin-shipping-save]'));
    await settle();

    assert.match(tree(page).textContent, /Cairo & Giza/);
});

test('a refused save shows the message the server sent', async () => {
    const page = run({
        fetchImpl: (url, options) => {
            page.calls.push({ url, options });
            return jsonResponse(422, {
                success: false,
                message: 'The end date must be after the start date.',
                errors: { name: ['The name has already been taken.'] },
            });
        },
    });
    await settle();

    selectZone(page, 'z-1');
    click(page.document.querySelector('[data-abo-admin-shipping-save]'));
    await settle();

    assert.equal(toastText(page), 'The end date must be after the start date.');
    assert.match(tree(page).textContent, /Greater Cairo/);
});

test('a new zone is created on the server', async () => {
    const page = run();
    await settle();

    click(page.document.querySelector('[data-abo-admin-shipping-add]'));

    const name = page.document.querySelector('[data-abo-admin-shipping-zone-name][data-lang="en"]');
    name.value = 'Alexandria';
    name.dispatchEvent('input', {});

    click(page.document.querySelector('[data-abo-admin-shipping-save]'));
    await settle();

    assert.equal(page.calls.length, 1);
    assert.equal(page.calls[0].url, '/admin/shipping/zones');
    assert.equal(page.calls[0].options.method, 'POST');

    const body = JSON.parse(page.calls[0].options.body);
    assert.equal(body.name, 'Alexandria');
    assert.equal(body.fee, 50);
    assert.match(tree(page).textContent, /Alexandria/);
});

test('switching a zone off reaches the server', async () => {
    const page = run();
    await settle();

    const toggle = tree(page).querySelector('[data-abo-admin-shipping-zone-toggle="z-1"]');
    toggle.checked = false;

    click(toggle);
    await settle();

    assert.equal(page.calls.length, 1);
    assert.equal(page.calls[0].url, '/admin/shipping/zones/1');
    assert.equal(page.calls[0].options.method, 'PUT');
    assert.deepEqual(JSON.parse(page.calls[0].options.body), { is_active: false });
});

test('switching a governorate off reaches the server', async () => {
    const page = run();
    await settle();

    expandZone(page, 'z-1');

    const toggle = tree(page).querySelector('[data-abo-admin-shipping-gov-toggle="g-12"]');
    toggle.checked = true;

    click(toggle);
    await settle();

    assert.equal(page.calls.length, 1);
    assert.equal(page.calls[0].url, '/admin/shipping/governorates/12');
    assert.equal(page.calls[0].options.method, 'PUT');
    assert.deepEqual(JSON.parse(page.calls[0].options.body), { is_active: true });
});

test('saving a governorate sends the fields the governorates table holds', async () => {
    const page = run();
    await settle();

    selectGovernorate(page, 'g-11');

    const radios = page.document.querySelectorAll('[data-abo-admin-shipping-gov-fee-mode]');
    radios[1].checked = true;
    radios[1].dispatchEvent('change', {});

    const fee = page.document.querySelector('[data-abo-admin-shipping-gov-fee]');
    fee.value = '55';
    fee.dispatchEvent('input', {});

    click(page.document.querySelector('[data-abo-admin-shipping-save]'));
    await settle();

    assert.equal(page.calls.length, 1);
    assert.equal(page.calls[0].url, '/admin/shipping/governorates/11');
    assert.equal(page.calls[0].options.method, 'PUT');

    const body = JSON.parse(page.calls[0].options.body);
    assert.equal(body.shipping_zone_id, 1);
    assert.equal(body.name, 'Cairo');
    assert.equal(body.name_ar, 'القاهرة');
    assert.equal(body.fee, 55);
    assert.equal(body.time_from, null);
    assert.equal(body.time_to, null);
    assert.equal(body.allows_cod, true);
    assert.equal(body.is_active, true);
});

test('deleting a governorate asks the server and drops the row', async () => {
    const page = run({
        fetchImpl: (url, options) => {
            page.calls.push({ url, options });
            return jsonResponse(200, { success: true, message: 'Governorate deleted.' });
        },
    });
    await settle();

    selectGovernorate(page, 'g-12');
    click(page.document.querySelector('[data-abo-admin-shipping-delete]'));
    click(page.document.querySelector('[data-abo-admin-shipping-modal-confirm]'));
    await settle();

    assert.equal(page.calls.length, 1);
    assert.equal(page.calls[0].url, '/admin/shipping/governorates/12');
    assert.equal(page.calls[0].options.method, 'DELETE');
    assert.doesNotMatch(tree(page).textContent, /Giza/);
});

test('dropping a governorate into another zone writes the zone and the order', async () => {
    const page = run();
    await settle();

    expandZone(page, 'z-1');

    const target = zoneRow(page, 'z-2');
    target.dispatchEvent('drop', {
        preventDefault() {},
        dataTransfer: { getData: () => 'g-11' },
    });
    await settle();

    assert.equal(page.calls.length, 2);

    assert.equal(page.calls[0].url, '/admin/shipping/governorates/11');
    assert.equal(page.calls[0].options.method, 'PUT');
    assert.deepEqual(JSON.parse(page.calls[0].options.body), { shipping_zone_id: 2 });

    assert.equal(page.calls[1].url, '/admin/shipping/zones/2/reorder');
    assert.equal(page.calls[1].options.method, 'POST');
    assert.deepEqual(JSON.parse(page.calls[1].options.body), { governorates: [11] });
});

test('the free shipping threshold is editable while free shipping is on', async () => {
    const page = run();
    await settle();

    const threshold = page.document.querySelector('[data-abo-admin-shipping-threshold]');
    const free = page.document.querySelector('[data-abo-admin-shipping-free-shipping]');

    assert.equal(threshold.disabled, false);

    free.checked = false;
    free.dispatchEvent('change', {});

    assert.equal(threshold.disabled, true);
});

test('the global settings are saved on the server', async () => {
    const page = run();
    await settle();

    const cod = page.document.querySelector('[data-abo-admin-shipping-cod-fee]');
    cod.value = '25';
    cod.dispatchEvent('input', {});

    const threshold = page.document.querySelector('[data-abo-admin-shipping-threshold]');
    threshold.value = '2000';
    threshold.dispatchEvent('input', {});

    click(page.document.querySelector('[data-abo-admin-shipping-settings-save]'));
    await settle();

    assert.equal(page.calls.length, 1);
    assert.equal(page.calls[0].url, '/admin/shipping/settings');
    assert.equal(page.calls[0].options.method, 'POST');

    const body = JSON.parse(page.calls[0].options.body);
    assert.equal(body.free_shipping_enabled, true);
    assert.equal(body.free_shipping_threshold, 2000);
    assert.equal(body.cod_fee, 25);
    assert.equal(body.default_fee, 75);
    assert.equal(body.note_en, 'Delivery within 2 to 5 working days.');
    assert.equal(body.note_ar, 'التوصيل خلال 2 إلى 5 أيام عمل.');
});
