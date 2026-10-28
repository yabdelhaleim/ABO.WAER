// Runtime tests for the admin bell's sound.
//
// The settings screen offers a "Browser sound" switch: "Play a short
// tone when a new notification arrives." Nothing played a tone — the
// switch was a picture of a setting.
//
// Now the server publishes the store's answer as
// window.ADMIN_NOTIFICATIONS_SOUND and admin.js plays a short tone
// when a poll brings a notification the browser has not seen before.
//
// These tests run public/admin/js/admin.js for real, against a small
// DOM, and check that:
//
//   - a notification that arrives after the page loaded rings the tone;
//   - a poll that brings nothing new stays silent;
//   - the store's switch off means silence, whatever arrives.
//
// No new dependency: node:test is part of Node itself.

import test from 'node:test';
import assert from 'node:assert/strict';
import path from 'node:path';
import fs from 'node:fs';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';

import { createBrowser } from './support/mini-dom.mjs';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const adminScript = fs.readFileSync(
    path.join(projectRoot, 'public/admin/js/admin.js'),
    'utf8'
);

/**
 * A row in the bell's shape, as AdminNotificationService serialises it.
 */
function notification(id, overrides = {}) {
    return {
        id,
        type: 'orders',
        tone: 'lime',
        title: `Notification ${id}`,
        time: '1 minute ago',
        href: '/admin/orders',
        unread: true,
        order_id: null,
        ...overrides,
    };
}

/**
 * A Web Audio API that counts what it was asked to play.
 */
function createFakeAudio() {
    const played = { contexts: 0, oscillators: 0 };

    class FakeAudioContext {
        constructor() {
            played.contexts += 1;
            this.currentTime = 0;
            this.destination = {};
        }

        createGain() {
            return {
                gain: { setValueAtTime() {}, exponentialRampToValueAtTime() {} },
                connect() {},
            };
        }

        createOscillator() {
            played.oscillators += 1;

            return {
                type: '',
                frequency: { setValueAtTime() {} },
                connect() {},
                start() {},
                stop() {},
            };
        }

        close() {
            return Promise.resolve();
        }
    }

    return { FakeAudioContext, played };
}

/**
 * The admin page, with the hooks the bell reads.
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

    const bell = el('div', { 'data-abo-admin-bell': '' });
    bell.appendChild(el('button', { type: 'button', 'data-abo-admin-bell-trigger': '' }));
    bell.appendChild(el('span', { 'data-abo-admin-bell-badge': '' }));
    bell.appendChild(el('button', { type: 'button', 'data-abo-admin-bell-mark': '' }));
    bell.appendChild(el('div', { 'data-abo-admin-bell-panel': '', hidden: true }));
    for (const tab of ['all', 'orders', 'stock', 'returns', 'coupons']) {
        bell.appendChild(el('button', { type: 'button', 'data-abo-admin-bell-filter': tab }));
    }
    bell.appendChild(el('ul', { 'data-abo-admin-bell-list': '' }));

    page.appendChild(bell);
    root.appendChild(page);

    return { document, root, page };
}

/**
 * Run admin.js against the page.
 *
 * @param {{ sound: boolean, initial: object[], polled: object|null }} options
 */
function run({ sound, initial = [], polled = null }) {
    const browser = createBrowser();
    const page = buildPage(browser);
    const { FakeAudioContext, played } = createFakeAudio();

    browser.window.INITIAL_ADMIN_NOTIFICATIONS = initial;
    browser.window.ADMIN_NOTIFICATIONS_ENDPOINTS = {
        index: '/admin/notifications',
        markAllRead: '/admin/notifications/mark-all-read',
        markReadBase: '/admin/notifications/',
        pushSubscribe: '/admin/push/subscriptions',
    };
    browser.window.ADMIN_NOTIFICATIONS_SOUND = sound;
    browser.window.AudioContext = FakeAudioContext;

    const intervals = [];

    const fetchImpl = async () => ({
        ok: true,
        json: async () => ({
            unread_count: (polled ?? initial).filter((n) => n.unread).length,
            notifications: polled ?? initial,
        }),
    });

    const sandbox = {
        document: page.document,
        window: browser.window,
        navigator: browser.navigator,
        localStorage: browser.localStorage,
        fetch: fetchImpl,
        // The poll is what brings the news, so the test holds on to it
        // and fires it by hand.
        setInterval: (callback) => {
            intervals.push(callback);

            return intervals.length;
        },
        setTimeout: (callback, delay) => setTimeout(callback, Math.min(delay || 0, 1)),
        clearTimeout,
        requestAnimationFrame: (callback) => setTimeout(callback, 0),
        console,
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
        Set,
        Map,
        URLSearchParams,
    };
    sandbox.globalThis = sandbox;

    const context = vm.createContext(sandbox);
    vm.runInContext(adminScript, context);

    return { ...page, browser, sandbox, intervals, played };
}

/**
 * Fire the poll the way the timer would.
 */
async function poll(sandbox, intervals) {
    assert.ok(intervals.length > 0, 'the bell must poll for new notifications');

    await intervals[0]();
}

test('a notification that arrives after the page loaded rings the tone', async () => {
    const page = run({
        sound: true,
        initial: [notification('a')],
        polled: [notification('a'), notification('b')],
    });

    assert.equal(page.played.contexts, 0, 'nothing rings before the news arrives');

    await poll(page.sandbox, page.intervals);

    assert.equal(page.played.contexts, 1, 'the new notification must ring the bell');
    assert.ok(page.played.oscillators > 0, 'the tone must have notes in it');
});

test('a poll that brings nothing new stays silent', async () => {
    const page = run({
        sound: true,
        initial: [notification('a')],
        polled: [notification('a')],
    });

    await poll(page.sandbox, page.intervals);

    assert.equal(page.played.contexts, 0);
});

test('the store turning the sound off means silence', async () => {
    const page = run({
        sound: false,
        initial: [notification('a')],
        polled: [notification('a'), notification('b')],
    });

    await poll(page.sandbox, page.intervals);

    assert.equal(page.played.contexts, 0, 'the switch off must keep the bell quiet');
});

test('the badge counts the notifications the server sent', async () => {
    const page = run({
        sound: true,
        initial: [],
        polled: [notification('a'), notification('b')],
    });

    await poll(page.sandbox, page.intervals);

    const badge = page.document.querySelector('[data-abo-admin-bell-badge]');

    assert.equal(badge.textContent, '2');
});
