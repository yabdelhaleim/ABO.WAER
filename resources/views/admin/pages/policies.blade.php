{{--
    Admin · Policies (front-end only, static data).

    Extends the existing admin layout. No controller, no model.
    All three policies (Shipping, Returns, Exchange) live in a
    single static array at the top of this view, marked
    TODO(backend). The page-local script (public/admin/js/policies.js)
    hydrates the tabs, rich-text editor, lang switch, rules
    fields, SEO fields and live preview from that one array.
    Every section that would need real data is marked TODO(backend).

    Reuses the brand tokens (--abo-lime, --abo-bg,
    --abo-admin-panel/border/text/coral/amber/gray), the page-header
    / eyebrow / title classes, .abo-admin-card, .abo-admin-btn,
    .abo-admin-empty, .abo-admin-pill and .sr-only. The page-local
    stylesheet and script are loaded only on this page via
    @stack('head') and @stack('scripts'). All policies-specific
    classes are prefixed `abo-admin-policies-`.
--}}

@extends('admin.layouts.app')

@section('title', 'Policies · Content')
@section('description', 'ABO — admin policies editor: Shipping, Returns and Exchange with rules, SEO and live preview.')

@php
    // TODO(backend): replace the static array below with data fetched
    // server-side from the policies tables (titles, content, meta,
    // status, rules). The view shape (tabs + editor + rules + SEO +
    // preview) is final; only the data source changes.

    // Today's "now" — matches the orders / pages page so relative
    // times are stable across loads.
    $today = '2026-10-17';

    // Three system policies. Each has EN/AR title + content + meta,
    // a status + last-updated timestamp, plus tab-specific rules:
    //   shipping → delivery time + free-shipping threshold + fees toggle
    //   returns  → return window + accept-returns + conditions + who pays
    //   exchange → accept exchanges + window + same-product + price diff
    $policies = [
        'shipping' => [
            'label'  => 'Shipping Policy',
            'slug'   => 'shipping',
            'status' => 'published',
            'updated' => '2026-10-08T13:22:00',
            'title'  => ['en' => 'Shipping Policy', 'ar' => 'سياسة الشحن'],
            'content' => [
                'en' => '<h2>Delivery options</h2><p>We ship to every Egyptian governorate. All orders are dispatched from our Cairo studio within 1 business day.</p><ul><li><strong>Standard delivery</strong> — 2 to 4 business days, EGP 70.</li><li><strong>Express delivery</strong> — next business day, EGP 120.</li><li><strong>Same-day (Cairo only)</strong> — for orders placed before 14:00, EGP 180.</li></ul><h3>Cut-off times</h3><p>Orders placed after 14:00 on Friday are dispatched the following Sunday.</p>',
                'ar' => '<h2>خيارات التوصيل</h2><p>نشحن إلى جميع المحافظات المصرية. تُسلَّم جميع الطلبات من استوديونا في القاهرة خلال يوم عمل واحد.</p><ul><li><strong>توصيل قياسي</strong> — 2 إلى 4 أيام عمل، 70 جنيه.</li><li><strong>توصيل سريع</strong> — يوم العمل التالي، 120 جنيه.</li><li><strong>في نفس اليوم (القاهرة فقط)</strong> — للطلبات قبل الساعة 14:00، 180 جنيه.</li></ul><h3>مواعيد القطع</h3><p>الطلبات التي تُوضع بعد الساعة 14:00 يوم الجمعة تُسلَّم الأحد التالي.</p>',
            ],
            'meta' => [
                'en' => ['title' => 'Shipping policy — delivery times and fees', 'description' => 'Standard, express and same-day delivery options across Egypt, with fees and cut-off times.'],
                'ar' => ['title' => 'سياسة الشحن — مواعيد ورسوم التوصيل', 'description' => 'خيارات التوصيل القياسي والسريع وفي نفس اليوم في جميع أنحاء مصر، مع الرسوم ومواعيد القطع.'],
            ],
            'rules' => [
                'deliveryMinDays'      => 1,
                'deliveryMaxDays'      => 4,
                'freeShippingEnabled'  => true,
                'freeShippingThreshold' => 1500,
                'showFeesTable'        => true,
            ],
        ],
        'returns' => [
            'label'  => 'Returns Policy',
            'slug'   => 'returns',
            'status' => 'published',
            'updated' => '2026-10-05T09:14:00',
            'title'  => ['en' => 'Returns Policy', 'ar' => 'سياسة الإرجاع'],
            'content' => [
                'en' => '<h2>How to return an item</h2><p>You have 14 days from the day your order was delivered to return any item for a full refund. Items must be unworn, unwashed, and have their original tags attached.</p><ol><li>Open the order from your account and request a return.</li><li>Pack the item in its original packaging (or a similar box).</li><li>Our courier picks it up from your address — free inside Cairo, Giza and Alexandria.</li></ol><h3>Refunds</h3><p>Refunds are issued to the original payment method within 5 business days of us receiving the return.</p>',
                'ar' => '<h2>كيفية إرجاع قطعة</h2><p>لديك 14 يومًا من يوم استلام الطلب لإعادة أي قطعة واسترداد قيمتها كاملة. يجب أن تكون القطع غير مستخدمة وغير مغسولة مع وجود البطاقات الأصلية.</p><ol><li>افتح الطلب من حسابك واطلب الإرجاع.</li><li>ضع القطعة في علبتها الأصلية (أو علبة مشابهة).</li><li>المندوب يستلمها من عنوانك — مجانًا داخل القاهرة والجيزة والإسكندرية.</li></ol><h3>الاسترداد</h3><p>يُسترد المبلغ إلى وسيلة الدفع الأصلية خلال 5 أيام عمل من استلامنا للإرجاع.</p>',
            ],
            'meta' => [
                'en' => ['title' => 'Returns policy — 14 days, no questions', 'description' => 'Return unworn items within 14 days of delivery. Free pickup from Cairo, Giza and Alexandria.'],
                'ar' => ['title' => 'سياسة الإرجاع — 14 يومًا بلا أسئلة', 'description' => 'أعد القطع غير المستخدمة خلال 14 يومًا من الاستلام. استلام مجاني من القاهرة والجيزة والإسكندرية.'],
            ],
            'rules' => [
                'acceptReturns'   => true,
                'windowDays'      => 14,
                'conditions'      => [
                    'unused'         => true,
                    'tagsAttached'   => true,
                    'originalPack'   => true,
                    'hasReceipt'     => true,
                ],
                'returnShippingPaidBy' => 'customer', // 'customer' | 'store'
            ],
        ],
        'exchange' => [
            'label'  => 'Exchange Policy',
            'slug'   => 'exchange',
            'status' => 'draft',
            'updated' => '2026-09-28T16:40:00',
            'title'  => ['en' => 'Exchange Policy', 'ar' => 'سياسة الاستبدال'],
            'content' => [
                'en' => '<h2>Exchanges</h2><p>If a size or colour isn\'t right, you can exchange any item for the same product in a different size or colour, within 14 days of delivery.</p><p>Exchanges are free inside Cairo, Giza and Alexandria. Outside those governorates, the exchange shipping fee is the same as a new order\'s delivery fee.</p>',
                'ar' => '<h2>الاستبدال</h2><p>إذا كان المقاس أو اللون غير مناسب، يمكنك استبدال أي قطعة بنفس المنتج بمقاس أو لون مختلف خلال 14 يومًا من الاستلام.</p><p>الاستبدال مجاني داخل القاهرة والجيزة والإسكندرية. خارج هذه المحافظات، تُحتسب رسوم شحن الاستبدال بنفس رسوم توصيل طلب جديد.</p>',
            ],
            'meta' => [
                'en' => ['title' => 'Exchange policy — same price, different size', 'description' => 'Exchange an item for a different size or colour within 14 days. Subject to availability.'],
                'ar' => ['title' => 'سياسة الاستبدال — نفس السعر، مقاس مختلف', 'description' => 'استبدل القطعة بمقاس أو لون مختلف خلال 14 يومًا، حسب التوفر.'],
            ],
            'rules' => [
                'acceptExchange'    => true,
                'windowDays'        => 14,
                'sameProductOnly'   => true,
                'priceDifference'   => 'customer', // 'customer' | 'store-credit'
            ],
        ],
    ];

    // Default template text used by the "Insert default template"
    // button. One HTML string per policy, EN only.
    $templates = [
        'shipping' => '<h2>Delivery options</h2><p>We ship to every Egyptian governorate. All orders are dispatched from our Cairo studio within 1 business day.</p><ul><li><strong>Standard delivery</strong> — 2 to 4 business days.</li><li><strong>Express delivery</strong> — next business day.</li><li><strong>Same-day</strong> — Cairo only, for orders before 14:00.</li></ul><h3>Cut-off times</h3><p>Orders placed after 14:00 on Friday are dispatched the following Sunday.</p>',
        'returns'  => '<h2>How to return an item</h2><p>You have 14 days from the day your order was delivered to return any item for a full refund. Items must be unworn and have their original tags attached.</p><ol><li>Open the order from your account and request a return.</li><li>Pack the item in its original packaging.</li><li>Our courier picks it up from your address.</li></ol><h3>Refunds</h3><p>Refunds are issued to the original payment method within 5 business days.</p>',
        'exchange' => '<h2>Exchanges</h2><p>If a size or colour isn\'t right, you can exchange any item for the same product in a different size or colour, within 14 days of delivery.</p><p>Exchanges are free inside Cairo, Giza and Alexandria.</p>',
    ];

    // Tab order — drives the tablist and arrow-key navigation.
    $tabOrder = ['shipping', 'returns', 'exchange'];

    $policiesJson = json_encode($policies, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $templatesJson = json_encode($templates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@endphp

@section('content')
    {{-- ─── Page header ────────────────────────────────────────── --}}
    <header class="abo-admin-page__head">
        <div class="abo-admin-page__head-text">
            <p class="abo-admin-page__eyebrow">
                <span class="abo-admin-page__eyebrow-line" aria-hidden="true"></span>
                <span>CONTENT</span>
            </p>
            <h1 class="abo-admin-page__title">
                <span>POLI</span><span class="abo-admin-policies__title-lime">CIES</span>
            </h1>
        </div>

        <div class="abo-admin-page__head-actions">
            <button type="button" class="abo-admin-btn abo-admin-btn--ghost"
                    data-abo-admin-policies-action="discard">
                DISCARD
            </button>
            <button type="button" class="abo-admin-btn abo-admin-btn--ghost"
                    data-abo-admin-policies-action="save-draft">
                SAVE AS DRAFT
            </button>
            <button type="button" class="abo-admin-btn abo-admin-btn--primary"
                    data-abo-admin-policies-action="save-publish">
                SAVE AND PUBLISH
            </button>
        </div>
    </header>

    {{-- ─── Tabs (Shipping / Returns / Exchange) ────────────────── --}}
    <nav class="abo-admin-policies__tabs" aria-label="Policies">
        <div class="abo-admin-policies__tablist"
             role="tablist"
             aria-label="Policies"
             data-abo-admin-policies-tablist>
            @foreach ($tabOrder as $i => $slug)
                @php $p = $policies[$slug]; @endphp
                <button type="button"
                        class="abo-admin-policies__tab {{ $i === 0 ? 'is-active' : '' }}"
                        id="abo-admin-policies-tab-{{ $slug }}"
                        role="tab"
                        aria-selected="{{ $i === 0 ? 'true' : 'false' }}"
                        aria-controls="abo-admin-policies-panel-{{ $slug }}"
                        tabindex="{{ $i === 0 ? '0' : '-1' }}"
                        data-abo-admin-policies-tab="{{ $slug }}">
                    <span class="abo-admin-policies__tab-name">{{ $p['label'] }}</span>
                    <span class="abo-admin-policies__tab-dirty"
                          aria-label="Unsaved changes on {{ $p['label'] }}"
                          data-abo-admin-policies-tab-dirty="{{ $slug }}"
                          hidden></span>
                </button>
            @endforeach
        </div>

        <div class="abo-admin-policies__tab-status" aria-live="polite">
            <span class="abo-admin-pill abo-admin-pill--lime"
                  data-abo-admin-policies-status-pill>{{ ucfirst($policies['shipping']['status']) }}</span>
            <span class="abo-admin-policies__updated"
                  data-abo-admin-policies-updated>—</span>
        </div>
    </nav>

    {{-- ─── Loading skeleton (visible until policies.js hydrates) ─ --}}
    <div class="abo-admin-policies__skeleton"
         aria-hidden="true"
         data-abo-admin-policies-skeleton>
        <div class="abo-admin-policies__skeleton-row" style="height: 36px; width: 30%;"></div>
        <div class="abo-admin-policies__skeleton-grid">
            <div class="abo-admin-policies__skeleton-card" style="height: 360px;"></div>
            <div class="abo-admin-policies__skeleton-card" style="height: 360px;"></div>
        </div>
    </div>

    {{-- ─── Tab panels ──────────────────────────────────────────── --}}
    <div class="abo-admin-policies__layout"
         data-abo-admin-policies-layout
         hidden>

        @foreach ($tabOrder as $i => $slug)
            @php $p = $policies[$slug]; @endphp
            <section class="abo-admin-policies__panel"
                     id="abo-admin-policies-panel-{{ $slug }}"
                     role="tabpanel"
                     aria-labelledby="abo-admin-policies-tab-{{ $slug }}"
                     data-abo-admin-policies-panel="{{ $slug }}"
                     {{ $i !== 0 ? 'hidden' : '' }}>

                <div class="abo-admin-policies__columns">

                    {{-- ── LEFT: editor ────────────────────────────── --}}
                    <article class="abo-admin-card abo-admin-policies__editor"
                             aria-label="{{ $p['label'] }} editor"
                             data-abo-admin-policies-editor="{{ $slug }}">

                        <header class="abo-admin-policies__editor-head">
                            <div class="abo-admin-policies__editor-meta">
                                <p class="abo-admin-policies__eyebrow">EDIT POLICY</p>
                                <h2 class="abo-admin-policies__editor-title">{{ $p['label'] }}</h2>
                            </div>

                            <div class="abo-admin-policies__lang"
                                 role="tablist"
                                 aria-label="Title language"
                                 data-abo-admin-policies-title-lang="{{ $slug }}">
                                <button type="button"
                                        class="is-active"
                                        role="tab"
                                        aria-selected="true"
                                        data-abo-admin-policies-lang="en"
                                        data-abo-admin-policies-lang-scope="title"
                                        data-abo-admin-policies-policy="{{ $slug }}">EN</button>
                                <button type="button"
                                        role="tab"
                                        aria-selected="false"
                                        data-abo-admin-policies-lang="ar"
                                        data-abo-admin-policies-lang-scope="title"
                                        data-abo-admin-policies-policy="{{ $slug }}">AR</button>
                            </div>
                        </header>

                        <label class="sr-only" for="abo-admin-policies-title-en-{{ $slug }}">Title (English) — {{ $p['label'] }}</label>
                        <input id="abo-admin-policies-title-en-{{ $slug }}"
                               class="abo-admin-policies__field-input abo-admin-policies__field-input--title"
                               type="text"
                               autocomplete="off"
                               value="{{ $p['title']['en'] }}"
                               data-abo-admin-policies-title="en"
                               data-abo-admin-policies-policy="{{ $slug }}"
                               data-abo-admin-policies-field="title">
                        <label class="sr-only" for="abo-admin-policies-title-ar-{{ $slug }}">Title (Arabic) — {{ $p['label'] }}</label>
                        <input id="abo-admin-policies-title-ar-{{ $slug }}"
                               class="abo-admin-policies__field-input abo-admin-policies__field-input--title abo-admin-policies__field-input--rtl"
                               type="text"
                               autocomplete="off"
                               dir="rtl"
                               lang="ar"
                               value="{{ $p['title']['ar'] }}"
                               data-abo-admin-policies-title="ar"
                               data-abo-admin-policies-policy="{{ $slug }}"
                               data-abo-admin-policies-field="title"
                               hidden>

                        {{-- Rich-text toolbar (Bold, Italic, H2, H3, lists, link, undo, redo) --}}
                        <div class="abo-admin-policies__toolbar"
                             role="toolbar"
                             aria-label="Formatting">
                            <button type="button" class="abo-admin-policies__tb-btn"
                                    data-abo-admin-policies-tb="bold"
                                    data-abo-admin-policies-policy="{{ $slug }}"
                                    aria-label="Bold"><strong>B</strong></button>
                            <button type="button" class="abo-admin-policies__tb-btn"
                                    data-abo-admin-policies-tb="italic"
                                    data-abo-admin-policies-policy="{{ $slug }}"
                                    aria-label="Italic"><em>I</em></button>
                            <span class="abo-admin-policies__tb-sep" aria-hidden="true"></span>
                            <button type="button" class="abo-admin-policies__tb-btn"
                                    data-abo-admin-policies-tb="h2"
                                    data-abo-admin-policies-policy="{{ $slug }}"
                                    aria-label="Heading 2">H2</button>
                            <button type="button" class="abo-admin-policies__tb-btn"
                                    data-abo-admin-policies-tb="h3"
                                    data-abo-admin-policies-policy="{{ $slug }}"
                                    aria-label="Heading 3">H3</button>
                            <span class="abo-admin-policies__tb-sep" aria-hidden="true"></span>
                            <button type="button" class="abo-admin-policies__tb-btn"
                                    data-abo-admin-policies-tb="ul"
                                    data-abo-admin-policies-policy="{{ $slug }}"
                                    aria-label="Bullet list">•</button>
                            <button type="button" class="abo-admin-policies__tb-btn"
                                    data-abo-admin-policies-tb="ol"
                                    data-abo-admin-policies-policy="{{ $slug }}"
                                    aria-label="Numbered list">1.</button>
                            <button type="button" class="abo-admin-policies__tb-btn"
                                    data-abo-admin-policies-tb="link"
                                    data-abo-admin-policies-policy="{{ $slug }}"
                                    aria-label="Insert link">↗</button>
                            <span class="abo-admin-policies__tb-sep" aria-hidden="true"></span>
                            <button type="button" class="abo-admin-policies__tb-btn"
                                    data-abo-admin-policies-tb="undo"
                                    data-abo-admin-policies-policy="{{ $slug }}"
                                    aria-label="Undo">⤺</button>
                            <button type="button" class="abo-admin-policies__tb-btn"
                                    data-abo-admin-policies-tb="redo"
                                    data-abo-admin-policies-policy="{{ $slug }}"
                                    aria-label="Redo">⤻</button>
                        </div>

                        <label class="sr-only" for="abo-admin-policies-content-en-{{ $slug }}">Content (English) — {{ $p['label'] }}</label>
                        <div id="abo-admin-policies-content-en-{{ $slug }}"
                             class="abo-admin-policies__content"
                             contenteditable="true"
                             role="textbox"
                             aria-multiline="true"
                             aria-label="Content (English) — {{ $p['label'] }}"
                             data-abo-admin-policies-content="en"
                             data-abo-admin-policies-policy="{{ $slug }}"
                             data-abo-admin-policies-field="content">{!! $p['content']['en'] !!}</div>
                        <label class="sr-only" for="abo-admin-policies-content-ar-{{ $slug }}">Content (Arabic) — {{ $p['label'] }}</label>
                        <div id="abo-admin-policies-content-ar-{{ $slug }}"
                             class="abo-admin-policies__content abo-admin-policies__content--rtl"
                             contenteditable="true"
                             role="textbox"
                             aria-multiline="true"
                             aria-label="Content (Arabic) — {{ $p['label'] }}"
                             dir="rtl"
                             lang="ar"
                             data-abo-admin-policies-content="ar"
                             data-abo-admin-policies-policy="{{ $slug }}"
                             data-abo-admin-policies-field="content"
                             hidden>{!! $p['content']['ar'] !!}</div>

                        {{-- Empty-state placeholder (shown when the editor is empty) --}}
                        <p class="abo-admin-policies__empty"
                           data-abo-admin-policies-empty="{{ $slug }}"
                           hidden>
                            Nothing written yet. Insert the default template or start typing.
                        </p>

                        {{-- Counter row --}}
                        <div class="abo-admin-policies__counter-row">
                            <button type="button"
                                    class="abo-admin-policies__tpl-btn"
                                    data-abo-admin-policies-template="{{ $slug }}">
                                + INSERT DEFAULT TEMPLATE
                            </button>
                            <span class="abo-admin-policies__counter"
                                  data-abo-admin-policies-counter="{{ $slug }}">0 words · 0 characters</span>
                        </div>
                    </article>

                    {{-- ── RIGHT: rules + SEO + preview ─────────────── --}}
                    <aside class="abo-admin-policies__right"
                           aria-label="{{ $p['label'] }} rules and preview">

                        {{-- RULES CARD (per tab) --}}
                        <article class="abo-admin-card abo-admin-policies__rules"
                                 aria-label="{{ $p['label'] }} rules">
                            <header class="abo-admin-policies__card-head">
                                <h3 class="abo-admin-policies__card-title">RULES</h3>
                                <p class="abo-admin-policies__card-note">Shown on the store page and used by the preview below.</p>
                            </header>

                            {{-- ─── SHIPPING rules ─── --}}
                            @if ($slug === 'shipping')
                                <div class="abo-admin-policies__field-row abo-admin-policies__field-row--two">
                                    <div class="abo-admin-policies__field">
                                        <label class="abo-admin-policies__field-label" for="abo-admin-policies-delivery-min-{{ $slug }}">Delivery from</label>
                                        <div class="abo-admin-policies__number">
                                            <input id="abo-admin-policies-delivery-min-{{ $slug }}"
                                                   class="abo-admin-policies__field-input"
                                                   type="number"
                                                   min="0"
                                                   step="1"
                                                   value="{{ $p['rules']['deliveryMinDays'] }}"
                                                   autocomplete="off"
                                                   data-abo-admin-policies-field="rules.deliveryMinDays"
                                                   data-abo-admin-policies-policy="{{ $slug }}">
                                            <span class="abo-admin-policies__number-suffix">days</span>
                                        </div>
                                    </div>
                                    <div class="abo-admin-policies__field">
                                        <label class="abo-admin-policies__field-label" for="abo-admin-policies-delivery-max-{{ $slug }}">Delivery to</label>
                                        <div class="abo-admin-policies__number">
                                            <input id="abo-admin-policies-delivery-max-{{ $slug }}"
                                                   class="abo-admin-policies__field-input"
                                                   type="number"
                                                   min="0"
                                                   step="1"
                                                   value="{{ $p['rules']['deliveryMaxDays'] }}"
                                                   autocomplete="off"
                                                   data-abo-admin-policies-field="rules.deliveryMaxDays"
                                                   data-abo-admin-policies-policy="{{ $slug }}">
                                            <span class="abo-admin-policies__number-suffix">days</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="abo-admin-policies__switch">
                                    <div>
                                        <p class="abo-admin-policies__switch-label">Free shipping</p>
                                        <p class="abo-admin-policies__switch-note">Offer free shipping above a cart total.</p>
                                    </div>
                                    <label class="abo-admin-policies__toggle"
                                           aria-label="Free shipping">
                                        <input type="checkbox"
                                               {{ $p['rules']['freeShippingEnabled'] ? 'checked' : '' }}
                                               aria-checked="{{ $p['rules']['freeShippingEnabled'] ? 'true' : 'false' }}"
                                               data-abo-admin-policies-field="rules.freeShippingEnabled"
                                               data-abo-admin-policies-policy="{{ $slug }}">
                                        <span class="abo-admin-policies__toggle-track" aria-hidden="true"></span>
                                        <span class="abo-admin-policies__toggle-thumb" aria-hidden="true"></span>
                                    </label>
                                </div>

                                <div class="abo-admin-policies__field"
                                     data-abo-admin-policies-threshold-wrap>
                                    <label class="abo-admin-policies__field-label" for="abo-admin-policies-threshold-{{ $slug }}">Free shipping above</label>
                                    <div class="abo-admin-policies__currency">
                                        <span class="abo-admin-policies__currency-prefix" aria-hidden="true">EGP</span>
                                        <input id="abo-admin-policies-threshold-{{ $slug }}"
                                               class="abo-admin-policies__field-input"
                                               type="number"
                                               min="0"
                                               step="1"
                                               value="{{ $p['rules']['freeShippingThreshold'] }}"
                                               autocomplete="off"
                                               data-abo-admin-policies-field="rules.freeShippingThreshold"
                                               data-abo-admin-policies-policy="{{ $slug }}">
                                    </div>
                                </div>

                                <div class="abo-admin-policies__switch">
                                    <div>
                                        <p class="abo-admin-policies__switch-label">Show shipping fees table from the Shipping page</p>
                                        <p class="abo-admin-policies__switch-note">Embed the fees table below the policy on the store.</p>
                                    </div>
                                    <label class="abo-admin-policies__toggle"
                                           aria-label="Show shipping fees table">
                                        <input type="checkbox"
                                               {{ $p['rules']['showFeesTable'] ? 'checked' : '' }}
                                               aria-checked="{{ $p['rules']['showFeesTable'] ? 'true' : 'false' }}"
                                               data-abo-admin-policies-field="rules.showFeesTable"
                                               data-abo-admin-policies-policy="{{ $slug }}">
                                        <span class="abo-admin-policies__toggle-track" aria-hidden="true"></span>
                                        <span class="abo-admin-policies__toggle-thumb" aria-hidden="true"></span>
                                    </label>
                                </div>
                            @endif

                            {{-- ─── RETURNS rules ─── --}}
                            @if ($slug === 'returns')
                                <div class="abo-admin-policies__switch">
                                    <div>
                                        <p class="abo-admin-policies__switch-label">Accept returns</p>
                                        <p class="abo-admin-policies__switch-note">Off hides the page from the store footer.</p>
                                    </div>
                                    <label class="abo-admin-policies__toggle"
                                           aria-label="Accept returns">
                                        <input type="checkbox"
                                               {{ $p['rules']['acceptReturns'] ? 'checked' : '' }}
                                               aria-checked="{{ $p['rules']['acceptReturns'] ? 'true' : 'false' }}"
                                               data-abo-admin-policies-field="rules.acceptReturns"
                                               data-abo-admin-policies-policy="{{ $slug }}">
                                        <span class="abo-admin-policies__toggle-track" aria-hidden="true"></span>
                                        <span class="abo-admin-policies__toggle-thumb" aria-hidden="true"></span>
                                    </label>
                                </div>

                                <div class="abo-admin-policies__field">
                                    <label class="abo-admin-policies__field-label" for="abo-admin-policies-window-{{ $slug }}">Return window</label>
                                    <div class="abo-admin-policies__number">
                                        <input id="abo-admin-policies-window-{{ $slug }}"
                                               class="abo-admin-policies__field-input"
                                               type="number"
                                               min="0"
                                               step="1"
                                               value="{{ $p['rules']['windowDays'] }}"
                                               autocomplete="off"
                                               data-abo-admin-policies-field="rules.windowDays"
                                               data-abo-admin-policies-policy="{{ $slug }}">
                                        <span class="abo-admin-policies__number-suffix">days</span>
                                    </div>
                                </div>

                                <fieldset class="abo-admin-policies__checklist"
                                          aria-label="Return conditions">
                                    <legend class="abo-admin-policies__field-label">Conditions the customer must meet</legend>
                                    <label class="abo-admin-policies__checklist-row">
                                        <input type="checkbox"
                                               {{ $p['rules']['conditions']['unused'] ? 'checked' : '' }}
                                               data-abo-admin-policies-field="rules.conditions.unused"
                                               data-abo-admin-policies-policy="{{ $slug }}">
                                        <span>Item unused</span>
                                    </label>
                                    <label class="abo-admin-policies__checklist-row">
                                        <input type="checkbox"
                                               {{ $p['rules']['conditions']['tagsAttached'] ? 'checked' : '' }}
                                               data-abo-admin-policies-field="rules.conditions.tagsAttached"
                                               data-abo-admin-policies-policy="{{ $slug }}">
                                        <span>Tags attached</span>
                                    </label>
                                    <label class="abo-admin-policies__checklist-row">
                                        <input type="checkbox"
                                               {{ $p['rules']['conditions']['originalPack'] ? 'checked' : '' }}
                                               data-abo-admin-policies-field="rules.conditions.originalPack"
                                               data-abo-admin-policies-policy="{{ $slug }}">
                                        <span>Original packaging</span>
                                    </label>
                                    <label class="abo-admin-policies__checklist-row">
                                        <input type="checkbox"
                                               {{ $p['rules']['conditions']['hasReceipt'] ? 'checked' : '' }}
                                               data-abo-admin-policies-field="rules.conditions.hasReceipt"
                                               data-abo-admin-policies-policy="{{ $slug }}">
                                        <span>Receipt / order number</span>
                                    </label>
                                </fieldset>

                                <div class="abo-admin-policies__field">
                                    <label class="abo-admin-policies__field-label" for="abo-admin-policies-who-pays-{{ $slug }}">Who pays return shipping</label>
                                    <select id="abo-admin-policies-who-pays-{{ $slug }}"
                                            class="abo-admin-policies__field-input"
                                            data-abo-admin-policies-field="rules.returnShippingPaidBy"
                                            data-abo-admin-policies-policy="{{ $slug }}">
                                        <option value="customer" {{ $p['rules']['returnShippingPaidBy'] === 'customer' ? 'selected' : '' }}>Customer</option>
                                        <option value="store"    {{ $p['rules']['returnShippingPaidBy'] === 'store'    ? 'selected' : '' }}>Store</option>
                                    </select>
                                </div>
                            @endif

                            {{-- ─── EXCHANGE rules ─── --}}
                            @if ($slug === 'exchange')
                                <div class="abo-admin-policies__switch">
                                    <div>
                                        <p class="abo-admin-policies__switch-label">Accept exchanges</p>
                                        <p class="abo-admin-policies__switch-note">Off hides the page from the store footer.</p>
                                    </div>
                                    <label class="abo-admin-policies__toggle"
                                           aria-label="Accept exchanges">
                                        <input type="checkbox"
                                               {{ $p['rules']['acceptExchange'] ? 'checked' : '' }}
                                               aria-checked="{{ $p['rules']['acceptExchange'] ? 'true' : 'false' }}"
                                               data-abo-admin-policies-field="rules.acceptExchange"
                                               data-abo-admin-policies-policy="{{ $slug }}">
                                        <span class="abo-admin-policies__toggle-track" aria-hidden="true"></span>
                                        <span class="abo-admin-policies__toggle-thumb" aria-hidden="true"></span>
                                    </label>
                                </div>

                                <div class="abo-admin-policies__field">
                                    <label class="abo-admin-policies__field-label" for="abo-admin-policies-window-{{ $slug }}">Exchange window</label>
                                    <div class="abo-admin-policies__number">
                                        <input id="abo-admin-policies-window-{{ $slug }}"
                                               class="abo-admin-policies__field-input"
                                               type="number"
                                               min="0"
                                               step="1"
                                               value="{{ $p['rules']['windowDays'] }}"
                                               autocomplete="off"
                                               data-abo-admin-policies-field="rules.windowDays"
                                               data-abo-admin-policies-policy="{{ $slug }}">
                                        <span class="abo-admin-policies__number-suffix">days</span>
                                    </div>
                                </div>

                                <div class="abo-admin-policies__switch">
                                    <div>
                                        <p class="abo-admin-policies__switch-label">Exchange for same product only</p>
                                        <p class="abo-admin-policies__switch-note">Off allows swapping for a different product.</p>
                                    </div>
                                    <label class="abo-admin-policies__toggle"
                                           aria-label="Same product only">
                                        <input type="checkbox"
                                               {{ $p['rules']['sameProductOnly'] ? 'checked' : '' }}
                                               aria-checked="{{ $p['rules']['sameProductOnly'] ? 'true' : 'false' }}"
                                               data-abo-admin-policies-field="rules.sameProductOnly"
                                               data-abo-admin-policies-policy="{{ $slug }}">
                                        <span class="abo-admin-policies__toggle-track" aria-hidden="true"></span>
                                        <span class="abo-admin-policies__toggle-thumb" aria-hidden="true"></span>
                                    </label>
                                </div>

                                <div class="abo-admin-policies__field">
                                    <label class="abo-admin-policies__field-label" for="abo-admin-policies-price-diff-{{ $slug }}">Price difference handling</label>
                                    <select id="abo-admin-policies-price-diff-{{ $slug }}"
                                            class="abo-admin-policies__field-input"
                                            data-abo-admin-policies-field="rules.priceDifference"
                                            data-abo-admin-policies-policy="{{ $slug }}">
                                        <option value="customer"     {{ $p['rules']['priceDifference'] === 'customer'     ? 'selected' : '' }}>Customer pays the difference</option>
                                        <option value="store-credit" {{ $p['rules']['priceDifference'] === 'store-credit' ? 'selected' : '' }}>Store credit</option>
                                    </select>
                                </div>
                            @endif
                        </article>

                        {{-- SEO CARD (per tab) --}}
                        <article class="abo-admin-card abo-admin-policies__seo"
                                 aria-label="SEO">
                            <header class="abo-admin-policies__card-head">
                                <h3 class="abo-admin-policies__card-title">SEO</h3>
                                <p class="abo-admin-policies__card-note">Used by the public page metadata.</p>
                            </header>

                            <div class="abo-admin-policies__seo-lang"
                                 role="tablist"
                                 aria-label="SEO language"
                                 data-abo-admin-policies-seo-lang="{{ $slug }}">
                                <button type="button"
                                        class="is-active"
                                        role="tab"
                                        aria-selected="true"
                                        data-abo-admin-policies-lang="en"
                                        data-abo-admin-policies-lang-scope="seo"
                                        data-abo-admin-policies-policy="{{ $slug }}">EN</button>
                                <button type="button"
                                        role="tab"
                                        aria-selected="false"
                                        data-abo-admin-policies-lang="ar"
                                        data-abo-admin-policies-lang-scope="seo"
                                        data-abo-admin-policies-policy="{{ $slug }}">AR</button>
                            </div>

                            <div class="abo-admin-policies__seo-fields"
                                 data-abo-admin-policies-seo-fields="en"
                                 data-abo-admin-policies-policy="{{ $slug }}">
                                <div class="abo-admin-policies__field">
                                    <div class="abo-admin-policies__field-head">
                                        <label class="abo-admin-policies__field-label" for="abo-admin-policies-meta-title-en-{{ $slug }}">Meta title</label>
                                        <span class="abo-admin-policies__counter"
                                              data-abo-admin-policies-seo-counter="metaTitle-{{ $slug }}-en">0 / 60</span>
                                    </div>
                                    <input id="abo-admin-policies-meta-title-en-{{ $slug }}"
                                           class="abo-admin-policies__field-input"
                                           type="text"
                                           maxlength="200"
                                           value="{{ $p['meta']['en']['title'] }}"
                                           data-abo-admin-policies-field="meta.en.title"
                                           data-abo-admin-policies-policy="{{ $slug }}"
                                           data-abo-admin-policies-seo-counter-input
                                           data-abo-admin-policies-seo-counter-name="metaTitle-{{ $slug }}-en"
                                           data-abo-admin-policies-seo-counter-max="60">
                                </div>
                                <div class="abo-admin-policies__field">
                                    <div class="abo-admin-policies__field-head">
                                        <label class="abo-admin-policies__field-label" for="abo-admin-policies-meta-desc-en-{{ $slug }}">Meta description</label>
                                        <span class="abo-admin-policies__counter"
                                              data-abo-admin-policies-seo-counter="metaDesc-{{ $slug }}-en">0 / 160</span>
                                    </div>
                                    <textarea id="abo-admin-policies-meta-desc-en-{{ $slug }}"
                                              class="abo-admin-policies__field-textarea"
                                              rows="3"
                                              maxlength="400"
                                              data-abo-admin-policies-field="meta.en.description"
                                              data-abo-admin-policies-policy="{{ $slug }}"
                                              data-abo-admin-policies-seo-counter-input
                                              data-abo-admin-policies-seo-counter-name="metaDesc-{{ $slug }}-en"
                                              data-abo-admin-policies-seo-counter-max="160">{{ $p['meta']['en']['description'] }}</textarea>
                                </div>
                            </div>

                            <div class="abo-admin-policies__seo-fields abo-admin-policies__seo-fields--rtl"
                                 data-abo-admin-policies-seo-fields="ar"
                                 data-abo-admin-policies-policy="{{ $slug }}"
                                 dir="rtl"
                                 lang="ar"
                                 hidden>
                                <div class="abo-admin-policies__field">
                                    <div class="abo-admin-policies__field-head">
                                        <label class="abo-admin-policies__field-label" for="abo-admin-policies-meta-title-ar-{{ $slug }}">عنوان الميتا</label>
                                        <span class="abo-admin-policies__counter"
                                              data-abo-admin-policies-seo-counter="metaTitle-{{ $slug }}-ar">0 / 60</span>
                                    </div>
                                    <input id="abo-admin-policies-meta-title-ar-{{ $slug }}"
                                           class="abo-admin-policies__field-input abo-admin-policies__field-input--rtl"
                                           type="text"
                                           maxlength="200"
                                           value="{{ $p['meta']['ar']['title'] }}"
                                           data-abo-admin-policies-field="meta.ar.title"
                                           data-abo-admin-policies-policy="{{ $slug }}"
                                           data-abo-admin-policies-seo-counter-input
                                           data-abo-admin-policies-seo-counter-name="metaTitle-{{ $slug }}-ar"
                                           data-abo-admin-policies-seo-counter-max="60">
                                </div>
                                <div class="abo-admin-policies__field">
                                    <div class="abo-admin-policies__field-head">
                                        <label class="abo-admin-policies__field-label" for="abo-admin-policies-meta-desc-ar-{{ $slug }}">وصف الميتا</label>
                                        <span class="abo-admin-policies__counter"
                                              data-abo-admin-policies-seo-counter="metaDesc-{{ $slug }}-ar">0 / 160</span>
                                    </div>
                                    <textarea id="abo-admin-policies-meta-desc-ar-{{ $slug }}"
                                              class="abo-admin-policies__field-textarea abo-admin-policies__field-textarea--rtl"
                                              rows="3"
                                              maxlength="400"
                                              data-abo-admin-policies-field="meta.ar.description"
                                              data-abo-admin-policies-policy="{{ $slug }}"
                                              data-abo-admin-policies-seo-counter-input
                                              data-abo-admin-policies-seo-counter-name="metaDesc-{{ $slug }}-ar"
                                              data-abo-admin-policies-seo-counter-max="160">{{ $p['meta']['ar']['description'] }}</textarea>
                                </div>
                            </div>
                        </article>

                        {{-- LIVE PREVIEW CARD (per tab) --}}
                        <article class="abo-admin-card abo-admin-policies__preview"
                                 aria-label="Live preview">
                            <header class="abo-admin-policies__card-head">
                                <h3 class="abo-admin-policies__card-title">LIVE PREVIEW</h3>
                                <p class="abo-admin-policies__card-note">Updates as you type. Mirrors the public store page.</p>
                            </header>

                            <div class="abo-admin-policies__preview-frame"
                                 data-abo-admin-policies-preview="{{ $slug }}"
                                 data-abo-admin-policies-preview-lang="en">
                                <h2 class="abo-admin-policies__preview-title"
                                    data-abo-admin-policies-preview-title>{{ $p['title']['en'] }}</h2>
                                <p class="abo-admin-policies__preview-summary"
                                   data-abo-admin-policies-preview-summary>—</p>
                                <div class="abo-admin-policies__preview-body"
                                     data-abo-admin-policies-preview-body>{!! $p['content']['en'] !!}</div>
                            </div>
                        </article>

                    </aside>
                </div>
            </section>
        @endforeach
    </div>

    {{-- ─── Confirm modal (insert default template) ────────────── --}}
    <div class="abo-admin-policies__modal"
         role="dialog"
         aria-modal="true"
         aria-labelledby="abo-admin-policies-template-title"
         hidden
         data-abo-admin-policies-template-modal>
        <div class="abo-admin-policies__modal-backdrop"
             data-abo-admin-policies-template-close></div>
        <div class="abo-admin-policies__modal-card">
            <h2 class="abo-admin-policies__modal-title"
                id="abo-admin-policies-template-title">REPLACE CONTENT?</h2>
            <p class="abo-admin-policies__modal-body"
               data-abo-admin-policies-template-body>
                This will replace the current editor content with the default template. You can edit it afterwards.
            </p>
            <footer class="abo-admin-policies__modal-foot">
                <button type="button" class="abo-admin-btn abo-admin-btn--ghost"
                        data-abo-admin-policies-template-close>CANCEL</button>
                <button type="button"
                        class="abo-admin-policies__modal-confirm"
                        data-abo-admin-policies-template-confirm>INSERT</button>
            </footer>
        </div>
    </div>

    {{-- ─── Single shared toast ────────────────────────────────── --}}
    <div class="abo-admin-policies__toast"
         role="status" aria-live="polite"
         hidden
         data-abo-admin-policies-toast>
        <span class="abo-admin-policies__toast-dot" aria-hidden="true"></span>
        <span data-abo-admin-policies-toast-text>Saved (demo)</span>
    </div>

    {{-- ─── Datasets consumed by public/admin/js/policies.js ────── --}}
    <script type="application/json" id="abo-admin-policies-data">{!! $policiesJson !!}</script>
    <script type="application/json" id="abo-admin-policies-templates">{!! $templatesJson !!}</script>
    <script type="application/json" id="abo-admin-policies-tab-order">{!! json_encode($tabOrder) !!}</script>
@endsection

@push('head')
    {{-- Page-local stylesheet. Loaded only on the policies page. --}}
    <link rel="stylesheet" href="{{ asset('admin/css/policies.css') }}">
@endpush

@push('scripts')
    {{-- Page-local script. Loaded only on the policies page. --}}
    <script src="{{ asset('admin/js/policies.js') }}" defer></script>
@endpush