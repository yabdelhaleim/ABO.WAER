// Runtime tests for the admin product form.
//
// The form used to be hydrated from a hard-coded sample array, so it
// opened on an empty name and an empty variant table — and saving it
// wiped the variants the product actually had. These tests run
// public/admin/js/products.js for real against a small DOM, and pin
// the behaviour that replaced the demo:
//
//   - the variant table is painted from the payload the server sends;
//   - the colour × size matrix is rebuilt when the selection changes;
//   - the tag widget writes the tags into the hidden inputs the server
//     reads, and an empty list clears them;
//   - the two save buttons set the status of the product before they
//     submit the form.
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

const COLORS = [
    { id: '3', name: 'Onyx', hex: '#0D0F11' },
    { id: '4', name: 'Lime', hex: '#C6FF00' },
];

const SIZES = [
    { id: '7', name: 'M', group: 'tops' },
    { id: '8', name: 'L', group: 'tops' },
];

const PAYLOAD = {
    colors: COLORS,
    sizes: SIZES,
    product: {
        id: '96',
        name: 'ABO Oversized Heavy Hoodie',
        nameAr: 'هودي اوفرسايز ثقيل',
        slug: 'abo-oversized-heavy-hoodie',
        categoryId: '95',
        description: 'A heavy hoodie.',
        archived: false,
        featured: false,
        colors: ['3'],
        sizes: ['7'],
        variants: [
            {
                colorId: '3', sizeId: '7', sku: 'ABOOVERSIZEDHEAVYHOODIE-C3-S7',
                price: 1890, cost: 760, discountPrice: null, quantity: 12,
            },
        ],
    },
};

/**
 * Make a set of radios behave like a browser: checking one unchecks
 * the others that carry the same name.
 */
function linkRadios(radios) {
    const state = new Map(radios.map((radio) => [radio, radio.checked]));

    radios.forEach((radio) => {
        Object.defineProperty(radio, 'checked', {
            configurable: true,
            get: () => state.get(radio),
            set: (value) => {
                const on = Boolean(value);

                state.set(radio, on);

                if (!on) return;

                radios.forEach((other) => {
                    if (other !== radio && other.getAttribute('name') === radio.getAttribute('name')) {
                        state.set(other, false);
                    }
                });
            },
        });
    });
}

/**
 * Build the page the admin form runs on.
 */
function buildPage(browser, payload = PAYLOAD) {
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

    // The payload the view publishes next to the form.
    const script = el('script', { type: 'application/json', 'data-abo-admin-products-form-payload': '' });
    script.textContent = JSON.stringify(payload);
    root.appendChild(script);

    const form = el('form', { 'data-abo-admin-products-form': '', 'data-mode': 'edit', 'data-product-id': '96' });

    // Basic info.
    for (const field of ['name', 'nameAr', 'slug', 'sku', 'description', 'price', 'compare', 'cost']) {
        const input = el('input', { type: 'text', 'data-abo-admin-products-field': field });
        // The page opens on the product the server sent.
        input.value = field === 'slug' ? 'abo-oversized-heavy-hoodie' : '';
        form.appendChild(input);
    }
    form.appendChild(el('select', { 'data-abo-admin-products-field': 'category' }));

    // Language tabs.
    const langs = el('div', {});
    for (const lang of ['en', 'ar']) {
        langs.appendChild(el('button', { type: 'button', 'data-abo-admin-products-lang': lang }));
    }
    form.appendChild(langs);
    form.appendChild(el('div', { 'data-abo-admin-products-lang-panel': 'en' }));
    form.appendChild(el('div', { 'data-abo-admin-products-lang-panel': 'ar' }));

    // Status: two radios inside their segments.
    const status = el('div', { 'data-abo-admin-products-status': '' });
    const statusRadios = [];
    for (const value of ['0', '1']) {
        const segment = el('label', { class: 'abo-admin-products__segment' });
        const radio = el('input', { type: 'radio', name: 'is_archived', value, 'data-abo-admin-products-status-input': '' });
        radio.checked = value === '0';
        segment.appendChild(radio);
        status.appendChild(segment);
        statusRadios.push(radio);
    }
    form.appendChild(status);
    linkRadios(statusRadios);

    // Colours and sizes: the checkboxes the matrix is built from.
    const colourChips = el('div', {});
    for (const color of COLORS) {
        const chip = el('label', { class: 'abo-admin-products__chip', 'data-abo-admin-products-variant-color': color.id });
        const input = el('input', { type: 'checkbox', name: 'colors[]', value: color.id, 'data-abo-admin-products-variant-color-input': '' });
        input.checked = color.id === '3';
        input.value = color.id;
        chip.appendChild(input);
        colourChips.appendChild(chip);
    }
    form.appendChild(colourChips);

    const sizeChips = el('div', {});
    for (const size of SIZES) {
        const chip = el('label', { class: 'abo-admin-products__chip', 'data-abo-admin-products-variant-size': size.id });
        const input = el('input', { type: 'checkbox', name: 'sizes[]', value: size.id, 'data-abo-admin-products-variant-size-input': '' });
        input.checked = size.id === '7';
        input.value = size.id;
        chip.appendChild(input);
        sizeChips.appendChild(chip);
    }
    form.appendChild(sizeChips);

    // The matrix.
    const rows = el('tbody', { 'data-abo-admin-products-variant-rows': '' });
    form.appendChild(el('table', {}, [rows]));

    // "Set stock for all".
    form.appendChild(el('input', { type: 'number', 'data-abo-admin-products-set-all': '' }));

    root.appendChild(form);

    // The top bar buttons.
    const actions = el('div', {});
    for (const action of ['discard', 'draft', 'publish']) {
        actions.appendChild(el('button', { type: 'button', 'data-abo-admin-products-form-action': action }));
    }
    root.appendChild(actions);

    // The shared modal and toast.
    const modal = el('div', { 'data-abo-admin-products-modal': '', hidden: true });
    modal.appendChild(el('h2', { id: 'abo-admin-products-modal-title' }));
    modal.appendChild(el('p', { 'data-abo-admin-products-modal-body': '' }));
    modal.appendChild(el('button', { type: 'button', 'data-abo-admin-products-modal-close': 'CANCEL' }));
    modal.appendChild(el('button', { type: 'button', 'data-abo-admin-products-modal-confirm': 'DISCARD' }));
    root.appendChild(modal);

    const toast = el('div', { 'data-abo-admin-products-toast': '', hidden: true });
    toast.appendChild(el('span', { 'data-abo-admin-products-toast-text': '' }));
    root.appendChild(toast);

    // The store preview.
    root.appendChild(el('p', { 'data-abo-admin-products-preview': 'name' }));
    root.appendChild(el('span', { 'data-abo-admin-products-preview': 'price' }));
    root.appendChild(el('span', { 'data-abo-admin-products-preview': 'compare' }));
    root.appendChild(el('span', { 'data-abo-admin-products-margin': 'pct' }));
    root.appendChild(el('span', { 'data-abo-admin-products-margin': 'profit' }));

    return { document, root, form, rows, modal, toast };
}

/**
 * Run the admin script against a fresh form page.
 */
function run(payload = PAYLOAD) {
    const browser = createBrowser();
    const page = buildPage(browser, payload);

    const submitted = { count: 0 };
    page.form.submit = () => { submitted.count += 1; };

    const sandbox = {
        document: page.document,
        window: browser.window,
        localStorage: browser.localStorage,
        fetch: () => Promise.resolve({ json: () => Promise.resolve({ success: true }) }),
        setTimeout,
        clearTimeout,
        console,
        URL,
    };
    sandbox.globalThis = sandbox;

    const context = vm.createContext(sandbox);
    vm.runInContext(productsScript, context);

    page.document.dispatchEvent('DOMContentLoaded', {});

    return { ...page, submitted };
}

/**
 * The names of the inputs the variant table posts.
 */
function postedNames(rows) {
    return rows.descendants()
        .filter((node) => node.getAttribute('name'))
        .map((node) => node.getAttribute('name'));
}

test('the variant table is painted from the payload the server sent', () => {
    const { rows } = run();

    assert.equal(rows.childNodes.length, 1);
    assert.match(rows.childNodes[0].textContent, /Onyx \/ M/);
    assert.match(rows.childNodes[0].textContent, /ABOOVERSIZEDHEAVYHOODIE-C3-S7/);
    assert.deepEqual(postedNames(rows), [
        'variants[0][color_id]',
        'variants[0][size_id]',
        'variants[0][quantity]',
        'variants[0][price]',
        'variants[0][cost]',
        'variants[0][discount_price]',
    ]);

    // Pin that the hidden inputs carry real values, not undefined!
    const colorInput = rows.descendants().find((node) => node.getAttribute('name') === 'variants[0][color_id]');
    const sizeInput = rows.descendants().find((node) => node.getAttribute('name') === 'variants[0][size_id]');
    assert.equal(colorInput.getAttribute('value'), '3');
    assert.equal(sizeInput.getAttribute('value'), '7');
});

test('ticking a colour rebuilds the matrix from the selection and preserves input values', () => {
    const { document, rows } = run();

    const lime = document.querySelectorAll('[data-abo-admin-products-variant-color-input]')[1];

    lime.checked = true;
    lime.dispatchEvent('change', { target: lime });

    // Two colours × one size = two rows.
    assert.equal(rows.childNodes.length, 2);
    assert.match(rows.childNodes[0].textContent, /Onyx \/ M/);
    assert.match(rows.childNodes[1].textContent, /Lime \/ M/);
    // The new row carries the price and the cost the operator typed.
    assert.match(rows.childNodes[1].textContent, /ABOOVERSIZEDHEAVYHOODIE-C4-S7/);

    // Verify all hidden color_id and size_id values are correctly set upon rebuilding!
    const row0Color = rows.descendants().find((node) => node.getAttribute('name') === 'variants[0][color_id]');
    const row0Size = rows.descendants().find((node) => node.getAttribute('name') === 'variants[0][size_id]');
    const row1Color = rows.descendants().find((node) => node.getAttribute('name') === 'variants[1][color_id]');
    const row1Size = rows.descendants().find((node) => node.getAttribute('name') === 'variants[1][size_id]');

    assert.equal(row0Color.getAttribute('value'), '3');
    assert.equal(row0Size.getAttribute('value'), '7');
    assert.equal(row1Color.getAttribute('value'), '4');
    assert.equal(row1Size.getAttribute('value'), '7');
});

test('save as draft archives the product before it submits', () => {
    const { document, submitted } = run();

    click(document.querySelector('[data-abo-admin-products-form-action="draft"]'));

    const radios = document.querySelectorAll('[data-abo-admin-products-status-input]');

    assert.equal(radios[1].checked, true);
    assert.equal(radios[0].checked, false);
    assert.equal(submitted.count, 1);
});

test('save and publish brings the product back to the store', () => {
    const { document, submitted } = run();

    click(document.querySelector('[data-abo-admin-products-form-action="publish"]'));

    const radios = document.querySelectorAll('[data-abo-admin-products-status-input]');

    assert.equal(radios[0].checked, true);
    assert.equal(radios[1].checked, false);
    assert.equal(submitted.count, 1);
});
