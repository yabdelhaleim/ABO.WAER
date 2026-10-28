// Runtime tests for the cart's minimum-order gate.
//
// The shop can set a minimum order the checkout enforces. The cart has to
// say so while the shopper can still do something about it: the checkout
// link is blocked and a notice says how far the cart is from the minimum.
//
// The PHP suite can only read the JavaScript as text; it cannot tell a
// blocked checkout link from a live one. These tests run resources/js/cart.js
// for real — against a small DOM and a fake server — so "the CTA is blocked
// below the minimum" is a fact, not a string match.
//
// No new dependency: node:test is part of Node itself.

import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

import { createBrowser, installGlobals } from './support/mini-dom.mjs';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const cartModule = pathToFileURL(path.join(projectRoot, 'resources/js/cart.js')).href;

// Every test imports its own copy of the module: the cart keeps its state
// in module scope, and a second test must not inherit the first one's lines.
let instance = 0;

function line(id, variantId, price, quantity = 1) {
    return {
        id,
        product_id: 1,
        variant_id: variantId,
        slug: 'drop-02-hoodie',
        name: 'Drop 02 Hoodie',
        image: 'images/drop-02.jpg',
        price,
        quantity,
        line_total: price * quantity,
        color: 'Onyx',
        color_hex: '#0D0F11',
        size: 'M',
        available_stock: 8,
        is_available: true,
    };
}

function json(status, body) {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    };
}

/**
 * The cart API, held in memory.
 *
 * The summary carries the shop's minimum order, exactly as the endpoint
 * does, so the client is forced to read the figure from the server rather
 * than inventing one.
 */
function createServer(items, minimumOrder) {
    const summary = () => ({
        items,
        item_count: items.reduce((sum, item) => sum + item.quantity, 0),
        subtotal: items.reduce((sum, item) => sum + item.line_total, 0),
        minimum_order: minimumOrder,
        meets_minimum: minimumOrder <= 0 || items.reduce((sum, item) => sum + item.line_total, 0) >= minimumOrder,
        remaining_for_minimum: Math.max(
            0,
            minimumOrder - items.reduce((sum, item) => sum + item.line_total, 0),
        ),
    });

    const fetchImpl = async () => json(200, summary());

    return { fetchImpl };
}

/**
 * Let every pending promise settle.
 */
async function flush() {
    await new Promise((resolve) => setImmediate(resolve));
    await new Promise((resolve) => setImmediate(resolve));
}

async function boot(t, { items = [], minimumOrder = 0 } = {}) {
    const browser = createBrowser();
    const server = createServer(items, minimumOrder);

    // The cart page shell — the hooks cart.js paints into.
    const section = browser.document.createElement('section');
    section.className = 'cart';

    const list = browser.document.createElement('ul');
    list.setAttribute('data-cart-items', '');
    section.appendChild(list);

    const empty = browser.document.createElement('div');
    empty.setAttribute('data-cart-empty', '');
    section.appendChild(empty);

    const summary = browser.document.createElement('aside');
    summary.setAttribute('data-cart-summary', '');
    section.appendChild(summary);

    const note = browser.document.createElement('p');
    note.setAttribute('data-cart-minimum-note', '');
    section.appendChild(note);

    const cta = browser.document.createElement('a');
    cta.setAttribute('data-cart-checkout', '');
    cta.setAttribute('href', '/checkout');
    section.appendChild(cta);

    const mobileCta = browser.document.createElement('a');
    mobileCta.setAttribute('data-cart-mobilebar-checkout', '');
    mobileCta.setAttribute('href', '/checkout');
    section.appendChild(mobileCta);

    const toasts = browser.document.createElement('div');
    toasts.setAttribute('data-cart-toasts', '');
    section.appendChild(toasts);

    // The rules the page publishes — what the cart can paint before the
    // first round trip lands.
    const rules = browser.document.createElement('script');
    rules.setAttribute('data-cart-rules', '');
    rules.textContent = JSON.stringify({ minimum_order: minimumOrder });
    section.appendChild(rules);

    browser.root.appendChild(section);

    installGlobals(browser, server.fetchImpl);

    await import(`${cartModule}?instance=${++instance}`);
    await flush();

    return {
        browser,
        note,
        cta,
        mobileCta,

        blocked: () => cta.getAttribute('aria-disabled') === 'true',
        mobileBlocked: () => mobileCta.getAttribute('aria-disabled') === 'true',
        noteText: () => note.textContent,
        noteShown: () => note.hidden === false,
    };
}

test('a cart below the minimum order blocks the checkout link', async (t) => {
    const cart = await boot(t, {
        items: [line(1, 10, 500)],
        minimumOrder: 750,
    });

    assert.equal(cart.blocked(), true, 'the checkout link must be blocked');
    assert.equal(cart.mobileBlocked(), true, 'the mobile checkout link must be blocked too');
});

test('a cart below the minimum order says how far it is from it', async (t) => {
    const cart = await boot(t, {
        items: [line(1, 10, 500)],
        minimumOrder: 750,
    });

    assert.equal(cart.noteShown(), true, 'the notice must be visible');
    assert.match(cart.noteText(), /250/, 'the notice must say what is missing');
    assert.match(cart.noteText(), /minimum order/i);
});

test('a cart that clears the minimum order lets the shopper through', async (t) => {
    const cart = await boot(t, {
        items: [line(1, 10, 900)],
        minimumOrder: 750,
    });

    assert.equal(cart.blocked(), false, 'the checkout link must be live');
    assert.equal(cart.mobileBlocked(), false);
    assert.equal(cart.noteShown(), false, 'the notice must be gone');
    assert.equal(cart.noteText(), '');
});

test('a shop with no minimum order never blocks the checkout link', async (t) => {
    const cart = await boot(t, {
        items: [line(1, 10, 120)],
        minimumOrder: 0,
    });

    assert.equal(cart.blocked(), false);
    assert.equal(cart.noteShown(), false, 'no minimum means no notice');
    assert.equal(cart.noteText(), '');
});

test('the server’s minimum order is the one the cart obeys', async (t) => {
    // The page published a minimum, but the server answered with
    // another one. The server is the authority.
    const browser = createBrowser();
    const server = createServer([line(1, 10, 500)], 750);

    const section = browser.document.createElement('section');
    section.className = 'cart';

    const list = browser.document.createElement('ul');
    list.setAttribute('data-cart-items', '');
    section.appendChild(list);

    const empty = browser.document.createElement('div');
    empty.setAttribute('data-cart-empty', '');
    section.appendChild(empty);

    const summary = browser.document.createElement('aside');
    summary.setAttribute('data-cart-summary', '');
    section.appendChild(summary);

    const note = browser.document.createElement('p');
    note.setAttribute('data-cart-minimum-note', '');
    section.appendChild(note);

    const cta = browser.document.createElement('a');
    cta.setAttribute('data-cart-checkout', '');
    section.appendChild(cta);

    const rules = browser.document.createElement('script');
    rules.setAttribute('data-cart-rules', '');
    rules.textContent = JSON.stringify({ minimum_order: 0 });
    section.appendChild(rules);

    browser.root.appendChild(section);

    installGlobals(browser, server.fetchImpl);

    await import(`${cartModule}?instance=${++instance}`);
    await flush();

    assert.equal(cta.getAttribute('aria-disabled'), 'true');
    assert.equal(note.hidden, false);
});
