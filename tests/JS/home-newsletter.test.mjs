// Runtime tests for the home page's newsletter form.
//
// The form used to be a theatre: the browser checked the address,
// waited 700ms and printed "YOU'RE IN ✓" without sending anything. The
// shopper was told they had subscribed and the store never learned
// their address.
//
// These tests run resources/js/home.js for real against a small DOM and
// a fake server, so "the success state only appears once the server
// has written the subscription down" is a fact, not a string match.
//
// No new dependency: node:test is part of Node itself.

import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

import { createBrowser, installGlobals } from './support/mini-dom.mjs';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const homeModule = pathToFileURL(path.join(projectRoot, 'resources/js/home.js')).href;

// Every test imports its own copy of the module: the page keeps its
// state in module scope, and a second test must not inherit the first
// one's state.
let instance = 0;

function json(status, body) {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    };
}

/**
 * Let every pending promise settle.
 */
async function flush() {
    await new Promise((resolve) => setImmediate(resolve));
    await new Promise((resolve) => setImmediate(resolve));
    await new Promise((resolve) => setImmediate(resolve));
}

async function boot(t, { respond, email = 'shopper@example.com' } = {}) {
    const browser = createBrowser();
    const calls = [];

    const fetchImpl = async (url, options = {}) => {
        calls.push({
            url: String(url),
            method: (options.method || 'GET').toUpperCase(),
            body: options.body ? JSON.parse(options.body) : null,
        });

        return respond
            ? respond()
            : json(200, { success: true, message: "You're on the list.", already_subscribed: false });
    };

    // The CSRF token the client reads before it posts.
    const meta = browser.document.createElement('meta');
    meta.setAttribute('name', 'csrf-token');
    meta.setAttribute('content', 'test-token');
    browser.root.appendChild(meta);

    // The newsletter panel — the hooks home.js paints into.
    const section = browser.document.createElement('section');
    section.className = 'newsletter';

    const form = browser.document.createElement('form');
    form.setAttribute('data-home-newsletter', '');
    form.setAttribute('action', '/newsletter/subscribe');
    form.setAttribute('method', 'POST');

    const input = browser.document.createElement('input');
    input.setAttribute('type', 'email');
    input.setAttribute('name', 'email');
    input.value = email;
    form.appendChild(input);

    const button = browser.document.createElement('button');
    button.setAttribute('type', 'submit');
    form.appendChild(button);

    const success = browser.document.createElement('span');
    success.setAttribute('data-home-newsletter-success', '');
    success.textContent = "YOU'RE IN ✓";
    success.hidden = true;
    form.appendChild(success);

    section.appendChild(form);

    const hint = browser.document.createElement('p');
    hint.className = 'newsletter__hint';
    section.appendChild(hint);

    browser.root.appendChild(section);

    installGlobals(browser, fetchImpl);

    await import(`${homeModule}?instance=${++instance}`);
    await flush();

    return {
        calls,
        form,
        input,
        success,
        hint,

        successShown: () => success.hidden === false,
        hintText: () => hint.textContent,

        async submit() {
            form.dispatchEvent('submit', {
                target: form,
                preventDefault() {},
                stopPropagation() {},
            });

            await flush();
        },
    };
}

test('the address is posted to the server', async (t) => {
    const page = await boot(t);

    await page.submit();

    assert.equal(page.calls.length, 1, 'the form must post exactly once');
    assert.equal(page.calls[0].method, 'POST');
    assert.equal(page.calls[0].url, '/newsletter/subscribe');
    assert.equal(page.calls[0].body.email, 'shopper@example.com');
});

test('the success state appears only after the server has saved the address', async (t) => {
    const page = await boot(t);

    assert.equal(page.successShown(), false, 'nothing is claimed before the request');

    await page.submit();

    assert.equal(page.successShown(), true);
    assert.match(page.hintText(), /on the list/i);
});

test('a refused request is never dressed up as a subscription', async (t) => {
    const page = await boot(t, {
        respond: () => json(422, { message: 'The given email address is invalid.', errors: { email: ['The given email address is invalid.'] } }),
    });

    await page.submit();

    assert.equal(page.successShown(), false, 'a failed subscription must not show the success state');
    assert.match(page.hintText(), /invalid/i, 'the shopper is told what went wrong');
});

test('a network failure is never dressed up as a subscription', async (t) => {
    const page = await boot(t, {
        respond: () => {
            throw new Error('offline');
        },
    });

    await page.submit();

    assert.equal(page.successShown(), false);
    assert.match(page.hintText(), /could not reach the store/i);
});

test('an address that is not an address never leaves the browser', async (t) => {
    const page = await boot(t, { email: 'not-an-address' });

    await page.submit();

    assert.equal(page.calls.length, 0, 'the browser must not post an address it knows is invalid');
    assert.equal(page.successShown(), false);
    assert.match(page.hintText(), /valid email/i);
});
