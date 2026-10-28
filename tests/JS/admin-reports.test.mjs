// Runtime tests for the admin reports screen.
//
// The screen used to draw a static REPORTS_DATA object that lived in
// public/admin/js/reports.js: every number on it was invented, and
// the "compare to previous period" arrows were a fixed 7% and a fixed
// -5%. It now draws the report the server measured, and asks the
// server for another range when the toolbar changes.
//
// These tests run public/admin/js/reports.js for real — against a
// small DOM and a fake server — and check that:
//
//   - the KPI cards carry the numbers the server sent;
//   - the comparison is the real difference against the period before;
//   - switching the range asks the server for that range and draws
//     the answer;
//   - the export button sends the browser to the export endpoint with
//     the range that is on screen.
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
const reportsScript = fs.readFileSync(
    path.join(projectRoot, 'public/admin/js/reports.js'),
    'utf8'
);

/**
 * The report, as Admin\ReportService sends it.
 */
const REPORT = {
    range: { key: '7d', label: 'Last 7 days', from: '2026-10-22', to: '2026-10-28' },
    series: {
        days: ['22', '23', '24', '25', '26', '27', '28'],
        revenue: [0, 550, 0, 1100, 0, 0, 2200],
        orders: [0, 1, 0, 2, 0, 0, 4],
        items: [0, 1, 0, 3, 0, 0, 6],
        profit: [0, 150, 0, 300, 0, 0, 400],
        cog: [0, 400, 0, 800, 0, 0, 1800],
        ship: [0, 50, 0, 100, 0, 0, 200],
        returns: [0, 0, 0, 1, 0, 0, 0],
        waste: [0, 0, 0, 700, 0, 0, 0],
    },
    totals: {
        revenue: 3850, orders: 7, items: 10, aov: 550,
        cog: 3000, ship: 350, profit: 850, margin: 22.08,
        returns: 1, waste: 700,
    },
    previous: {
        series: { days: [], revenue: [], orders: [], items: [], profit: [], cog: [], ship: [], returns: [], waste: [] },
        totals: {
            revenue: 2750, orders: 5, items: 7, aov: 550,
            cog: 2000, ship: 250, profit: 750, margin: 27.27,
            returns: 2, waste: 1400,
        },
    },
    reasons: [{ key: 'r1', label: 'Wrong size', color: 'coral' }],
    reasonsByDay: [{ r1: 1 }],
    governorates: [{ name: 'Cairo', share: 60, revenue: 2310, orders: 4 }],
    categories: [{ name: 'Hoodies', share: 100, revenue: 3850, cog: 3000, profit: 850 }],
    statusSegments: [
        { key: 'new', label: 'New', count: 2, hue: 'lime' },
        { key: 'delivered', label: 'Delivered', count: 5, hue: 'olive' },
    ],
    costBreakdown: [
        { key: 'cog', label: 'Cost of goods', count: 3000, hue: 'lime' },
        { key: 'ship', label: 'Shipping collected', count: 350, hue: 'olive' },
    ],
    topProducts: [
        { id: '1', name: 'Oversize Hoodie', cat: 'Hoodies', units: 10, rev: 3850, profit: 850, ret: 9.1 },
    ],
    topReturnReason: { name: 'Wrong size', share: 100 },
};

/**
 * The routes the controller renders.
 */
const ROUTES = {
    range: { url: '/admin/reports/range/__RANGE__' },
    export: { url: '/admin/reports/export' },
};

/**
 * The reports page, with the hooks reports.js reads.
 */
function buildPage(browser, report) {
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

    const payload = el('script', { 'data-abo-admin-reports-payload': '' });
    payload.textContent = JSON.stringify(report);
    page.appendChild(payload);

    const routes = el('script', { 'data-abo-admin-reports-routes': '' });
    routes.textContent = JSON.stringify(ROUTES);
    page.appendChild(routes);

    page.appendChild(el('p', { 'data-abo-admin-reports-generated': '' }));
    page.appendChild(el('time', { 'data-abo-admin-reports-generated-time': '' }));

    // The toolbar.
    const range = el('div', { 'data-abo-admin-reports-range': '' });
    for (const key of ['today', '7d', '30d', 'month', 'custom']) {
        range.appendChild(el('button', { type: 'button', 'data-range': key }));
    }
    page.appendChild(range);

    page.appendChild(el('div', { 'data-abo-admin-reports-custom': '', hidden: true }));
    page.appendChild(el('input', { type: 'date', 'data-abo-admin-reports-from': '' }));
    page.appendChild(el('input', { type: 'date', 'data-abo-admin-reports-to': '' }));
    page.appendChild(el('input', { type: 'checkbox', 'data-abo-admin-reports-compare': '' }));

    // The tabs.
    const tabs = el('div', { 'data-abo-admin-reports-tabs': '' });
    for (const key of ['sales', 'profit', 'waste', 'top']) {
        tabs.appendChild(el('button', { type: 'button', 'data-tab': key }));
    }
    page.appendChild(tabs);

    // The sections.
    page.appendChild(el('div', { 'data-abo-admin-reports-kpis': '' }));
    page.appendChild(el('section', { 'data-abo-admin-reports-main': '' }));
    page.appendChild(el('h2', { 'data-abo-admin-reports-main-title': '' }));
    page.appendChild(el('ul', { 'data-abo-admin-reports-main-legend': '' }));
    page.appendChild(el('div', { 'data-abo-admin-reports-main-chart': '' }));

    page.appendChild(el('section', { 'data-abo-admin-reports-breakdown': '' }));
    page.appendChild(el('h2', { 'data-abo-admin-reports-breakdown1-title': '' }));
    page.appendChild(el('p', { 'data-abo-admin-reports-breakdown1-soft': '' }));
    page.appendChild(el('div', { 'data-abo-admin-reports-breakdown1': '' }));
    page.appendChild(el('h2', { 'data-abo-admin-reports-breakdown2-title': '' }));
    page.appendChild(el('p', { 'data-abo-admin-reports-breakdown2-soft': '' }));
    page.appendChild(el('div', { 'data-abo-admin-reports-breakdown2': '' }));
    page.appendChild(el('div', { 'data-abo-admin-reports-segbar': '' }));
    page.appendChild(el('ul', { 'data-abo-admin-reports-seglegend': '' }));

    page.appendChild(el('section', { 'data-abo-admin-reports-top': '', hidden: true }));
    page.appendChild(el('input', { type: 'search', 'data-abo-admin-reports-top-search': '' }));
    page.appendChild(el('select', { 'data-abo-admin-reports-top-category': '' }));

    const table = el('table', { 'data-abo-admin-reports-top-table': '' });
    const head = el('thead', {});
    const headRow = el('tr', {});
    for (const key of ['rank', 'name', 'units', 'rev', 'profit', 'ret']) {
        headRow.appendChild(el('th', { 'data-sort': key }));
    }
    head.appendChild(headRow);
    table.appendChild(head);
    table.appendChild(el('tbody', { 'data-abo-admin-reports-top-body': '' }));
    page.appendChild(table);

    // The export buttons.
    page.appendChild(el('button', { type: 'button', 'data-abo-admin-reports-export': 'csv' }));
    page.appendChild(el('button', { type: 'button', 'data-abo-admin-reports-export': 'pdf' }));

    // The toast.
    const toast = el('div', { 'data-abo-admin-reports-toast': '', hidden: true });
    toast.appendChild(el('span', { 'data-abo-admin-reports-toast-dot': '' }));
    toast.appendChild(el('span', { 'data-abo-admin-reports-toast-text': '' }));
    page.appendChild(toast);

    root.appendChild(page);

    return { document, root, page };
}

/**
 * Run reports.js against the page.
 *
 * @param {{ report?: object, respond?: (call) => object }} options
 */
function run({ report = REPORT, respond } = {}) {
    const browser = createBrowser();
    const page = buildPage(browser, report);

    const calls = [];
    const assigned = [];
    const printed = [];

    const fetchStub = async (url, options = {}) => {
        calls.push({ url, method: options.method || 'GET' });

        const answer = respond ? respond({ url }) : null;
        const payload = answer === null ? { success: true, report: REPORT } : answer;

        return {
            ok: payload.success !== false,
            status: payload.success === false ? 422 : 200,
            json: async () => payload,
        };
    };

    const sandbox = {
        document: page.document,
        window: {
            ...browser.window,
            location: { assign: (url) => assigned.push(url), href: '' },
            print: () => printed.push(true),
        },
        localStorage: browser.localStorage,
        fetch: fetchStub,
        setTimeout: (callback, delay) => setTimeout(callback, Math.min(delay || 0, 1)),
        clearTimeout,
        requestAnimationFrame: (callback) => setTimeout(callback, 0),
        history: { replaceState() {} },
        URLSearchParams,
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
    vm.runInContext(reportsScript, context);

    return { ...page, calls, assigned, printed, browser, sandbox };
}

/** Let the promise chains the screen starts settle. */
const tick = () => new Promise((resolve) => setTimeout(resolve, 5));

/**
 * The text of one KPI card.
 */
function kpi(page, label) {
    const card = page.document.querySelector(`[data-abo-admin-reports-kpi="${label}"]`);
    return card ? card.textContent : null;
}

test('the KPI cards carry the numbers the server sent', () => {
    const page = run();

    assert.equal(kpi(page, 'REVENUE').includes('3,850 EGP'), true);
    assert.equal(kpi(page, 'ORDERS').includes('7'), true);
    assert.equal(kpi(page, 'AVG ORDER').includes('550 EGP'), true);
    assert.equal(kpi(page, 'ITEMS SOLD').includes('10'), true);
});

test('the comparison is the real difference against the period before', () => {
    const page = run();

    // The comparison is off: the card shows no arrow.
    assert.equal(kpi(page, 'REVENUE').includes('vs previous'), false);

    const compare = page.document.querySelector('[data-abo-admin-reports-compare]');
    compare.checked = true;
    compare.dispatchEvent('change', {});

    // 3,850 against 2,750 is +40.0%.
    assert.equal(kpi(page, 'REVENUE').includes('+40.0% vs previous'), true);
});

test('switching the range asks the server for that range', async () => {
    const page = run({
        respond: () => ({
            success: true,
            report: {
                ...REPORT,
                range: { key: 'today', label: 'Today', from: '2026-10-28', to: '2026-10-28' },
                totals: { ...REPORT.totals, revenue: 2200, orders: 4 },
            },
        }),
    });

    const today = page.document.querySelector('button[data-range="today"]');
    click(today);
    await tick();

    assert.equal(page.calls.length, 1);
    assert.equal(page.calls[0].url, '/admin/reports/range/today');

    // The screen now shows the answer, not the report it started with.
    assert.equal(kpi(page, 'REVENUE').includes('2,200 EGP'), true);
});

test('a refused range leaves the report on screen as it was', async () => {
    const page = run({
        respond: () => ({ success: false, message: 'Not allowed.' }),
    });

    const today = page.document.querySelector('button[data-range="today"]');
    click(today);
    await tick();

    assert.equal(page.calls.length, 1);
    assert.equal(kpi(page, 'REVENUE').includes('3,850 EGP'), true);
});

test('the export button sends the browser to the export endpoint', () => {
    const page = run();

    const csv = page.document.querySelector('[data-abo-admin-reports-export="csv"]');
    click(csv);

    assert.equal(page.assigned.length, 1);
    assert.equal(page.assigned[0].startsWith('/admin/reports/export?range=7d'), true);
});

test('the PDF button opens the print dialog', () => {
    const page = run();

    const pdf = page.document.querySelector('[data-abo-admin-reports-export="pdf"]');
    click(pdf);

    assert.equal(page.printed.length, 1);
    assert.equal(page.assigned.length, 0);
});
