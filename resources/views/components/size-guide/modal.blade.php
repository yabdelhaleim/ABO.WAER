{{--
    Size Guide modal — reusable Blade component.

    Self-contained: renders BOTH the trigger button and the modal
    dialog. The caller positions the component where the trigger
    should sit (e.g. a flex row next to a "Size —" label).

    Props
    -----
    @param  array|null  $chart         Size chart data. Null/empty → empty state.
    @param  array|null  $product       The product array (we only need $product['category']).
    @param  string|null $id            Optional id; defaults to 'size-guide-<slug>'.

    Chart shape (data contract — single source of truth)
    ----------------------------------------------------
    [
        'unit'  => 'cm',
        'note'  => 'Measurements are in cm. If you are between sizes, choose the larger one.',
        'noteAr' => '',
        'howTo' => 'Lay the garment flat and measure the chest 1 cm below the armhole.',
        'howToAr' => '',
        'types' => [
            '<category-slug>' => [
                // The column names are the ones the admin gave the
                // measurements, and they drive the headers.
                'columns' => ['Chest', 'Length', 'Shoulder', 'Sleeve'],
                'rows'    => [
                    ['size' => 'S',  'measurements' => ['Chest' => 52, 'Length' => 71, 'Shoulder' => 45]],
                    ['size' => 'M',  'measurements' => [...]],
                ],
            ],
        ],
        'howToMeasure' => [
            ['label' => 'A · Chest',    'hint' => 'Measured flat, 1 cm below the armhole.'],
            ['label' => 'B · Length',   'hint' => 'From the highest point of the shoulder to the hem.'],
            ['label' => 'C · Shoulder', 'hint' => 'From one shoulder seam to the other, across the back.'],
            ['label' => 'D · Sleeve',   'hint' => 'From the shoulder seam to the end of the cuff.'],
        ],
    ]

    Rules (per the project spec)
    ----------------------------
    - No fake numbers: the empty state shows when there's no chart or
      no type for the product's category.
    - No cross-type fallback: if the product's category isn't a key in
      $chart['types'], we render the empty state — never another type.
    - Single source for labels: the column names drive the headers.
    - The admin's copy wins: the guide's note and how-to are printed
      when it has them; the generic guidance is only a fallback for a
      guide that was written without any.
    - Selected-size highlight is NOT done in Blade. The JS reads
      [data-product-size] from the page when the modal opens and adds
      .is-active to the matching row. With no attribute on the page
      (current case) no row is highlighted.
--}}

@props([
    'chart' => null,
    'product' => null,
    'id' => null,
])

@php
    $id = $id ?: 'size-guide-'.($product['slug'] ?? 'default');

    // The column names are the admin's own: a pair of pants is
    // measured by waist and hip, a hoodie by chest and sleeve. They
    // are printed as they are named.
    $hasChart = is_array($chart) && !empty($chart['types']);
    $productType = $product['category'] ?? null;
    $hasTypeForProduct = $hasChart && $productType && isset($chart['types'][$productType]);
    $showEmpty = !$hasTypeForProduct;
    $type = $hasTypeForProduct ? $chart['types'][$productType] : null;
    $unit = $chart['unit'] ?? 'cm';

    // The guide's own copy: the note under the title and the how-to
    // under the table. The Arabic copy is used when the English one
    // is empty, so a guide written in Arabic still reaches the shop.
    $note = trim((string) ($chart['note'] ?? ''));
    if ($note === '') {
        $note = trim((string) ($chart['noteAr'] ?? ''));
    }
    $noteIsArabic = $note !== '' && $note === trim((string) ($chart['noteAr'] ?? ''));

    $howTo = trim((string) ($chart['howTo'] ?? ''));
    if ($howTo === '') {
        $howTo = trim((string) ($chart['howToAr'] ?? ''));
    }
    $howToIsArabic = $howTo !== '' && $howTo === trim((string) ($chart['howToAr'] ?? ''));

    $howToMeasure = $chart['howToMeasure'] ?? [];
@endphp

<button
    type="button"
    class="size-guide__trigger"
    data-size-guide-open="{{ $id }}"
    aria-haspopup="dialog"
    aria-controls="{{ $id }}"
>
    <span>SIZE GUIDE</span>
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" class="size-guide__trigger-icon">
        <path d="M3 7h18M3 12h18M3 17h18" fill="none" stroke="currentColor"
              stroke-width="2" stroke-linecap="round"/>
    </svg>
</button>

<div
    id="{{ $id }}"
    class="size-guide"
    data-size-guide
    data-size-guide-modal
    data-product-category="{{ $productType }}"
    role="dialog"
    aria-modal="true"
    aria-labelledby="{{ $id }}-title"
    hidden
>
    <div class="size-guide__backdrop" data-size-guide-close aria-hidden="true"></div>

    <div class="size-guide__dialog" data-size-guide-dialog tabindex="-1">
        <header class="size-guide__head">
            <div class="size-guide__head-text">
                <p class="size-guide__eyebrow">
                    <span class="size-guide__eyebrow-line" aria-hidden="true"></span>
                    <span>SIZE CHART</span>
                </p>
                <h2 class="size-guide__title" id="{{ $id }}-title">
                    FIND YOUR <span class="size-guide__title-accent">FIT.</span>
                </h2>
                <p class="size-guide__subtitle" @if ($noteIsArabic) dir="rtl" @endif>
                    @if ($note !== '')
                        {{ $note }}
                    @else
                        Measurements are of the garment laid flat, in {{ $unit }}.
                    @endif
                </p>
            </div>
            <button
                type="button"
                class="size-guide__close"
                data-size-guide-close
                aria-label="Close size guide"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M6 6l12 12M6 18L18 6" fill="none" stroke="currentColor"
                          stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>
        </header>

        <div class="size-guide__body">
            @if ($showEmpty)
                <div class="size-guide__empty" role="status">
                    <p class="size-guide__empty-title">MEASUREMENTS COMING SOON.</p>
                    <p class="size-guide__empty-line">
                        The size chart for this product isn't published yet — drop us a line
                        and we'll send the exact measurements within 24h.
                    </p>
                </div>
            @else
                <div
                    class="size-guide__table-wrap"
                    role="region"
                    aria-label="Size chart, scrollable horizontally"
                    tabindex="0"
                >
                    <table class="size-guide__table">
                        <thead>
                            <tr>
                                <th scope="col" class="size-guide__col-size">Size</th>
                                @foreach ($type['columns'] as $col)
                                    <th scope="col" class="size-guide__col-measure">
                                        {{ $col }}
                                        <span class="size-guide__unit">({{ $unit }})</span>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($type['rows'] as $row)
                                <tr data-size-row="{{ $row['size'] }}">
                                    <th scope="row" class="size-guide__row-size">{{ $row['size'] }}</th>
                                    @foreach ($type['columns'] as $col)
                                        <td class="size-guide__row-measure">
                                            {{ $row['measurements'][$col] ?? '—' }}
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($howTo !== '' || !empty($howToMeasure))
                    <aside class="size-guide__howto" aria-label="How to measure">
                        <p class="size-guide__howto-title">HOW TO MEASURE</p>
                        @if ($howTo !== '')
                            <p class="size-guide__howto-text" @if ($howToIsArabic) dir="rtl" @endif>{{ $howTo }}</p>
                        @else
                            <ol class="size-guide__howto-list">
                                @foreach ($howToMeasure as $hint)
                                    <li>
                                        <strong>{{ $hint['label'] }}</strong>
                                        <span>{{ $hint['hint'] }}</span>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </aside>
                @endif
            @endif
        </div>
    </div>
</div>
