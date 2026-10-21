// ABO Admin · Reports (front-end only, static data).
//
// Scope (per the project scope rules):
//   - No fetch / XHR / WebSockets. No localStorage / IndexedDB.
//   - Every section hydrates from the static REPORTS_DATA object
//     below. The shape is final; replacing values with API data is
//     the only change required to wire a real backend.
//   - Charts are plain SVG, drawn in this file. No chart library.
//   - Export buttons are visual only — they fire a toast saying
//     "Export started (demo)".

/* eslint-disable no-console */

// ─── Tiny helpers ────────────────────────────────────────────

var $ = window.$ || ((sel, root = document) => root.querySelector(sel));
var $$ = window.$$ || ((sel, root = document) => Array.from(root.querySelectorAll(sel)));

function el(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);
    for (const [k, v] of Object.entries(attrs)) {
        if (v == null || v === false) continue;
        if (k === 'class') node.className = v;
        else if (k === 'text') node.textContent = v;
        else if (k === 'html') node.innerHTML = v;
        else node.setAttribute(k, v);
    }
    for (const c of [].concat(children)) {
        if (c == null) continue;
        node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    }
    return node;
}

const fmtNum = (n) => {
    if (n == null || Number.isNaN(n)) return '—';
    return new Intl.NumberFormat('en-US').format(Math.round(n));
};

const fmtPct = (n) => {
    if (n == null || Number.isNaN(n)) return '—';
    const sign = n > 0 ? '+' : '';
    return `${sign}${n.toFixed(1)}%`;
};

const fmtCurrency = (n) => {
    if (n == null || Number.isNaN(n)) return '—';
    return `${fmtNum(n)} EGP`;
};

const fmtDate = (d) => {
    try {
        return new Intl.DateTimeFormat('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(d);
    } catch (_) {
        return d.toDateString();
    }
};

// ─── Static data (single source of truth) ─────────────────────

// TODO(backend): replace with API data. Every reference below
// stays — only the values change.
const REPORTS_DATA = {
    // Each per-day series. Index 0 = oldest, last = most recent.
    // Values are realistic magnitudes; the JS layer applies small
    // scaling multipliers per range so "Today" / "7d" / "30d" /
    // "This month" all look distinct without authoring four arrays.
    series: {
        // Day labels; only the trailing N are kept for the active range.
        days: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],

        // Revenue (EGP) per day.
        revenue: [12_400, 14_800, 11_200, 17_600, 21_300, 18_400, 19_750],

        // Orders per day.
        orders:  [18, 22, 16, 27, 33, 28, 31],

        // Net profit per day.
        profit:  [3_100, 3_900, 2_700, 4_650, 5_800, 4_900, 5_320],

        // Cost of goods per day.
        cog:     [7_400, 8_700, 6_700, 10_300, 12_500, 10_800, 11_600],

        // Shipping cost per day.
        ship:    [1_900, 2_200, 1_800, 2_650, 2_990, 2_700, 2_850],

        // Returns per day (count of returned orders).
        returns: [1, 2, 0, 1, 3, 2, 2],

        // Waste value per day (EGP).
        waste:   [620, 980, 0, 410, 1_540, 870, 1_080],
    },

    // The 4 reason buckets for the stacked chart + reasons list.
    reasons: [
        { key: 'size',    label: 'Wrong size',     color: 'coral' },
        { key: 'damaged', label: 'Damaged',        color: 'amber' },
        { key: 'wrong',   label: 'Not as described', color: 'dim'   },
        { key: 'other',   label: 'Other',          color: 'stone' },
    ],

    // Reason counts per day — used by the stacked chart. Numbers
    // intentionally small so the stack reads cleanly.
    reasonsByDay: [
        { size: 1, damaged: 0, wrong: 0, other: 0 },
        { size: 1, damaged: 1, wrong: 0, other: 0 },
        { size: 0, damaged: 0, wrong: 0, other: 0 },
        { size: 1, damaged: 0, wrong: 0, other: 0 },
        { size: 1, damaged: 1, wrong: 1, other: 0 },
        { size: 1, damaged: 1, wrong: 0, other: 0 },
        { size: 1, damaged: 1, wrong: 0, other: 0 },
    ],

    // Top governorates — used by Sales by governorate.
    governorates: [
        { name: 'Cairo',        share: 32 },
        { name: 'Giza',         share: 18 },
        { name: 'Alexandria',   share: 14 },
        { name: 'Sharqia',      share: 8  },
        { name: 'Dakahlia',     share: 7  },
        { name: 'Gharbia',      share: 6  },
        { name: 'Qalyubia',     share: 5  },
        { name: 'Beheira',      share: 4  },
    ],

    // Profit by category — used on the Profit tab.
    categories: [
        { name: 'Hoodies',  share: 28 },
        { name: 'Tees',     share: 22 },
        { name: 'Pants',    share: 16 },
        { name: 'Shirts',   share: 12 },
        { name: 'Jackets',  share: 10 },
        { name: 'Accessories', share: 7 },
        { name: 'Footwear', share: 5 },
    ],

    // Orders by status — reused across the Sales and Profit tabs.
    statusSegments: [
        { key: 'new',        label: 'New',         count: 22, hue: 'lime'  },
        { key: 'processing', label: 'Processing',  count: 14, hue: 'amber' },
        { key: 'shipped',    label: 'Shipped',     count: 18, hue: 'sky'   },
        { key: 'delivered',  label: 'Delivered',   count: 96, hue: 'olive' },
        { key: 'cancelled',  label: 'Cancelled',   count: 6,  hue: 'gray'  },
    ],

    // Cost breakdown — used on the Profit tab.
    costBreakdown: [
        { key: 'cog',  label: 'Cost of goods', count: 51_500, hue: 'lime'  },
        { key: 'ship', label: 'Shipping',      count: 15_200, hue: 'olive' },
        { key: 'pay',  label: 'Payment fees',  count: 4_800,  hue: 'amber' },
        { key: 'mkt',  label: 'Marketing',     count: 3_100,  hue: 'sky'   },
        { key: 'oth',  label: 'Other',         count: 1_400,  hue: 'gray'  },
    ],

    // Top products — used on the Top products tab.
    topProducts: [
        { id: 'p-01', name: 'Oversize hoodie',     cat: 'Hoodies',     units: 142, rev: 71_000, profit: 21_300, ret: 4.2 },
        { id: 'p-02', name: 'Cargo pants',         cat: 'Pants',       units: 118, rev: 53_100, profit: 15_900, ret: 6.1 },
        { id: 'p-03', name: 'Heavy tee',           cat: 'Tees',        units: 96,  rev: 19_200, profit:  6_720, ret: 3.4 },
        { id: 'p-04', name: 'Linen overshirt',     cat: 'Shirts',      units: 74,  rev: 33_300, profit:  9_990, ret: 8.5 },
        { id: 'p-05', name: 'Bomber jacket',       cat: 'Jackets',     units: 38,  rev: 30_400, profit:  9_120, ret: 5.2 },
        { id: 'p-06', name: 'Wide-leg trousers',   cat: 'Pants',       units: 52,  rev: 20_800, profit:  6_240, ret: 2.8 },
        { id: 'p-07', name: 'Cap',                 cat: 'Accessories', units: 88,  rev:  8_800, profit:  3_520, ret: 1.4 },
        { id: 'p-08', name: 'Court sneaker',       cat: 'Footwear',    units: 41,  rev: 24_600, profit:  7_380, ret: 9.6 },
        { id: 'p-09', name: 'Pocket tee',          cat: 'Tees',        units: 65,  rev: 11_700, profit:  4_095, ret: 3.1 },
        { id: 'p-10', name: 'Coach jacket',        cat: 'Jackets',     units: 22,  rev: 17_600, profit:  5_280, ret: 4.7 },
    ],

    // Range scaling — multiplies the canonical 7-day series so each
    // range reads distinctly without authoring 4 separate arrays.
    rangeScale: {
        today: 0.32,
        '7d':  1.0,
        '30d': 4.0,
        month: 3.4,
        custom: 1.4,
    },

    // Top return reason — used by the Waste tab KPI.
    topReturnReason: { name: 'Wrong size', share: 48 },
};

// ─── State ────────────────────────────────────────────────────

const state = {
    range: '7d',          // 'today' | '7d' | '30d' | 'month' | 'custom'
    compare: false,       // "compare to previous period"
    tab: 'sales',         // 'sales' | 'profit' | 'waste' | 'top'
    custom: { from: null, to: null },
    topSort: { key: 'units', dir: 'desc' },
    topFilter: { q: '', cat: '' },
};

// ─── Range helpers ────────────────────────────────────────────

function scaledSeries() {
    const s = REPORTS_DATA.series;
    const scale = REPORTS_DATA.rangeScale[state.range] ?? 1;
    return {
        days: s.days.slice(),
        revenue:  s.revenue.map((v) => v * scale),
        orders:   s.orders.map((v)  => v * scale),
        profit:   s.profit.map((v)  => v * scale),
        cog:      s.cog.map((v)     => v * scale),
        ship:     s.ship.map((v)    => v * scale),
        returns:  s.returns.map((v) => v * scale),
        waste:    s.waste.map((v)   => v * scale),
    };
}

function previousPeriodFactor() {
    // Compare = on: previous period is roughly the same magnitude
    // (small noise). Off: no comparison.
    if (!state.compare) return null;
    return 0.93;
}

function delta(cur, prev) {
    if (prev == null || prev === 0) return null;
    return ((cur - prev) / prev) * 100;
}

// ─── Range label ─────────────────────────────────────────────

function rangeLabel() {
    switch (state.range) {
        case 'today':  return 'Today';
        case '7d':     return 'Last 7 days';
        case '30d':    return 'Last 30 days';
        case 'month':  return 'This month';
        case 'custom': return `${state.custom.from || '…'} → ${state.custom.to || '…'}`;
        default:       return 'Last 7 days';
    }
}

// ─── Toast ────────────────────────────────────────────────────

let toastTimer = null;
function showToast(text) {
    const wrap = $('[data-abo-admin-reports-toast]');
    const txt  = $('[data-abo-admin-reports-toast-text]');
    if (!wrap || !txt) return;
    txt.textContent = text;
    wrap.hidden = false;
    // Force reflow so the transition triggers even when called rapidly.
    void wrap.offsetWidth;
    wrap.classList.add('is-open');
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        wrap.classList.remove('is-open');
        setTimeout(() => { wrap.hidden = true; }, 240);
    }, 1800);
}

// ─── Footer + generated date ─────────────────────────────────

function setGenerated() {
    const el1 = $('[data-abo-admin-reports-generated]');
    const el2 = $('[data-abo-admin-reports-generated-time]');
    const now = new Date();
    const text = fmtDate(now);
    if (el1) el1.textContent = `Sample data — generated ${text}`;
    if (el2) {
        el2.textContent = text;
        el2.setAttribute('datetime', now.toISOString());
    }
}

// ─── KPI rendering ────────────────────────────────────────────

function buildKpi(label, value, deltaVal, tone, spark) {
    const upClass = (deltaVal == null) ? '' : (deltaVal >= 0 ? 'is-up' : 'is-down');
    const arrow = (deltaVal == null) ? '' : (deltaVal >= 0 ? '▲' : '▼');
    const deltaText = (deltaVal == null) ? '—' : fmtPct(deltaVal);
    const sparkId = `abo-admin-reports-spark-${label.replace(/\s+/g, '-').toLowerCase()}`;

    const card = el('article', { class: `abo-admin-kpi abo-admin-reports__kpi ${upClass}`, 'data-abo-admin-reports-kpi': label });

    card.appendChild(el('p', { class: 'abo-admin-kpi__label', text: label }));

    const toneClass = `abo-admin-kpi__value--${tone || 'lime'}`;
    card.appendChild(el('p', { class: `abo-admin-kpi__value ${toneClass}`, text: value }));

    const deltaEl = el('p', { class: 'abo-admin-kpi__delta' });
    if (deltaVal == null) {
        deltaEl.textContent = '—';
    } else {
        deltaEl.appendChild(el('span', { class: 'abo-admin-kpi__delta-arrow', text: arrow }));
        deltaEl.appendChild(document.createTextNode(` ${deltaText} vs previous`));
    }
    card.appendChild(deltaEl);

    // Sparkline as a small polyline, drawn like the dashboard's.
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'abo-admin-kpi__spark');
    svg.setAttribute('viewBox', '0 0 100 28');
    svg.setAttribute('preserveAspectRatio', 'none');
    svg.setAttribute('aria-hidden', 'true');
    const poly = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
    poly.setAttribute('fill', 'none');
    poly.setAttribute('stroke', 'currentColor');
    poly.setAttribute('stroke-width', '1.4');
    poly.setAttribute('stroke-linecap', 'round');
    poly.setAttribute('stroke-linejoin', 'round');
    const max = Math.max(0, spark.length - 1);
    const step = max > 0 ? 100 / max : 0;
    poly.setAttribute('points', spark.map((v, i) => `${(i * step).toFixed(2)},${(28 - v * 24 - 2).toFixed(2)}`).join(' '));
    svg.appendChild(poly);
    card.appendChild(svg);

    return card;
}

function renderKpis() {
    const wrap = $('[data-abo-admin-reports-kpis]');
    if (!wrap) return;
    wrap.replaceChildren();

    const s = scaledSeries();
    const prev = previousPeriodFactor();

    // Build per-tab KPI sets. Each entry is { label, value, delta, tone, spark }.
    let items = [];
    if (state.tab === 'sales') {
        const sum = (arr) => arr.reduce((a, b) => a + b, 0);
        const revenue = sum(s.revenue);
        const orders  = sum(s.orders);
        const aov     = orders ? revenue / orders : 0;
        const itemsSold = sum(s.orders.map((o, i) => o * 1.8)); // synthetic items/order ratio
        items = [
            { label: 'REVENUE',       value: fmtCurrency(revenue),   delta: prev != null ? delta(revenue, revenue * prev) : null, tone: 'lime',  spark: s.revenue.map((v, i, a) => v / Math.max(...a, 1)) },
            { label: 'ORDERS',        value: fmtNum(orders),          delta: prev != null ? delta(orders, orders * prev) : null,     tone: 'olive', spark: s.orders.map((v, i, a) => v / Math.max(...a, 1)) },
            { label: 'AVG ORDER',     value: fmtCurrency(aov),        delta: prev != null ? delta(aov, aov * prev) : null,           tone: 'olive', spark: s.revenue.map((v, i, a) => v / Math.max(...a, 1)) },
            { label: 'ITEMS SOLD',    value: fmtNum(itemsSold),       delta: prev != null ? delta(itemsSold, itemsSold * prev) : null, tone: 'olive', spark: s.orders.map((v, i, a) => v / Math.max(...a, 1)) },
        ];
    } else if (state.tab === 'profit') {
        const sum = (arr) => arr.reduce((a, b) => a + b, 0);
        const revenue = sum(s.revenue);
        const profit  = sum(s.profit);
        const cog     = sum(s.cog);
        const ship    = sum(s.ship);
        const margin  = revenue ? (profit / revenue) * 100 : 0;
        items = [
            { label: 'NET PROFIT',    value: fmtCurrency(profit),  delta: prev != null ? delta(profit, profit * prev) : null, tone: 'lime',  spark: s.profit.map((v, i, a) => v / Math.max(...a, 1)) },
            { label: 'MARGIN %',      value: `${margin.toFixed(1)}%`, delta: prev != null ? delta(margin, margin * 0.95) : null, tone: 'olive', spark: s.profit.map((v, i, a) => v / Math.max(...a, 1)) },
            { label: 'COST OF GOODS', value: fmtCurrency(cog),     delta: prev != null ? delta(cog, cog * prev) : null,     tone: 'amber', spark: s.cog.map((v, i, a) => v / Math.max(...a, 1)) },
            { label: 'SHIPPING COST', value: fmtCurrency(ship),    delta: prev != null ? delta(ship, ship * prev) : null,   tone: 'amber', spark: s.ship.map((v, i, a) => v / Math.max(...a, 1)) },
        ];
    } else if (state.tab === 'waste') {
        const sum = (arr) => arr.reduce((a, b) => a + b, 0);
        const returned = sum(s.returns);
        const waste    = sum(s.waste);
        const totalOrders = sum(s.orders) || 1;
        const rate = (returned / totalOrders) * 100;
        items = [
            { label: 'RETURNED ORDERS',  value: fmtNum(returned),                   delta: prev != null ? delta(returned, returned * 1.1) : null, tone: 'amber', spark: s.returns.map((v, i, a) => v / Math.max(...a, 1)) },
            { label: 'WASTE VALUE',      value: fmtCurrency(waste),                 delta: prev != null ? delta(waste, waste * 0.9) : null,        tone: 'coral', spark: s.waste.map((v, i, a) => v / Math.max(...a, 1)) },
            { label: 'RETURN RATE %',    value: `${rate.toFixed(1)}%`,               delta: prev != null ? delta(rate, rate * 0.95) : null,         tone: 'coral', spark: s.returns.map((v, i, a) => v / Math.max(...a, 1)) },
            { label: 'TOP RETURN REASON',value: REPORTS_DATA.topReturnReason.name,   delta: null,                                                       tone: 'amber', spark: [.2, .3, .25, .5, .6, .7, .85] },
        ];
    } else {
        // Top products tab — show a neutral set so the page is never
        // empty before the user navigates into the table.
        const sum = (arr) => arr.reduce((a, b) => a + b, 0);
        const revenue = sum(s.revenue);
        const profit  = sum(s.profit);
        const units   = REPORTS_DATA.topProducts.reduce((a, p) => a + p.units, 0);
        const avgRet  = REPORTS_DATA.topProducts.reduce((a, p) => a + p.ret, 0) / REPORTS_DATA.topProducts.length;
        items = [
            { label: 'TOP UNITS',     value: fmtNum(units),     delta: prev != null ? delta(units, units * prev) : null, tone: 'lime',  spark: s.orders.map((v, i, a) => v / Math.max(...a, 1)) },
            { label: 'TOP REVENUE',   value: fmtCurrency(revenue), delta: prev != null ? delta(revenue, revenue * prev) : null, tone: 'olive', spark: s.revenue.map((v, i, a) => v / Math.max(...a, 1)) },
            { label: 'TOP PROFIT',    value: fmtCurrency(profit),  delta: prev != null ? delta(profit, profit * prev) : null, tone: 'lime',  spark: s.profit.map((v, i, a) => v / Math.max(...a, 1)) },
            { label: 'AVG RETURN RATE', value: `${avgRet.toFixed(1)}%`, delta: null, tone: avgRet > 6 ? 'coral' : 'amber', spark: [.2, .25, .3, .28, .35, .4, .45] },
        ];
    }

    for (const it of items) {
        wrap.appendChild(buildKpi(it.label, it.value, it.delta, it.tone, it.spark));
    }
}

// ─── Main chart (SVG) ─────────────────────────────────────────

const CHART_W = 800;
const CHART_H = 240;
const PAD = { l: 38, r: 12, t: 18, b: 28 };

function svgNS(tag, attrs = {}) {
    const node = document.createElementNS('http://www.w3.org/2000/svg', tag);
    for (const [k, v] of Object.entries(attrs)) {
        if (v == null || v === false) continue;
        node.setAttribute(k, v);
    }
    return node;
}

function clearChart(host) {
    host.replaceChildren();
}

function attachTooltip(host) {
    let tip = $('.abo-admin-reports__chart-tooltip', host.parentElement);
    if (!tip) {
        tip = el('div', { class: 'abo-admin-reports__chart-tooltip', hidden: 'hidden' });
        host.parentElement.appendChild(tip);
    }
    return tip;
}

function showTip(tip, x, y, html) {
    const host = tip.parentElement.querySelector('[data-abo-admin-reports-main-chart]');
    const rect = host.getBoundingClientRect();
    const parentRect = host.parentElement.getBoundingClientRect();
    tip.style.left = (x - parentRect.left) + 'px';
    tip.style.top  = (y - parentRect.top)  + 'px';
    tip.innerHTML = html;
    tip.hidden = false;
}
function hideTip(tip) { tip.hidden = true; }

function renderSalesChart() {
    const host = $('[data-abo-admin-reports-main-chart]');
    if (!host) return;
    clearChart(host);

    const s = scaledSeries();
    const tip = attachTooltip(host);

    const svg = svgNS('svg', { viewBox: `0 0 ${CHART_W} ${CHART_H}`, preserveAspectRatio: 'none', role: 'img' });
    svg.setAttribute('aria-label', 'Bar chart of revenue per day for the selected period.');

    const maxRev = Math.max(...s.revenue, 1);
    const maxOrd = Math.max(...s.orders, 1);
    const innerW = CHART_W - PAD.l - PAD.r;
    const innerH = CHART_H - PAD.t - PAD.b;
    const n = s.revenue.length;
    const groupW = innerW / n;
    const barW = Math.max(8, groupW * 0.55);

    // Y grid lines (3).
    for (let g = 0; g <= 3; g++) {
        const y = PAD.t + (innerH * g) / 3;
        svg.appendChild(svgNS('line', {
            class: 'abo-admin-reports__grid',
            x1: PAD.l, x2: CHART_W - PAD.r, y1: y, y2: y,
        }));
    }

    // Bars + line.
    const linePts = [];
    s.revenue.forEach((rev, i) => {
        const cx = PAD.l + i * groupW + groupW / 2;
        const h = (rev / maxRev) * innerH;
        const y = PAD.t + innerH - h;
        const x = cx - barW / 2;

        const rect = svgNS('rect', {
            class: 'abo-admin-reports__bar',
            x: x.toFixed(2),
            y: y.toFixed(2),
            width: barW.toFixed(2),
            height: h.toFixed(2),
            rx: 1,
            'data-day': s.days[i],
            'data-value': fmtCurrency(rev),
        });
        rect.addEventListener('mouseenter', (ev) => {
            showTip(tip, ev.clientX, ev.clientY, `<strong>${s.days[i]}</strong> · ${fmtCurrency(rev)}`);
        });
        rect.addEventListener('mousemove', (ev) => {
            showTip(tip, ev.clientX, ev.clientY, `<strong>${s.days[i]}</strong> · ${fmtCurrency(rev)}`);
        });
        rect.addEventListener('mouseleave', () => hideTip(tip));
        rect.addEventListener('focus', (ev) => {
            showTip(tip, ev.clientX, ev.clientY, `<strong>${s.days[i]}</strong> · ${fmtCurrency(rev)}`);
        });
        rect.addEventListener('blur', () => hideTip(tip));
        rect.setAttribute('tabindex', '0');
        svg.appendChild(rect);

        // Value label above the bar (short version).
        const shortVal = rev >= 1000 ? `${(rev / 1000).toFixed(1)}k` : `${Math.round(rev)}`;
        const tlabel = svgNS('text', {
            class: 'abo-admin-reports__value-label',
            x: cx.toFixed(2),
            y: Math.max(y - 4, PAD.t + 9).toFixed(2),
            'text-anchor': 'middle',
        });
        tlabel.textContent = shortVal;
        svg.appendChild(tlabel);

        // X label.
        const txlabel = svgNS('text', {
            class: 'abo-admin-reports__axis-label',
            x: cx.toFixed(2),
            y: (CHART_H - 8).toFixed(2),
            'text-anchor': 'middle',
        });
        txlabel.textContent = s.days[i];
        svg.appendChild(txlabel);

        // Map orders onto a second Y axis on the same height range.
        const oy = PAD.t + innerH - (s.orders[i] / maxOrd) * innerH;
        linePts.push([cx, oy]);
    });

    // Polyline.
    const poly = svgNS('polyline', {
        class: 'abo-admin-reports__line',
        points: linePts.map((p) => `${p[0].toFixed(2)},${p[1].toFixed(2)}`).join(' '),
    });
    svg.appendChild(poly);

    // Dots.
    for (const p of linePts) {
        svg.appendChild(svgNS('circle', {
            class: 'abo-admin-reports__line-dot',
            cx: p[0].toFixed(2),
            cy: p[1].toFixed(2),
            r: 2.5,
        }));
    }

    host.appendChild(svg);
}

function renderProfitChart() {
    const host = $('[data-abo-admin-reports-main-chart]');
    if (!host) return;
    clearChart(host);

    const s = scaledSeries();
    const tip = attachTooltip(host);

    const svg = svgNS('svg', { viewBox: `0 0 ${CHART_W} ${CHART_H}`, preserveAspectRatio: 'none', role: 'img' });
    svg.setAttribute('aria-label', 'Bar chart of sales vs profit per day for the selected period.');

    const max = Math.max(...s.revenue, ...s.profit, 1);
    const innerW = CHART_W - PAD.l - PAD.r;
    const innerH = CHART_H - PAD.t - PAD.b;
    const n = s.revenue.length;
    const groupW = innerW / n;
    const barW = Math.max(10, groupW * 0.42);
    const thinW = 4;

    for (let g = 0; g <= 3; g++) {
        const y = PAD.t + (innerH * g) / 3;
        svg.appendChild(svgNS('line', {
            class: 'abo-admin-reports__grid',
            x1: PAD.l, x2: CHART_W - PAD.r, y1: y, y2: y,
        }));
    }

    s.revenue.forEach((rev, i) => {
        const cx = PAD.l + i * groupW + groupW / 2;

        // Sales bar.
        const h1 = (rev / max) * innerH;
        const y1 = PAD.t + innerH - h1;
        const r1 = svgNS('rect', {
            class: 'abo-admin-reports__bar',
            x: (cx - barW - 2).toFixed(2),
            y: y1.toFixed(2),
            width: barW.toFixed(2),
            height: h1.toFixed(2),
            rx: 1,
            tabindex: '0',
        });
        r1.addEventListener('mouseenter', (ev) => showTip(tip, ev.clientX, ev.clientY, `<strong>${s.days[i]}</strong> · sales ${fmtCurrency(rev)}`));
        r1.addEventListener('mousemove',  (ev) => showTip(tip, ev.clientX, ev.clientY, `<strong>${s.days[i]}</strong> · sales ${fmtCurrency(rev)}`));
        r1.addEventListener('mouseleave', () => hideTip(tip));
        svg.appendChild(r1);

        // Profit (thin white bar).
        const profit = s.profit[i];
        const h2 = (profit / max) * innerH;
        const y2 = PAD.t + innerH - h2;
        const r2 = svgNS('rect', {
            class: 'abo-admin-reports__bar abo-admin-reports__bar--profit',
            x: (cx + 2).toFixed(2),
            y: y2.toFixed(2),
            width: thinW.toFixed(2),
            height: h2.toFixed(2),
            rx: 1,
            tabindex: '0',
        });
        r2.addEventListener('mouseenter', (ev) => showTip(tip, ev.clientX, ev.clientY, `<strong>${s.days[i]}</strong> · profit ${fmtCurrency(profit)}`));
        r2.addEventListener('mousemove',  (ev) => showTip(tip, ev.clientX, ev.clientY, `<strong>${s.days[i]}</strong> · profit ${fmtCurrency(profit)}`));
        r2.addEventListener('mouseleave', () => hideTip(tip));
        svg.appendChild(r2);

        // Day label.
        const tx = svgNS('text', {
            class: 'abo-admin-reports__axis-label',
            x: cx.toFixed(2),
            y: (CHART_H - 8).toFixed(2),
            'text-anchor': 'middle',
        });
        tx.textContent = s.days[i];
        svg.appendChild(tx);
    });

    host.appendChild(svg);
}

function renderWasteChart() {
    const host = $('[data-abo-admin-reports-main-chart]');
    if (!host) return;
    clearChart(host);

    const s = scaledSeries();
    const tip = attachTooltip(host);

    const svg = svgNS('svg', { viewBox: `0 0 ${CHART_W} ${CHART_H}`, preserveAspectRatio: 'none', role: 'img' });
    svg.setAttribute('aria-label', 'Stacked bar chart of returns by reason per day for the selected period.');

    const reasons = REPORTS_DATA.reasons;
    const stacks = REPORTS_DATA.reasonsByDay;
    // Max total per day.
    const maxStack = Math.max(...stacks.map((row) => reasons.reduce((a, r) => a + (row[r.key] || 0), 0)), 1);
    const innerW = CHART_W - PAD.l - PAD.r;
    const innerH = CHART_H - PAD.t - PAD.b;
    const n = s.days.length;
    const groupW = innerW / n;
    const barW = Math.max(14, groupW * 0.5);

    for (let g = 0; g <= 3; g++) {
        const y = PAD.t + (innerH * g) / 3;
        svg.appendChild(svgNS('line', {
            class: 'abo-admin-reports__grid',
            x1: PAD.l, x2: CHART_W - PAD.r, y1: y, y2: y,
        }));
    }

    stacks.forEach((row, i) => {
        const cx = PAD.l + i * groupW + groupW / 2;
        const total = reasons.reduce((a, r) => a + (row[r.key] || 0), 0);
        const h = (total / maxStack) * innerH;
        const x = cx - barW / 2;
        let yCursor = PAD.t + innerH;

        reasons.forEach((r) => {
            const v = row[r.key] || 0;
            if (!v) return;
            const segH = (v / maxStack) * innerH;
            yCursor -= segH;
            const colorClass = {
                coral: 'abo-admin-reports__bar--coral',
                amber: 'abo-admin-reports__bar--amber',
                dim:   'abo-admin-reports__bar--dim',
                stone: 'abo-admin-reports__bar--stone',
            }[r.color] || 'abo-admin-reports__bar--coral';

            const seg = svgNS('rect', {
                class: `abo-admin-reports__bar ${colorClass}`,
                x: x.toFixed(2),
                y: yCursor.toFixed(2),
                width: barW.toFixed(2),
                height: segH.toFixed(2),
                tabindex: '0',
            });
            seg.addEventListener('mouseenter', (ev) => {
                showTip(tip, ev.clientX, ev.clientY, `<strong>${s.days[i]}</strong> · ${r.label}: ${v}`);
            });
            seg.addEventListener('mousemove',  (ev) => {
                showTip(tip, ev.clientX, ev.clientY, `<strong>${s.days[i]}</strong> · ${r.label}: ${v}`);
            });
            seg.addEventListener('mouseleave', () => hideTip(tip));
            svg.appendChild(seg);
        });

        // Total label.
        if (total > 0) {
            const tlabel = svgNS('text', {
                class: 'abo-admin-reports__value-label',
                x: cx.toFixed(2),
                y: Math.max(yCursor - 4, PAD.t + 9).toFixed(2),
                'text-anchor': 'middle',
            });
            tlabel.textContent = String(total);
            svg.appendChild(tlabel);
        }

        const tx = svgNS('text', {
            class: 'abo-admin-reports__axis-label',
            x: cx.toFixed(2),
            y: (CHART_H - 8).toFixed(2),
            'text-anchor': 'middle',
        });
        tx.textContent = s.days[i];
        svg.appendChild(tx);
    });

    host.appendChild(svg);
}

function renderMainChart() {
    const titleEl = $('[data-abo-admin-reports-main-title]');
    const legendEl = $('[data-abo-admin-reports-main-legend]');
    if (titleEl) {
        if (state.tab === 'sales') titleEl.textContent = 'REVENUE BY DAY';
        else if (state.tab === 'profit') titleEl.textContent = 'SALES VS PROFIT';
        else if (state.tab === 'waste') titleEl.textContent = 'RETURNS BY REASON';
        else titleEl.textContent = 'TOP PRODUCTS OVERVIEW';
    }
    if (legendEl) {
        legendEl.replaceChildren();
        if (state.tab === 'sales') {
            legendEl.appendChild(el('li', {}, [el('span', { class: 'abo-admin-chart__swatch abo-admin-reports__swatch--lime' }), 'Revenue']));
            legendEl.appendChild(el('li', {}, [el('span', { class: 'abo-admin-reports__swatch--line' }), 'Orders']));
        } else if (state.tab === 'profit') {
            legendEl.appendChild(el('li', {}, [el('span', { class: 'abo-admin-chart__swatch abo-admin-reports__swatch--lime' }), 'Sales']));
            legendEl.appendChild(el('li', {}, [el('span', { class: 'abo-admin-chart__swatch abo-admin-chart__swatch--profit' }), 'Profit']));
        } else if (state.tab === 'waste') {
            for (const r of REPORTS_DATA.reasons) {
                const cls = `abo-admin-reports__swatch--${r.color}`;
                legendEl.appendChild(el('li', {}, [el('span', { class: `abo-admin-chart__swatch ${cls}` }), r.label]));
            }
        }
    }

    if (state.tab === 'sales') renderSalesChart();
    else if (state.tab === 'profit') renderProfitChart();
    else if (state.tab === 'waste') renderWasteChart();
    // 'top' tab — the main chart section is hidden by setTab().
}

// ─── Second row: bar list + status bar ────────────────────────

function renderBarsList(host, items, opts = {}) {
    host.replaceChildren();
    if (!items.length) {
        host.appendChild(el('p', { class: 'abo-admin-empty' }, 'No data for this period.'));
        return;
    }
    const max = Math.max(...items.map((it) => it.share || it.value || 0), 1);
    for (const it of items) {
        const pct = ((it.share || it.value || 0) / max) * 100;
        const fillClass = opts.fillClass || '';
        const row = el('div', { class: 'abo-admin-reports__bar-row' });
        row.appendChild(el('span', { class: 'abo-admin-reports__bar-name', text: it.name }));
        const track = el('div', { class: 'abo-admin-reports__bar-track' });
        track.appendChild(el('span', { class: `abo-admin-reports__bar-fill ${fillClass}`, style: `--w: ${pct.toFixed(1)}%` }));
        row.appendChild(track);
        const val = (it.share != null) ? `${(it.share)}%` : (it.value != null ? fmtNum(it.value) : '—');
        row.appendChild(el('span', { class: 'abo-admin-reports__bar-value', text: val }));
        host.appendChild(row);
    }
}

function renderStatusBar(segments) {
    const bar = $('[data-abo-admin-reports-segbar]');
    const legend = $('[data-abo-admin-reports-seglegend]');
    if (!bar || !legend) return;
    bar.replaceChildren();
    legend.replaceChildren();

    const total = Math.max(segments.reduce((a, s) => a + s.count, 0), 1);
    for (const seg of segments) {
        const span = el('span', {
            class: `abo-admin-status__seg abo-admin-status__seg--${seg.hue}`,
            style: `--seg: ${(seg.count / total) * 100}%`,
            title: `${seg.label}: ${seg.count}`,
        });
        bar.appendChild(span);

        const li = el('li', {}, [
            el('span', { class: `abo-admin-status__dot abo-admin-status__dot--${seg.hue}`, 'aria-hidden': 'true' }),
            el('span', { class: 'abo-admin-status__name', text: seg.label }),
            el('span', { class: 'abo-admin-status__count', text: String(seg.count) }),
        ]);
        legend.appendChild(li);
    }
}

function renderReasonsList() {
    const host = $('[data-abo-admin-reports-breakdown1]');
    if (!host) return;
    host.replaceChildren();
    const ul = el('ul', { class: 'abo-admin-reports__reasons' });
    const total = REPORTS_DATA.reasonsByDay.reduce(
        (a, row) => a + REPORTS_DATA.reasons.reduce((b, r) => b + (row[r.key] || 0), 0),
        0,
    );
    if (!total) {
        host.appendChild(el('p', { class: 'abo-admin-empty' }, 'No data for this period.'));
        return;
    }
    for (const r of REPORTS_DATA.reasons) {
        const count = REPORTS_DATA.reasonsByDay.reduce((a, row) => a + (row[r.key] || 0), 0);
        const cls = `abo-admin-reports__reasons-dot abo-admin-reports__bar--${r.color === 'dim' ? 'dim' : r.color === 'stone' ? 'stone' : r.color}`;
        ul.appendChild(el('li', {}, [
            el('span', { class: cls }),
            el('span', { class: 'abo-admin-reports__reasons-name', text: r.label }),
            el('span', { class: 'abo-admin-reports__reasons-count', text: String(count) }),
        ]));
    }
    host.appendChild(ul);
}

function renderBreakdown() {
    const t1 = $('[data-abo-admin-reports-breakdown1-title]');
    const s1 = $('[data-abo-admin-reports-breakdown1-soft]');
    const t2 = $('[data-abo-admin-reports-breakdown2-title]');
    const s2 = $('[data-abo-admin-reports-breakdown2-soft]');

    const host1 = $('[data-abo-admin-reports-breakdown1]');
    const host2 = $('[data-abo-admin-reports-breakdown2]');

    if (state.tab === 'sales') {
        if (t1) t1.textContent = 'SALES BY GOVERNORATE';
        if (s1) s1.textContent = 'Top 8';
        if (t2) t2.textContent = 'ORDERS BY STATUS';
        if (s2) s2.textContent = `${REPORTS_DATA.statusSegments.reduce((a, s) => a + s.count, 0)} orders`;
        renderBarsList(host1, REPORTS_DATA.governorates, { fillClass: '' });
        renderStatusBar(REPORTS_DATA.statusSegments);
    } else if (state.tab === 'profit') {
        if (t1) t1.textContent = 'PROFIT BY CATEGORY';
        if (s1) s1.textContent = 'Top categories';
        if (t2) t2.textContent = 'COST BREAKDOWN';
        if (s2) s2.textContent = `${REPORTS_DATA.costBreakdown.reduce((a, s) => a + s.count, 0)} EGP`;
        renderBarsList(host1, REPORTS_DATA.categories, { fillClass: '' });
        renderStatusBar(REPORTS_DATA.costBreakdown);
    } else if (state.tab === 'waste') {
        if (t1) t1.textContent = 'WASTE BY PRODUCT';
        if (s1) s1.textContent = `${REPORTS_DATA.topProducts.length} items`;
        if (t2) t2.textContent = 'REASONS';
        if (s2) s2.textContent = 'All returns';
        // For waste, breakdown 1 becomes a simple list, breakdown 2 a count list.
        renderBarsList(host1, REPORTS_DATA.topProducts.slice(0, 6).map((p) => ({ name: p.name, value: Math.round(p.units * (p.ret / 100)) })), { fillClass: 'abo-admin-reports__bar-fill--coral' });
        renderReasonsList();
    } else {
        // 'top' tab — the breakdown section is hidden by setTab(),
        // so this branch is a no-op safety net.
    }
}

// ─── Top products (sort + search + filter) ────────────────────

function sortKey(p, key) {
    switch (key) {
        case 'rank':   return REPORTS_DATA.topProducts.indexOf(p) + 1;
        case 'name':   return p.name;
        case 'units':  return p.units;
        case 'rev':    return p.rev;
        case 'profit': return p.profit;
        case 'ret':    return p.ret;
        default:       return 0;
    }
}

function renderTopProducts() {
    const body = $('[data-abo-admin-reports-top-body]');
    if (!body) return;
    body.replaceChildren();

    const q = state.topFilter.q.trim().toLowerCase();
    const cat = state.topFilter.cat;
    let rows = REPORTS_DATA.topProducts.filter((p) => {
        if (cat && p.cat !== cat) return false;
        if (q && !(p.name.toLowerCase().includes(q) || p.cat.toLowerCase().includes(q))) return false;
        return true;
    });

    const { key, dir } = state.topSort;
    rows.sort((a, b) => {
        const av = sortKey(a, key);
        const bv = sortKey(b, key);
        if (av < bv) return dir === 'asc' ? -1 : 1;
        if (av > bv) return dir === 'asc' ? 1 : -1;
        return 0;
    });

    if (!rows.length) {
        const tr = el('tr', { class: 'abo-admin-reports__row-empty' });
        tr.appendChild(el('td', { colspan: '6', text: 'No products match the current filter.' }));
        body.appendChild(tr);
        return;
    }

    const maxRet = Math.max(...REPORTS_DATA.topProducts.map((p) => p.ret), 1);
    rows.forEach((p, i) => {
        const tr = el('tr', { 'data-abo-admin-reports-row': p.id });
        tr.appendChild(el('td', { text: String(i + 1) }));

        const productCell = el('td', {});
        const wrap = el('span', { class: 'abo-admin-reports__product-cell' });
        wrap.appendChild(el('span', { class: 'abo-admin-reports__thumb', text: p.name.slice(0, 2).toUpperCase() }));
        const text = el('span', { class: 'abo-admin-reports__product-text' });
        text.appendChild(el('span', { class: 'abo-admin-reports__product-name', text: p.name }));
        text.appendChild(el('span', { class: 'abo-admin-reports__product-cat', text: p.cat }));
        wrap.appendChild(text);
        productCell.appendChild(wrap);
        tr.appendChild(productCell);

        tr.appendChild(el('td', { class: 'abo-admin-reports__num', text: fmtNum(p.units) }));
        tr.appendChild(el('td', { class: 'abo-admin-reports__num', text: fmtCurrency(p.rev) }));
        tr.appendChild(el('td', { class: 'abo-admin-reports__num', text: fmtCurrency(p.profit) }));

        const retTd = el('td', { class: 'abo-admin-reports__num' });
        const retCell = el('span', { class: 'abo-admin-reports__return-cell' });
        const fillCls = p.ret < 4 ? 'abo-admin-reports__return-cell-fill--low' : (p.ret < 7 ? 'abo-admin-reports__return-cell-fill--mid' : '');
        retCell.appendChild(el('span', { class: 'abo-admin-reports__return-cell-value', text: `${p.ret.toFixed(1)}%` }));
        const bar = el('span', { class: 'abo-admin-reports__return-cell-bar', 'aria-hidden': 'true' });
        bar.appendChild(el('span', { class: `abo-admin-reports__return-cell-fill ${fillCls}`, style: `--w: ${((p.ret / maxRet) * 100).toFixed(1)}%` }));
        retCell.appendChild(bar);
        retTd.appendChild(retCell);
        tr.appendChild(retTd);

        body.appendChild(tr);
    });
}

function populateCategoryFilter() {
    const sel = $('[data-abo-admin-reports-top-category]');
    if (!sel) return;
    const cats = Array.from(new Set(REPORTS_DATA.topProducts.map((p) => p.cat))).sort();
    // Keep the first "All categories" option.
    sel.replaceChildren(el('option', { value: '' }, 'All categories'));
    for (const c of cats) sel.appendChild(el('option', { value: c }, c));
}

function initTopSort() {
    const ths = $$('th[data-sort]', $('[data-abo-admin-reports-top-table]'));
    for (const th of ths) {
        th.addEventListener('click', () => {
            const key = th.dataset.sort;
            if (state.topSort.key === key) {
                state.topSort.dir = state.topSort.dir === 'asc' ? 'desc' : 'asc';
            } else {
                state.topSort.key = key;
                state.topSort.dir = (key === 'name') ? 'asc' : 'desc';
            }
            for (const t of ths) {
                t.setAttribute('aria-sort', t === th ? (state.topSort.dir === 'asc' ? 'ascending' : 'descending') : 'none');
            }
            renderTopProducts();
        });
    }
}

function initTopSearch() {
    const input = $('[data-abo-admin-reports-top-search]');
    if (!input) return;
    input.addEventListener('input', () => {
        state.topFilter.q = input.value || '';
        renderTopProducts();
    });
}

function initTopCategory() {
    const sel = $('[data-abo-admin-reports-top-category]');
    if (!sel) return;
    sel.addEventListener('change', () => {
        state.topFilter.cat = sel.value || '';
        renderTopProducts();
    });
}

// ─── Tab switching ────────────────────────────────────────────

function setTab(tab, opts = {}) {
    if (!['sales', 'profit', 'waste', 'top'].includes(tab)) tab = 'sales';
    state.tab = tab;
    for (const btn of $$('[data-abo-admin-reports-tabs] button')) {
        const isActive = btn.dataset.tab === tab;
        btn.classList.toggle('is-active', isActive);
        btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
        btn.setAttribute('tabindex', isActive ? '0' : '-1');
    }
    // KPIs, main chart and breakdown are replaced by the Top products
    // table on the Top products tab (per the spec — section 6 is the
    // whole top-products experience, no extra rows around it).
    const isTop = tab === 'top';
    const kpiSec  = $('[data-abo-admin-reports-kpis]');
    const mainSec = $('[data-abo-admin-reports-main]');
    const bdSec   = $('[data-abo-admin-reports-breakdown]');
    if (kpiSec)  kpiSec.hidden  = isTop;
    if (mainSec) mainSec.hidden = isTop;
    if (bdSec)   bdSec.hidden   = isTop;
    const topSection = $('[data-abo-admin-reports-top]');
    if (topSection) topSection.hidden = !isTop;
    if (!isTop) {
        renderKpis();
        renderMainChart();
        renderBreakdown();
    } else {
        renderTopProducts();
    }
    if (!opts.skipHash) syncHash();
}

function syncHash() {
    const next = `#${state.tab}`;
    if (window.location.hash !== next) {
        // Avoid pushing history entries for every redraw; use replaceState
        // when the hash already differs only in the active tab.
        history.replaceState(null, '', next);
    }
}

function initTabs() {
    const wrap = $('[data-abo-admin-reports-tabs]');
    if (!wrap) return;
    const btns = $$('button[data-tab]', wrap);

    for (const btn of btns) {
        btn.addEventListener('click', () => setTab(btn.dataset.tab));
        btn.addEventListener('keydown', (ev) => {
            const idx = btns.indexOf(btn);
            if (ev.key === 'ArrowRight' || ev.key === 'ArrowDown') {
                ev.preventDefault();
                const next = btns[(idx + 1) % btns.length];
                next.focus();
                setTab(next.dataset.tab);
            } else if (ev.key === 'ArrowLeft' || ev.key === 'ArrowUp') {
                ev.preventDefault();
                const next = btns[(idx - 1 + btns.length) % btns.length];
                next.focus();
                setTab(next.dataset.tab);
            } else if (ev.key === 'Home') {
                ev.preventDefault();
                btns[0].focus();
                setTab(btns[0].dataset.tab);
            } else if (ev.key === 'End') {
                ev.preventDefault();
                btns[btns.length - 1].focus();
                setTab(btns[btns.length - 1].dataset.tab);
            }
        });
    }
}

function readHash() {
    const h = (window.location.hash || '').replace(/^#/, '');
    if (['sales', 'profit', 'waste', 'top'].includes(h)) return h;
    return 'sales';
}

// ─── Date range ───────────────────────────────────────────────

function setRange(range) {
    state.range = range;
    const wrap = $('[data-abo-admin-reports-range]');
    if (wrap) {
        for (const b of $$('button[data-range]', wrap)) {
            const isActive = b.dataset.range === range;
            b.classList.toggle('is-active', isActive);
            b.setAttribute('aria-selected', isActive ? 'true' : 'false');
        }
    }
    const custom = $('[data-abo-admin-reports-custom]');
    if (custom) custom.hidden = range !== 'custom';
    renderKpis();
    renderMainChart();
    renderBreakdown();
}

function initRange() {
    const wrap = $('[data-abo-admin-reports-range]');
    if (!wrap) return;
    for (const b of $$('button[data-range]', wrap)) {
        b.addEventListener('click', () => setRange(b.dataset.range || '7d'));
    }

    const from = $('[data-abo-admin-reports-from]');
    const to   = $('[data-abo-admin-reports-to]');
    const onChange = () => {
        if (from) state.custom.from = from.value || null;
        if (to)   state.custom.to   = to.value   || null;
        if (state.range === 'custom') {
            renderKpis();
            renderMainChart();
            renderBreakdown();
        }
    };
    if (from) from.addEventListener('change', onChange);
    if (to)   to.addEventListener('change',   onChange);

    const compare = $('[data-abo-admin-reports-compare]');
    if (compare) {
        compare.addEventListener('change', () => {
            state.compare = !!compare.checked;
            renderKpis();
        });
    }
}

// ─── Export buttons ───────────────────────────────────────────

function initExport() {
    for (const btn of $$('[data-abo-admin-reports-export]')) {
        btn.addEventListener('click', () => {
            const kind = (btn.dataset.aboAdminReportsExport || 'csv').toUpperCase();
            showToast(`Export ${kind} started (demo)`);
        });
    }
}

// ─── Boot ─────────────────────────────────────────────────────

function init() {
    setGenerated();
    populateCategoryFilter();
    initTopSort();
    initTopSearch();
    initTopCategory();
    initTabs();
    initRange();
    initExport();

    const initial = readHash();
    setTab(initial, { skipHash: true });
    window.addEventListener('hashchange', () => setTab(readHash(), { skipHash: true }));
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
