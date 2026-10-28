{{--
    The stylesheet the two printable documents share.

    These pages deliberately do NOT extend the admin layout. A receipt
    is handed to a customer and a label is handed to a courier — neither
    should carry the back office's sidebar, its bell or the name of the
    admin who happened to press the button.

    The paper is the one the receipt settings ask for:
      - `a4`     an A4 sheet, `@page` sized to it
      - `thermal` a narrow roll, laid out for an 80mm printer

    Nothing here is coloured: a receipt is printed on white paper, and
    a print stylesheet carrying the site's dark palette produces a sheet
    that eats toner and reads badly.

    @param string $paper  'a4' | 'thermal'
--}}
@php
    $isThermal = ($paper ?? 'a4') === 'thermal';
@endphp
<style>
    * { box-sizing: border-box; }

    :root {
        --ink: #111111;
        --muted: #6b6b6b;
        --rule: #d8d8d8;
        --paper-width: {{ $isThermal ? '80mm' : '210mm' }};
        --paper-pad: {{ $isThermal ? '4mm' : '12mm' }};
    }

    @page {
        size: {{ $isThermal ? '80mm auto' : 'A4' }};
        margin: {{ $isThermal ? '4mm' : '12mm' }};
    }

    html, body {
        margin: 0;
        padding: 0;
        background: #f0f0f0;
        color: var(--ink);
        font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', sans-serif;
        font-size: 13px;
        line-height: 1.45;
    }

    .abo-print {
        width: var(--paper-width);
        max-width: 100%;
        margin: 24px auto;
        padding: var(--paper-pad);
        background: #ffffff;
        box-shadow: 0 2px 14px rgba(0, 0, 0, 0.12);
    }

    .abo-print__head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        padding-bottom: 10px;
        border-bottom: 2px solid var(--ink);
    }

    .abo-print__brand { min-width: 0; }

    .abo-print__logo {
        display: block;
        max-width: 140px;
        max-height: 56px;
        margin-bottom: 6px;
        object-fit: contain;
    }

    .abo-print__store {
        font-size: 17px;
        font-weight: 700;
        letter-spacing: 0.02em;
    }

    .abo-print__header-text {
        margin-top: 2px;
        color: var(--muted);
        font-size: 12px;
    }

    .abo-print__doc {
        flex: 0 0 auto;
        text-align: right;
    }

    .abo-print__doc-kind {
        font-size: 15px;
        font-weight: 700;
        letter-spacing: 0.14em;
        text-transform: uppercase;
    }

    .abo-print__doc-number {
        margin-top: 2px;
        font-size: 12px;
        color: var(--muted);
        font-variant-numeric: tabular-nums;
    }

    .abo-print__section { margin-top: 14px; }

    .abo-print__label {
        margin: 0 0 4px;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0.16em;
        text-transform: uppercase;
        color: var(--muted);
    }

    .abo-print__value { margin: 0; }

    .abo-print__grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px 18px;
    }

    .abo-print__table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 4px;
    }

    .abo-print__table th,
    .abo-print__table td {
        padding: 6px 4px;
        text-align: left;
        vertical-align: top;
        border-bottom: 1px solid var(--rule);
    }

    .abo-print__table th {
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: var(--muted);
        border-bottom-color: var(--ink);
    }

    .abo-print__table .is-numeric {
        text-align: right;
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
    }

    .abo-print__table th.is-numeric { text-align: right; }

    .abo-print__variant {
        color: var(--muted);
        font-size: 11px;
    }

    .abo-print__totals {
        margin-top: 12px;
        margin-left: auto;
        width: min(280px, 100%);
    }

    .abo-print__total-row {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        padding: 4px 0;
    }

    .abo-print__total-row span:last-child {
        font-variant-numeric: tabular-nums;
    }

    .abo-print__total-row--grand {
        margin-top: 4px;
        padding-top: 8px;
        border-top: 2px solid var(--ink);
        font-size: 15px;
        font-weight: 700;
    }

    .abo-print__foot {
        margin-top: 16px;
        padding-top: 10px;
        border-top: 1px solid var(--rule);
        color: var(--muted);
        font-size: 11px;
    }

    /* A small bar the operator can press if the browser refused to
       open the print dialog on its own. It never reaches the paper. */
    .abo-print__bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        width: var(--paper-width);
        max-width: 100%;
        margin: 24px auto 0;
    }

    .abo-print__bar-label {
        font-size: 12px;
        color: var(--muted);
    }

    .abo-print__bar button {
        padding: 8px 16px;
        border: 1px solid var(--ink);
        background: var(--ink);
        color: #ffffff;
        font: inherit;
        font-weight: 600;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        cursor: pointer;
    }

    /* ─── Labels sheet ────────────────────────────────────── */

    .abo-labels {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 6mm;
    }

    .abo-labels__label {
        border: 1px dashed var(--rule);
        padding: 6mm 5mm;
        break-inside: avoid;
    }

    .abo-labels__number {
        font-size: 15px;
        font-weight: 700;
        letter-spacing: 0.04em;
    }

    .abo-labels__customer {
        margin-top: 6px;
        font-size: 13px;
        font-weight: 600;
    }

    .abo-labels__line {
        margin-top: 2px;
        font-size: 12px;
    }

    .abo-labels__collect {
        margin-top: 8px;
        padding-top: 6px;
        border-top: 1px solid var(--rule);
        font-size: 14px;
        font-weight: 700;
        display: flex;
        justify-content: space-between;
        gap: 8px;
    }

    /* ─── Print ───────────────────────────────────────────── */

    @media print {
        html, body { background: #ffffff; }

        .abo-print {
            width: auto;
            margin: 0;
            padding: 0;
            box-shadow: none;
        }

        .abo-print__bar { display: none !important; }
    }

    @media print and (min-width: 100mm) {
        .abo-labels {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
</style>
