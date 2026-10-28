// Runtime tests for the admin colors & sizes screen.
//
// The lists on this screen came from the database, but four controls
// were still a demo: switching a color on or off, dragging a color into
// a new place, dragging a size into a new place and the switch that
// hides a whole size group all toasted "(demo)" and changed nothing but
// the page's own memory.
//
// These tests run public/admin/js/colors-sizes.js for real — against a
// small DOM and a fake server — so that:
//
//   - the lists are built from the payload the controller sends;
//   - every control reaches the server with the right fields;
//   - the list on screen is the list the server answered with.
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
const colorsSizesScript = fs.readFileSync(
    path.join(projectRoot, 'public/admin/js/colors-sizes.js'),
    'utf8'
);

/**
 * Two colors and two size groups, as Admin\ColorSizeController sends them.
 */
const COLORS = [
    { id: '1', name: 'Olive', nameAr: 'زيتوني', hex: '#5C6A48', products: 4, active: true, sortOrder: 0 },
    { id: '2', name: 'Black', nameAr: 'أسود', hex: '#0D0F11', products: 9, active: false, sortOrder: 1 },
];

const SIZE_GROUPS = {
    tops: [
        { id: '11', name: 'S', group: 'tops', products: 3, active: true, sortOrder: 0 },
        { id: '12', name: 'M', group: 'tops', products: 5, active: true, sortOrder: 1 },
    ],
    bottoms: [
        { id: '21', name: '32', group: 'bottoms', products: 2, active: true, sortOrder: 0 },
    ],
    general: [],
};

/**
 * Build the page the colors & sizes screen runs on.
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

    const page = el('div', { 'data-abo-admin-cs': '' });

    // The colors section.
    page.appendChild(el('div', { 'data-abo-admin-cs-colors': '' }));
    page.appendChild(el('input', { type: 'search', 'data-abo-admin-cs-search': '' }));
    page.appendChild(el('ul', { role: 'listbox', 'data-abo-admin-cs-list': '' }));
    page.appendChild(el('div', { 'data-abo-admin-cs-skeleton': '' }));
    page.appendChild(el('div', { 'data-abo-admin-cs-state': 'empty', hidden: true }));
    page.appendChild(el('div', { 'data-abo-admin-cs-state': 'noresults', hidden: true }));
    page.appendChild(el('button', { type: 'button', 'data-abo-admin-cs-add-color': '' }));
    page.appendChild(el('button', { type: 'button', 'data-abo-admin-cs-add-color-empty': '' }));

    // The edit panel.
    const panel = el('aside', { 'data-abo-admin-cs-panel': '' });
    panel.appendChild(el('div', { 'data-abo-admin-cs-panel-overlay': '' }));
    panel.appendChild(el('div', { 'data-abo-admin-cs-panel-no-selection': '' }));

    const form = el('div', { 'data-abo-admin-cs-panel-form': '', hidden: true });
    form.appendChild(el('span', { 'data-abo-admin-cs-panel-title-text': '' }));
    form.appendChild(el('span', { 'data-abo-admin-cs-dirty-dot': '' }));
    form.appendChild(el('div', { 'data-abo-admin-cs-preview': '' }));
    form.appendChild(el('img', { 'data-abo-admin-cs-preview-img': '' }));
    form.appendChild(el('span', { 'data-abo-admin-cs-preview-dot': '' }));
    form.appendChild(el('input', { 'data-abo-admin-cs-name': '' }));
    form.appendChild(el('input', { 'data-abo-admin-cs-name-ar': '' }));
    form.appendChild(el('input', { 'data-abo-admin-cs-hex': '' }));
    form.appendChild(el('input', { type: 'color', 'data-abo-admin-cs-hex-pick': '' }));
    form.appendChild(el('p', { 'data-abo-admin-cs-hex-error': '', hidden: true }));
    form.appendChild(el('input', { type: 'checkbox', 'data-abo-admin-cs-active': '' }));
    form.appendChild(el('button', { type: 'button', 'data-abo-admin-cs-save': '' }));
    form.appendChild(el('button', { type: 'button', 'data-abo-admin-cs-delete': '' }));
    form.appendChild(el('p', { 'data-abo-admin-cs-delete-note': '', hidden: true }));

    panel.appendChild(form);
    page.appendChild(panel);

    // The sizes section.
    page.appendChild(el('div', { 'data-abo-admin-cs-sizes-section': '' }));
    page.appendChild(el('button', { type: 'button', 'data-abo-admin-cs-add-size': '' }));
    page.appendChild(el('div', { 'data-abo-admin-cs-sizes': '' }));

    // The delete modal.
    const modal = el('div', { 'data-abo-admin-cs-modal': '', hidden: true });
    modal.appendChild(el('p', { 'data-abo-admin-cs-modal-body': '' }));
    modal.appendChild(el('button', { type: 'button', 'data-abo-admin-cs-modal-close': '' }));
    modal.appendChild(el('button', { type: 'button', 'data-abo-admin-cs-modal-cancel': '' }));
    modal.appendChild(el('button', { type: 'button', 'data-abo-admin-cs-modal-confirm': '' }));
    page.appendChild(modal);

    // The size modal.
    const sizeModal = el('div', { 'data-abo-admin-cs-size-modal': '', hidden: true });
    sizeModal.appendChild(el('button', { type: 'button', 'data-abo-admin-cs-size-modal-close': '' }));
    sizeModal.appendChild(el('span', { 'data-abo-admin-cs-size-modal-title-text': '' }));
    sizeModal.appendChild(el('input', { 'data-abo-admin-cs-size-label': '' }));
    sizeModal.appendChild(el('input', { type: 'checkbox', 'data-abo-admin-cs-size-visible': '' }));
    sizeModal.appendChild(el('button', { type: 'button', 'data-abo-admin-cs-size-save': '' }));
    sizeModal.appendChild(el('button', { type: 'button', 'data-abo-admin-cs-size-delete': '' }));
    page.appendChild(sizeModal);

    // The toast.
    const toast = el('div', { 'data-abo-admin-cs-toast': '', hidden: true });
    toast.appendChild(el('span', { 'data-abo-admin-cs-toast-dot': '' }));
    toast.appendChild(el('span', { 'data-abo-admin-cs-toast-text': '' }));
    page.appendChild(toast);

    root.appendChild(page);

    return { document, root, page, panel, modal, sizeModal, toast };
}

const jsonResponse = (status, payload) => Promise.resolve({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(payload),
});

/**
 * A server that answers the way Admin\ColorSizeController does.
 */
function defaultFetch(calls) {
    return (url, options = {}) => {
        calls.push({ url, options });

        const method = options.method;
        const body = options.body ? JSON.parse(options.body) : null;

        if (method === 'DELETE') {
            return jsonResponse(200, { success: true, message: 'Deleted successfully.' });
        }

        if (url === '/admin/colors/reorder') {
            return jsonResponse(200, {
                success: true,
                message: 'Color order updated.',
                colors: body.colors.map((id, index) => ({
                    ...COLORS.find((c) => c.id === String(id)),
                    sortOrder: index,
                })),
            });
        }

        if (url === '/admin/sizes/reorder') {
            return jsonResponse(200, {
                success: true,
                message: 'Size order updated.',
                sizes: body.sizes.map((id, index) => ({
                    ...SIZE_GROUPS.tops.find((s) => s.id === String(id)),
                    sortOrder: index,
                })),
            });
        }

        if (url.endsWith('/toggle') && url.includes('/sizes/group/')) {
            return jsonResponse(200, {
                success: true,
                message: 'Size group hidden from store.',
                group: 'tops',
                updated: 2,
                sizes: Object.values(SIZE_GROUPS).flat().map((s) => ({ ...s, active: false })),
            });
        }

        if (url.endsWith('/toggle')) {
            const id = url.split('/')[3];
            const color = COLORS.find((c) => c.id === id);

            return jsonResponse(200, {
                success: true,
                message: 'Color deactivated successfully.',
                color: { ...color, active: !color.active },
            });
        }

        return jsonResponse(200, {
            success: true,
            message: 'Saved successfully.',
            color: COLORS[0],
            size: SIZE_GROUPS.tops[0],
        });
    };
}

/**
 * Run the colors & sizes script against a fresh page.
 */
function run({ colors = COLORS, sizeGroups = SIZE_GROUPS, fetchImpl } = {}) {
    const browser = createBrowser();
    const page = buildPage(browser);

    const calls = [];
    const fetchStub = fetchImpl || defaultFetch(calls);

    browser.window.__COLORS__ = JSON.parse(JSON.stringify(colors));
    browser.window.__SIZE_GROUPS__ = JSON.parse(JSON.stringify(sizeGroups));
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
        // The screen waits 500 ms before it draws the lists. The timers
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
    vm.runInContext(colorsSizesScript, context);

    return { ...page, calls, browser, sandbox };
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 20));

const colorList = (page) => page.document.querySelector('[data-abo-admin-cs-list]');
const sizesRoot = (page) => page.document.querySelector('[data-abo-admin-cs-sizes]');
const colorToggle = (page, id) =>
    colorList(page).querySelector(`[data-abo-admin-cs-toggle="${id}"]`);
const groupToggle = (page, id) =>
    sizesRoot(page).querySelector(`[data-abo-admin-cs-group-toggle="${id}"]`);
const chip = (page, id) => sizesRoot(page).querySelector(`[data-abo-admin-cs-chip][data-size-id="${id}"]`);
const toastText = (page) =>
    page.document.querySelector('[data-abo-admin-cs-toast-text]').textContent;

test('the lists are built from the payload the controller sent', async () => {
    const page = run();
    await settle();

    assert.match(colorList(page).textContent, /Olive/);
    assert.match(colorList(page).textContent, /Black/);
    assert.match(sizesRoot(page).textContent, /Letter sizes/);
    assert.match(sizesRoot(page).textContent, /S/);
});

test('switching a color off reaches the server', async () => {
    const page = run();
    await settle();

    const toggle = colorToggle(page, '1');
    toggle.checked = false;

    click(toggle);
    await settle();

    assert.equal(page.calls.length, 1);
    assert.equal(page.calls[0].url, '/admin/colors/1/toggle');
    assert.equal(page.calls[0].options.method, 'PATCH');
    assert.equal(page.calls[0].options.headers['X-CSRF-TOKEN'], 'test-token');
});

test('the color on screen is the color the server answered with', async () => {
    const page = run({
        fetchImpl: (url, options) => {
            page.calls.push({ url, options });
            return jsonResponse(200, {
                success: true,
                message: 'Color deactivated successfully.',
                color: { ...COLORS[0], active: false },
            });
        },
    });
    await settle();

    const toggle = colorToggle(page, '1');
    toggle.checked = false;

    click(toggle);
    await settle();

    assert.equal(colorToggle(page, '1').checked, false);
    assert.equal(toastText(page), 'Color deactivated successfully.');
});

test('dropping a color sends the new order', async () => {
    const page = run();
    await settle();

    const target = colorList(page).querySelector('li[data-id="1"]');
    target.classList.add('is-drop-before');
    target.dispatchEvent('drop', {
        target,
        preventDefault() {},
        dataTransfer: { getData: () => '2' },
    });
    await settle();

    assert.equal(page.calls.length, 1);
    assert.equal(page.calls[0].url, '/admin/colors/reorder');
    assert.equal(page.calls[0].options.method, 'POST');
    assert.deepEqual(JSON.parse(page.calls[0].options.body), { colors: [2, 1] });
});

test('hiding a size group reaches the server', async () => {
    const page = run();
    await settle();

    const toggle = groupToggle(page, 'tops');
    toggle.checked = false;

    click(toggle);
    await settle();

    assert.equal(page.calls.length, 1);
    assert.equal(page.calls[0].url, '/admin/sizes/group/tops/toggle');
    assert.equal(page.calls[0].options.method, 'PATCH');
    assert.deepEqual(JSON.parse(page.calls[0].options.body), { is_active: false });
});

test('dropping a size sends the new order of its group', async () => {
    const page = run();
    await settle();

    const target = chip(page, '11');
    target.classList.add('is-drop-before');

    // The drag that starts the move. The listeners sit on the sizes
    // root, so the event is delivered there with the chip as target.
    sizesRoot(page).dispatchEvent('dragstart', {
        target: chip(page, '12'),
        dataTransfer: { setData() {}, effectAllowed: '' },
    });

    sizesRoot(page).dispatchEvent('drop', {
        target,
        preventDefault() {},
        dataTransfer: { getData: () => '12' },
    });
    await settle();

    assert.equal(page.calls.length, 1);
    assert.equal(page.calls[0].url, '/admin/sizes/reorder');
    assert.equal(page.calls[0].options.method, 'POST');
    assert.deepEqual(JSON.parse(page.calls[0].options.body), { sizes: [12, 11] });
});
