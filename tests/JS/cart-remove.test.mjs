// Runtime tests for the cart's removal flow.
//
// The PHP suite can only read the JavaScript as text; it cannot tell a
// working delete from a dead one. These tests run resources/js/cart.js
// for real — against a small DOM, a fake server and Node's own clock —
// so "the DELETE is sent when the undo window closes" is a fact, not a
// string match.
//
// What is pinned here:
//   - the deferred delete actually reaches the server, with the cart
//     line id and not the variant id;
//   - UNDO inside the window sends nothing at all;
//   - a refused delete leaves the page and the server agreeing;
//   - leaving the page sends the delete once, never twice.
//
// No new dependency: node:test is part of Node itself.

import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

import { click, createBrowser, installGlobals } from './support/mini-dom.mjs';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const cartModule = pathToFileURL(path.join(projectRoot, 'resources/js/cart.js')).href;

// Every test imports its own copy of the module: the cart keeps its
// state in module scope, and a second test must not inherit the first
// one's lines.
let instance = 0;

function line(id, variantId, quantity = 2) {
    return {
        id,
        product_id: 1,
        variant_id: variantId,
        slug: 'drop-02-hoodie',
        name: 'Drop 02 Hoodie',
        image: 'images/drop-02.jpg',
        price: 1490,
        quantity,
        line_total: 1490 * quantity,
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
 * The delete endpoint answers 204 with no body — the same shape the
 * Laravel route returns — so the client is forced to learn the truth
 * from a second call instead of reading a summary that is not there.
 */
function createServer(items, { failDelete = false } = {}) {
    const state = { items: items.map((item) => ({ ...item })) };
    const calls = [];

    const summary = () => ({
        items: state.items,
        item_count: state.items.reduce((sum, item) => sum + item.quantity, 0),
        subtotal: state.items.reduce((sum, item) => sum + item.line_total, 0),
    });

    const fetchImpl = async (url, options = {}) => {
        const method = (options.method || 'GET').toUpperCase();

        calls.push({
            method,
            url: String(url),
            body: options.body ? JSON.parse(options.body) : null,
            keepalive: Boolean(options.keepalive),
        });

        if (method === 'GET') {
            return json(200, summary());
        }

        if (method === 'DELETE') {
            if (failDelete) {
                return json(500, { message: 'The cart could not be updated.' });
            }

            const id = Number(String(url).split('/').pop());
            const index = state.items.findIndex((item) => item.id === id);

            if (index === -1) {
                return json(404, { message: 'That line is already gone.' });
            }

            state.items.splice(index, 1);

            return {
                ok: true,
                status: 204,
                json: async () => {
                    throw new Error('No body');
                },
            };
        }

        return json(405, { message: `Unexpected method ${method}.` });
    };

    return { state, calls, fetchImpl };
}

/**
 * Let every pending promise settle.
 */
async function flush() {
    await new Promise((resolve) => setImmediate(resolve));
    await new Promise((resolve) => setImmediate(resolve));
}

async function boot(t, { items = [], failDelete = false } = {}) {
    // The clock is the test's: the undo window is five seconds of
    // nothing happening, and the test decides when those seconds pass.
    t.mock.timers.enable({ apis: ['setTimeout'] });

    const browser = createBrowser();
    const server = createServer(items, { failDelete });

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

    const toasts = browser.document.createElement('div');
    toasts.setAttribute('data-cart-toasts', '');
    section.appendChild(toasts);

    browser.root.appendChild(section);

    installGlobals(browser, server.fetchImpl);

    await import(`${cartModule}?instance=${++instance}`);
    await flush();

    return {
        server,
        browser,

        rows: () => list.querySelectorAll('.cart__row'),
        row: (lineId) => list.querySelector(`.cart__row[data-line-id="${lineId}"]`),

        clickRemove(lineId) {
            const row = list.querySelector(`.cart__row[data-line-id="${lineId}"]`);

            assert.ok(row, `no cart row for ${lineId}`);

            const button = row.querySelector('[data-remove-line]');

            assert.ok(button, `no remove button for ${lineId}`);

            click(button);
        },

        clickUndo() {
            const button = toasts.querySelector('.cart__toast-undo');

            assert.ok(button, 'no UNDO button was offered');

            click(button);
        },

        toastTexts: () => toasts.querySelectorAll('.cart__toast-text').map((node) => node.textContent),

        deletes: () => server.calls.filter((call) => call.method === 'DELETE'),

        leavePage() {
            browser.window.dispatchEvent('pagehide', {});
        },

        async tick(milliseconds) {
            t.mock.timers.tick(milliseconds);
            await flush();
        },
    };
}

test('the delete reaches the server once the undo window closes', async (t) => {
    const cart = await boot(t, {
        items: [line(7, 12), line(8, 13)],
    });

    assert.equal(cart.rows().length, 2, 'both lines are on screen to begin with');

    cart.clickRemove('v12');

    // Inside the window the line is only off the screen — the server
    // still has it.
    assert.equal(cart.rows().length, 1);
    assert.equal(cart.server.state.items.length, 2);
    assert.equal(cart.deletes().length, 0, 'nothing is sent while the window is open');

    await cart.tick(5000);

    assert.equal(cart.deletes().length, 1, 'the delete is sent when the window closes');
    assert.equal(
        cart.deletes()[0].url,
        '/cart/items/7',
        'the delete names the cart line id, not the variant id',
    );

    // The server and the page agree: the line is gone from both, and
    // the other line is untouched.
    assert.equal(cart.server.state.items.length, 1);
    assert.equal(cart.server.state.items[0].id, 8);
    assert.equal(cart.rows().length, 1, 'the surviving line is still on screen');
    assert.equal(cart.row('v12'), null, 'the removed line is gone from the screen');
});

test('undo inside the window sends nothing and keeps the line', async (t) => {
    const cart = await boot(t, {
        items: [line(7, 12), line(8, 13)],
    });

    cart.clickRemove('v12');
    assert.equal(cart.rows().length, 1);

    await cart.tick(1000);
    cart.clickUndo();

    // The window is over now — and the line was never deleted.
    await cart.tick(10000);

    assert.equal(cart.deletes().length, 0, 'undo sends no delete');
    assert.equal(cart.server.state.items.length, 2, 'the server still has both lines');
    assert.equal(cart.rows().length, 2, 'the line is back on screen');
});

test('a refused delete leaves the page and the server agreeing', async (t) => {
    const cart = await boot(t, {
        items: [line(7, 12), line(8, 13)],
        failDelete: true,
    });

    cart.clickRemove('v12');
    await cart.tick(5000);

    assert.equal(cart.deletes().length, 1, 'the delete was attempted');

    // The server refused, so it still has the line — and so does the
    // page. A line that vanished from the screen while the server kept
    // it would leave the two out of step.
    assert.equal(cart.server.state.items.length, 2);
    assert.equal(cart.rows().length, 2, 'the line is back on screen');
    assert.ok(
        cart.toastTexts().some((text) => /could not be updated/i.test(text)),
        `the refusal is reported, saw: ${JSON.stringify(cart.toastTexts())}`,
    );
});

test('leaving the page during the window sends the delete once', async (t) => {
    const cart = await boot(t, {
        items: [line(7, 12), line(8, 13)],
    });

    cart.clickRemove('v12');
    cart.leavePage();
    await flush();

    assert.equal(cart.deletes().length, 1, 'the delete goes out with the page');
    assert.equal(cart.deletes()[0].url, '/cart/items/7');
    assert.equal(cart.deletes()[0].keepalive, true, 'the request outlives the page');

    // The undo timer was cancelled on the way out, so it cannot send a
    // second one.
    await cart.tick(10000);
    assert.equal(cart.deletes().length, 1, 'the delete is not sent twice');
});

test('leaving the page after the window closed does not delete twice', async (t) => {
    const cart = await boot(t, {
        items: [line(7, 12), line(8, 13)],
    });

    cart.clickRemove('v12');
    await cart.tick(5000);

    assert.equal(cart.deletes().length, 1);

    cart.leavePage();
    await flush();

    assert.equal(cart.deletes().length, 1, 'the delete is not repeated');
});
