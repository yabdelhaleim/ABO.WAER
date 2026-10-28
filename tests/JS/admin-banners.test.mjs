// Runtime tests for the admin banners screen.
//
// The banners screen used to be a demo: the list came from a static
// array in the script, the placement of every row was hard-coded, and
// the save / duplicate / delete buttons toasted "(demo)" without
// asking the server for anything.
//
// These tests run public/admin/js/banners.js for real — against a
// small DOM and a fake server — so that:
//
//   - the list is built from the payload the controller sends;
//   - the placement groups come from that payload, not from demo ids;
//   - saving, duplicating, deleting and the on/off switch reach the
//     server, and the row on screen is the row the server answered
//     with;
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
const bannersScript = fs.readFileSync(
    path.join(projectRoot, 'public/admin/js/banners.js'),
    'utf8'
);

/**
 * Three banners, as the controller sends them: two in the hero slot
 * and one announcement. The shape is the one
 * App\Services\BannerService::payload() builds.
 */
const BANNERS = [
    {
        id: 1, placement: 'hero', active: true, starts: null, ends: null,
        title: 'Winter hero', titleAr: 'بطل الشتاء',
        subtitle: 'Warm layers', subtitleAr: 'طبقات دافئة',
        buttonLabel: 'Shop now', alt: 'A model in a hoodie', altAr: 'نص بديل',
        desktopImage: '/storage/banners/one.jpg', mobileImage: null,
        textColor: 'light', textPosition: 'left', overlay: 'none',
        linkType: 'category', linkCategory: 'hoodies', linkProduct: null, linkUrl: null,
    },
    {
        id: 2, placement: 'hero', active: false, starts: null, ends: null,
        title: 'Hidden hero', titleAr: null,
        subtitle: null, subtitleAr: null,
        buttonLabel: '', alt: '', altAr: null,
        desktopImage: '/storage/banners/two.jpg', mobileImage: null,
        textColor: 'light', textPosition: 'left', overlay: 'none',
        linkType: 'url', linkCategory: null, linkProduct: null, linkUrl: '/help',
    },
    {
        id: 3, placement: 'announcement', active: true, starts: null, ends: null,
        title: 'Free shipping', titleAr: 'شحن مجاني',
        subtitle: null, subtitleAr: null,
        buttonLabel: '', alt: '', altAr: null,
        desktopImage: null, mobileImage: null,
        textColor: 'light', textPosition: 'left', overlay: 'none',
        linkType: 'none', linkCategory: null, linkProduct: null, linkUrl: null,
    },
];

const ROUTES = {
    store: '/admin/banners',
    update: '/admin/banners/__BANNER__',
    duplicate: '/admin/banners/__BANNER__/duplicate',
    destroy: '/admin/banners/__BANNER__',
};

const CATEGORIES = [{ slug: 'hoodies', name: 'Hoodies' }];
const PRODUCTS = [{ slug: 'heavyweight-hoodie', name: 'Heavyweight Hoodie' }];

/**
 * Build the page the banners screen runs on.
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

    const page = el('div', { 'data-abo-admin-banners': '' });

    // The placement tabs and their counts.
    const tabs = el('div', { 'data-abo-admin-banners-tabs': '' });
    for (const key of ['hero', 'promo', 'announcement']) {
        tabs.appendChild(el('button', { type: 'button', 'data-abo-admin-banners-tab': key, text: key }));
        tabs.appendChild(el('span', { 'data-abo-admin-banners-tab-count': key, text: '0' }));
    }
    page.appendChild(tabs);

    // The list and its states.
    page.appendChild(el('ul', { 'data-abo-admin-banners-list': '' }));
    page.appendChild(el('div', { 'data-abo-admin-banners-state': 'loading' }));
    page.appendChild(el('div', { 'data-abo-admin-banners-state': 'empty', hidden: true }));
    page.appendChild(el('p', { 'data-abo-admin-banners-counter': '' }));
    page.appendChild(el('span', { id: 'abo-admin-banners-today-date' }));
    page.appendChild(el('p', { 'data-abo-admin-banners-helper': '' }));

    // The header buttons.
    page.appendChild(el('button', { type: 'button', 'data-abo-admin-banners-add': '' }));
    page.appendChild(el('button', { type: 'button', 'data-abo-admin-banners-add-empty': '' }));

    // The edit panel.
    const panel = el('aside', { 'data-abo-admin-banners-panel': '' });
    panel.appendChild(el('div', { 'data-abo-admin-banners-panel-form': '' }));
    panel.appendChild(el('div', { 'data-abo-admin-banners-panel-no-selection': '' }));
    panel.appendChild(el('div', { 'data-abo-admin-banners-panel-overlay': '' }));
    panel.appendChild(el('button', { type: 'button', 'data-abo-admin-banners-panel-close': '' }));
    panel.appendChild(el('span', { 'data-abo-admin-banners-panel-title-text': '' }));
    panel.appendChild(el('span', { 'data-abo-admin-banners-dirty-dot': '' }));

    // The fields.
    panel.appendChild(el('select', { 'data-abo-admin-banners-placement': '' }));
    panel.appendChild(el('div', { 'data-abo-admin-banners-images': '' }));
    panel.appendChild(el('div', { 'data-abo-admin-banners-subtitle-field': '' }));
    panel.appendChild(el('div', { 'data-abo-admin-banners-button-field': '' }));
    panel.appendChild(el('div', { 'data-abo-admin-banners-look': '' }));
    panel.appendChild(el('div', { 'data-abo-admin-banners-alt-field': '' }));
    panel.appendChild(el('label', { 'data-abo-admin-banners-title-label': '' }));
    panel.appendChild(el('input', { 'data-abo-admin-banners-title': '' }));
    panel.appendChild(el('input', { 'data-abo-admin-banners-subtitle': '' }));
    panel.appendChild(el('input', { 'data-abo-admin-banners-button': '' }));
    panel.appendChild(el('input', { 'data-abo-admin-banners-alt': '' }));
    panel.appendChild(el('input', { 'data-abo-admin-banners-starts': '' }));
    panel.appendChild(el('input', { 'data-abo-admin-banners-ends': '' }));
    panel.appendChild(el('p', { 'data-abo-admin-banners-date-error': '', hidden: true }));
    panel.appendChild(el('p', { 'data-abo-admin-banners-alt-error': '', hidden: true }));

    // The language switches.
    for (const which of ['alt', 'title', 'subtitle']) {
        const wrap = el('div', { [`data-abo-admin-banners-${which}-lang`]: '' });
        wrap.appendChild(el('button', { type: 'button', 'data-lang': 'en', text: 'EN' }));
        wrap.appendChild(el('button', { type: 'button', 'data-lang': 'ar', text: 'AR' }));
        panel.appendChild(wrap);
    }

    // The link pickers.
    panel.appendChild(el('select', { 'data-abo-admin-banners-link-type': '' }));
    panel.appendChild(el('div', { 'data-abo-admin-banners-link-category': '' }));
    panel.appendChild(el('div', { 'data-abo-admin-banners-link-product': '' }));
    panel.appendChild(el('div', { 'data-abo-admin-banners-link-url': '' }));
    panel.appendChild(el('select', { 'data-abo-admin-banners-link-category-value': '' }));
    panel.appendChild(el('select', { 'data-abo-admin-banners-link-product-value': '' }));
    panel.appendChild(el('input', { 'data-abo-admin-banners-link-url-value': '' }));
    panel.appendChild(el('p', { 'data-abo-admin-banners-link-url-error': '', hidden: true }));

    // The look controls.
    for (const which of ['text-color', 'text-position', 'overlay']) {
        const wrap = el('div', { [`data-abo-admin-banners-${which}`]: '' });
        wrap.appendChild(el('button', { type: 'button', 'data-value': 'light', text: 'a' }));
        wrap.appendChild(el('button', { type: 'button', 'data-value': 'dark', text: 'b' }));
        panel.appendChild(wrap);
    }

    // The active switch.
    const activeSwitch = el('div', { 'data-abo-admin-banners-switch': 'active' });
    activeSwitch.appendChild(el('input', { type: 'checkbox' }));
    panel.appendChild(activeSwitch);

    // The image pickers.
    panel.appendChild(el('div', { 'data-abo-admin-banners-desktop-preview': '' }));
    panel.appendChild(el('div', { 'data-abo-admin-banners-mobile-preview': '' }));
    panel.appendChild(el('button', { type: 'button', 'data-abo-admin-banners-desktop-remove': '' }));
    panel.appendChild(el('button', { type: 'button', 'data-abo-admin-banners-mobile-remove': '' }));
    panel.appendChild(el('input', { type: 'file', 'data-abo-admin-banners-desktop-file': '' }));
    panel.appendChild(el('input', { type: 'file', 'data-abo-admin-banners-mobile-file': '' }));
    panel.appendChild(el('button', { type: 'button', 'data-abo-admin-banners-desktop-change': '' }));
    panel.appendChild(el('button', { type: 'button', 'data-abo-admin-banners-mobile-change': '' }));
    panel.appendChild(el('p', { 'data-abo-admin-banners-desktop-error': '', hidden: true }));
    panel.appendChild(el('p', { 'data-abo-admin-banners-mobile-error': '', hidden: true }));

    // The live preview.
    panel.appendChild(el('div', { 'data-abo-admin-banners-preview-wrap': '' }));
    const frame = el('div', { 'data-abo-admin-banners-preview-frame': '' });
    frame.appendChild(el('div', { 'data-abo-admin-banners-preview-banner': '' }));
    frame.appendChild(el('div', { 'data-abo-admin-banners-preview-overlay': '' }));
    frame.appendChild(el('div', { 'data-abo-admin-banners-preview-text': '' }));
    frame.appendChild(el('button', { type: 'button', 'data-abo-admin-banners-preview-btn': '' }));
    panel.appendChild(frame);
    const device = el('div', { 'data-abo-admin-banners-preview-device': '' });
    device.appendChild(el('button', { type: 'button', 'data-value': 'desktop', text: 'd' }));
    device.appendChild(el('button', { type: 'button', 'data-value': 'mobile', text: 'm' }));
    panel.appendChild(device);

    // The panel buttons.
    panel.appendChild(el('button', { type: 'button', 'data-abo-admin-banners-save': '' }));
    panel.appendChild(el('button', { type: 'button', 'data-abo-admin-banners-duplicate': '' }));
    panel.appendChild(el('button', { type: 'button', 'data-abo-admin-banners-delete': '' }));
    panel.appendChild(el('p', { 'data-abo-admin-banners-delete-note': '' }));

    page.appendChild(panel);

    // The delete modal.
    const modal = el('div', { 'data-abo-admin-banners-modal': '', hidden: true });
    modal.appendChild(el('p', { 'data-abo-admin-banners-modal-body': '' }));
    modal.appendChild(el('button', { type: 'button', 'data-abo-admin-banners-modal-close': '' }));
    modal.appendChild(el('button', { type: 'button', 'data-abo-admin-banners-modal-cancel': '' }));
    modal.appendChild(el('button', { type: 'button', 'data-abo-admin-banners-modal-confirm': '' }));
    page.appendChild(modal);

    // The toast.
    const toast = el('div', { 'data-abo-admin-banners-toast': '', hidden: true });
    toast.appendChild(el('span', { 'data-abo-admin-banners-toast-text': '' }));
    toast.appendChild(el('span', { 'data-abo-admin-banners-toast-dot': '' }));
    page.appendChild(toast);

    root.appendChild(page);

    return { document, root, page, panel, modal, toast, tabs };
}

/**
 * Run the banners script against a fresh page.
 */
function run({ banners = BANNERS, fetchImpl } = {}) {
    const browser = createBrowser();
    const page = buildPage(browser);

    const calls = [];
    const fetchStub = fetchImpl || ((url, options = {}) => {
        calls.push({ url, options });
        return Promise.resolve({
            ok: true,
            status: 200,
            json: () => Promise.resolve({ success: true, message: 'Banner saved.' }),
        });
    });

    browser.window.__BANNERS__ = JSON.parse(JSON.stringify(banners));
    browser.window.__BANNER_CATEGORIES__ = CATEGORIES;
    browser.window.__BANNER_PRODUCTS__ = PRODUCTS;
    browser.window.__BANNER_ROUTES__ = ROUTES;
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
        setTimeout,
        clearTimeout,
        requestAnimationFrame: (callback) => setTimeout(callback, 0),
        console,
        CSS: browser.window.CSS,
        URL: {
            createObjectURL: () => 'blob:preview',
            revokeObjectURL: () => {},
        },
        FormData,
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
    vm.runInContext(bannersScript, context);

    return { ...page, calls, browser, sandbox, state: browser.window.__aboAdminBanners.state };
}

/**
 * Let the promises the script made settle.
 */
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

const list = (page) => page.document.querySelector('[data-abo-admin-banners-list]');
const tabCount = (page, key) =>
    page.document.querySelector(`[data-abo-admin-banners-tab-count="${key}"]`).textContent;
const rowFor = (page, id) =>
    list(page).querySelector(`[data-abo-admin-banners-row][data-id="${id}"]`);

test('the list is built from the banners the controller sent', () => {
    const page = run();

    assert.match(list(page).textContent, /Winter hero/);
    assert.match(list(page).textContent, /Hidden hero/);
});

test('the placement groups come from the payload, not from demo ids', () => {
    const page = run();

    assert.equal(tabCount(page, 'hero'), '2');
    assert.equal(tabCount(page, 'promo'), '0');
    assert.equal(tabCount(page, 'announcement'), '1');
});

test('the announcement banner is listed under its own placement', () => {
    const page = run();

    click(page.document.querySelector('[data-abo-admin-banners-tab="announcement"]'));

    assert.match(list(page).textContent, /Free shipping/);
    assert.doesNotMatch(list(page).textContent, /Winter hero/);
});

test('saving sends the fields the server stores', async () => {
    const page = run();

    click(rowFor(page, 1));

    const title = page.document.querySelector('[data-abo-admin-banners-title]');
    title.value = 'Winter hero 26';
    title.dispatchEvent('input', {});

    click(page.document.querySelector('[data-abo-admin-banners-save]'));
    await settle();

    assert.equal(page.calls.length, 1);

    const call = page.calls[0];
    assert.equal(call.url, '/admin/banners/1');
    assert.equal(call.options.method, 'POST');

    // A body that carries a picture travels as a POST with the method
    // spoofed to the PUT the route expects.
    assert.equal(call.options.body.get('_method'), 'PUT');
    assert.equal(call.options.body.get('title'), 'Winter hero 26');
    assert.equal(call.options.body.get('placement'), 'hero');
    assert.equal(call.options.body.get('is_active'), '1');
    assert.equal(call.options.body.get('link_type'), 'category');
    assert.equal(call.options.body.get('link_target'), 'hoodies');
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
                    message: 'Banner saved.',
                    banner: { ...BANNERS[0], title: 'From the server' },
                }),
            });
        },
    });

    click(rowFor(page, 1));
    click(page.document.querySelector('[data-abo-admin-banners-save]'));
    await settle();

    assert.match(list(page).textContent, /From the server/);
    assert.equal(page.state.editing.title, 'From the server');
});

test('a refused save leaves the screen as the server still sees it', async () => {
    const page = run({
        fetchImpl: (url, options) => {
            page.calls.push({ url, options });
            return Promise.resolve({
                ok: false,
                status: 422,
                json: () => Promise.resolve({
                    success: false,
                    message: 'The end date must be after the start date.',
                    errors: { ends_at: ['The end date must be after the start date.'] },
                }),
            });
        },
    });

    click(rowFor(page, 1));
    click(page.document.querySelector('[data-abo-admin-banners-save]'));
    await settle();

    const toast = page.document.querySelector('[data-abo-admin-banners-toast-text]');
    assert.equal(toast.textContent, 'The end date must be after the start date.');
    assert.match(list(page).textContent, /Winter hero/);
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
                    message: 'Banner saved.',
                    banner: { ...BANNERS[1], active: true },
                }),
            });
        },
    });

    const toggle = list(page).querySelector('[data-abo-admin-banners-toggle="2"]');
    toggle.checked = true;

    list(page).dispatchEvent('change', { target: toggle });
    await settle();

    assert.equal(page.calls.length, 1);
    assert.equal(page.calls[0].url, '/admin/banners/2');
    assert.equal(page.calls[0].options.method, 'PUT');
    assert.deepEqual(JSON.parse(page.calls[0].options.body), { is_active: true });
});

test('deleting asks the server and drops the row when it answers', async () => {
    const page = run({
        fetchImpl: (url, options) => {
            page.calls.push({ url, options });
            return Promise.resolve({
                ok: true,
                status: 200,
                json: () => Promise.resolve({ success: true, message: 'Banner deleted.' }),
            });
        },
    });

    click(rowFor(page, 1));
    click(page.document.querySelector('[data-abo-admin-banners-delete]'));
    click(page.document.querySelector('[data-abo-admin-banners-modal-confirm]'));
    await settle();

    assert.equal(page.calls.length, 1);
    assert.equal(page.calls[0].url, '/admin/banners/1');
    assert.equal(page.calls[0].options.method, 'DELETE');
    assert.doesNotMatch(list(page).textContent, /Winter hero/);
});

test('duplicating asks the server and adds the copy it sent back', async () => {
    const page = run({
        fetchImpl: (url, options) => {
            page.calls.push({ url, options });
            return Promise.resolve({
                ok: true,
                status: 201,
                json: () => Promise.resolve({
                    success: true,
                    message: 'Banner duplicated.',
                    banner: { ...BANNERS[0], id: 9, title: 'Winter hero (copy)', active: false },
                }),
            });
        },
    });

    click(rowFor(page, 1));
    click(page.document.querySelector('[data-abo-admin-banners-duplicate]'));
    await settle();

    assert.equal(page.calls.length, 1);
    assert.equal(page.calls[0].url, '/admin/banners/1/duplicate');
    assert.equal(page.calls[0].options.method, 'POST');
    assert.match(list(page).textContent, /Winter hero \(copy\)/);
});
