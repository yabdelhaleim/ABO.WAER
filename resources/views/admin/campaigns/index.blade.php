{{--
    Admin · Campaigns (front-end only, static data).

    Extends the existing admin layout. No controller, no model, no API.
    Every row, filter, slide-over field and live preview is hydrated by
    public/admin/js/campaigns.js from the static CAMPAIGNS array in that
    same file. Each section that would need real data is marked
    TODO(backend).

    Page sections (top to bottom):
      1. Page header    — MARKETING eyebrow + CAM/PAIGNS title, lime
                          "+ NEW CAMPAIGN" CTA on the right.
      2. Stat cards     — 4 KPI cards (Active, Scheduled, Sent this
                          month, Avg open rate). Clicking a card
                          filters the table by that status.
      3. Filter bar     — search by name, type select (Push / Email /
                          SMS), status tabs (All, Active, Scheduled,
                          Draft, Ended), sort select (Newest, Best
                          performing).
      4. Table          — Name + preview, Type pill, Audience, Status
                          pill, Schedule, Sent, Opened %, Clicked %,
                          row menu (Edit, Duplicate, Pause/Resume,
                          Delete with confirm modal).
      5. Pagination     — static, styled.
      6. New / edit     — slide-over from the right (560px / full on
                          mobile), 4 steps (Content, Audience,
                          Schedule, Review) with a progress bar.
                          A live phone-style notification preview
                          updates while typing in step 1.
      7. Modal + toast  — single shared send-confirm modal + toast.

    Conventions match admin/products/index.blade.php and
    admin/banners/index.blade.php: existing shell classes are reused
    (abo-admin-*), new campaign-specific classes are prefixed
    `abo-admin-campaigns-`.
--}}

@extends('admin.layouts.app')

@section('title', 'Campaigns · Marketing')
@section('description', 'ABO — admin marketing campaigns: list, filter, schedule and review push / email / SMS campaigns.')

@php
    // KPI cards: derived server-side in Admin\CampaignController::index
    // via App\Services\Admin\CampaignService::getDashboardStats. The
    // JS-side CAMPAIGNS array in public/admin/js/campaigns.js is now
    // hydrated from window.CAMPAIGNS_DATA (injected below) instead of
    // the static fixture that used to live at the top of the file.

    $activeCampaignsCount = number_format((int) ($stats['active_campaigns_count'] ?? 0));
    $totalSentCount = number_format((int) ($stats['total_sent_count'] ?? 0));
    $avgOpenRate = number_format((float) ($stats['avg_open_rate'] ?? 0), 1);
    $avgClickRate = number_format((float) ($stats['avg_click_rate'] ?? 0), 1);

    $statCards = [
        ['key' => 'active',    'label' => 'Active',           'value' => $activeCampaignsCount,      'tone' => 'lime'],
        ['key' => 'scheduled', 'label' => 'Scheduled',        'value' => '0',                       'tone' => 'amber'],
        ['key' => 'sent',      'label' => 'Total messages sent','value' => $totalSentCount,          'tone' => 'olive'],
        ['key' => 'open',      'label' => 'Avg open rate',    'value' => $avgOpenRate.'%',          'tone' => 'lime'],
    ];

    $typeOptions = [
        ['value' => '',       'label' => 'All types'],
        ['value' => 'push',   'label' => 'Push'],
        ['value' => 'email',  'label' => 'Email'],
        ['value' => 'sms',    'label' => 'SMS'],
    ];

    $statusTabs = [
        ['value' => '',         'label' => 'All'],
        ['value' => 'active',   'label' => 'Active'],
        ['value' => 'scheduled','label' => 'Scheduled'],
        ['value' => 'draft',    'label' => 'Draft'],
        ['value' => 'ended',    'label' => 'Ended'],
    ];

    $sortOptions = [
        ['value' => 'newest',  'label' => 'Newest'],
        ['value' => 'best',    'label' => 'Best performing'],
    ];
@endphp

@section('content')
    {{-- ─── Page header ────────────────────────────────────────── --}}
    <header class="abo-admin-page__head">
        <div class="abo-admin-page__head-text">
            <p class="abo-admin-page__eyebrow">
                <span class="abo-admin-page__eyebrow-line" aria-hidden="true"></span>
                <span>MARKETING</span>
            </p>
            <h1 class="abo-admin-page__title">
                <span>CAM</span><span class="abo-admin-campaigns__title-lime">PAIGNS</span>
            </h1>
            <p class="abo-admin-page__date" id="abo-admin-campaigns-today-date">Loading date…</p>
        </div>

        <div class="abo-admin-page__head-actions">
            <button type="button"
                    class="abo-admin-btn abo-admin-btn--primary"
                    data-abo-admin-campaigns-add
                    aria-label="Create a new campaign">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.8"
                          fill="none" stroke-linecap="round"/>
                </svg>
                + NEW CAMPAIGN
            </button>
        </div>
    </header>

    {{-- ─── Stat cards (clickable filters) ─────────────────────── --}}
    <section class="abo-admin-attention abo-admin-campaigns__kpis"
             aria-label="Campaign summary"
             data-abo-admin-campaigns-kpis>
        @foreach ($statCards as $i => $card)
            <button type="button"
                    class="abo-admin-attention__card abo-admin-attention__card--{{ $card['tone'] }} @if ($card['key'] === 'active' || $card['key'] === 'open') is-active-stat @endif"
                    data-abo-admin-campaigns-stat="{{ $card['key'] }}"
                    aria-pressed="{{ ($card['key'] === 'active' || $card['key'] === 'open') ? 'true' : 'false' }}">
                <span class="abo-admin-attention__count">{{ $card['value'] }}</span>
                <span class="abo-admin-attention__label">{{ $card['label'] }}</span>
                <span class="abo-admin-attention__arrow" aria-hidden="true">→</span>
            </button>
        @endforeach
    </section>

    {{-- ─── Filter bar ─────────────────────────────────────────── --}}
    <section class="abo-admin-card abo-admin-campaigns__filters" aria-label="Filter campaigns">
        <div class="abo-admin-campaigns__filter-search">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" class="abo-admin-campaigns__filter-icon">
                <path d="M21 20.3 16.7 16a8 8 0 1 0-1.4 1.4L20.3 21 21 20.3zM4 10a6 6 0 1 1 12 0 6 6 0 0 1-12 0z"
                      fill="currentColor"/>
            </svg>
            <label class="sr-only" for="abo-admin-campaigns-search">Search campaigns</label>
            <input id="abo-admin-campaigns-search"
                   type="search"
                   placeholder="Search by name…"
                   autocomplete="off"
                   data-abo-admin-campaigns-search>
        </div>

        <label class="sr-only" for="abo-admin-campaigns-type">Type</label>
        <select id="abo-admin-campaigns-type" class="abo-admin-campaigns__select"
                data-abo-admin-campaigns-filter="type">
            @foreach ($typeOptions as $opt)
                <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
            @endforeach
        </select>

        <div class="abo-admin-campaigns__tabs" role="tablist" aria-label="Status"
             data-abo-admin-campaigns-status>
            @foreach ($statusTabs as $i => $tab)
                <button type="button"
                        role="tab"
                        aria-selected="{{ $i === 0 ? 'true' : 'false' }}"
                        class="@if ($i === 0) is-active @endif"
                        data-abo-admin-campaigns-status-tab="{{ $tab['value'] }}">{{ $tab['label'] }}</button>
            @endforeach
        </div>

        <label class="sr-only" for="abo-admin-campaigns-sort">Sort by</label>
        <select id="abo-admin-campaigns-sort" class="abo-admin-campaigns__select"
                data-abo-admin-campaigns-sort>
            @foreach ($sortOptions as $opt)
                <option value="{{ $opt['value'] }}" @if ($loop->first) selected @endif>{{ $opt['label'] }}</option>
            @endforeach
        </select>
    </section>

    {{-- ─── Campaigns table ─────────────────────────────────────── --}}
    <article class="abo-admin-card abo-admin-campaigns__table-card" aria-label="Campaigns">
        <div class="abo-admin-orders__wrap abo-admin-campaigns__table-wrap">
            <table class="abo-admin-orders__table abo-admin-campaigns__table">
                <thead>
                    <tr>
                        <th scope="col">NAME</th>
                        <th scope="col">TYPE</th>
                        <th scope="col">AUDIENCE</th>
                        <th scope="col">STATUS</th>
                        <th scope="col">SCHEDULE</th>
                        <th scope="col" class="abo-admin-campaigns__th-num">SENT</th>
                        <th scope="col" class="abo-admin-campaigns__th-bar">OPENED</th>
                        <th scope="col" class="abo-admin-campaigns__th-bar">CLICKED</th>
                        <th scope="col" class="abo-admin-campaigns__th-actions"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody data-abo-admin-campaigns-tbody>
                    {{-- Rows are injected by public/admin/js/campaigns.js
                         from the static CAMPAIGNS array. --}}
                </tbody>
            </table>
        </div>

        {{-- States: skeleton / empty / no-results are mutually exclusive --}}
        <div class="abo-admin-campaigns__state" data-abo-admin-campaigns-state="empty" hidden>
            <p class="abo-admin-empty">No campaigns yet. Create your first campaign.</p>
            <button type="button" class="abo-admin-btn abo-admin-btn--primary"
                    data-abo-admin-campaigns-add>
                + NEW CAMPAIGN
            </button>
        </div>
        <div class="abo-admin-campaigns__state" data-abo-admin-campaigns-state="noresults" hidden>
            <p class="abo-admin-empty">No results. Try clearing the filters above.</p>
        </div>
        <div class="abo-admin-campaigns__state" data-abo-admin-campaigns-state="loading" hidden>
            <div class="abo-admin-campaigns__skeleton" aria-hidden="true">
                @for ($i = 0; $i < 6; $i++)
                    <div class="abo-admin-campaigns__skeleton-row">
                        <span class="abo-admin-campaigns__skeleton-cell" style="width: 36%; height: 14px;"></span>
                        <span class="abo-admin-campaigns__skeleton-cell" style="width: 60px; height: 14px;"></span>
                        <span class="abo-admin-campaigns__skeleton-cell" style="width: 80px; height: 14px;"></span>
                        <span class="abo-admin-campaigns__skeleton-cell" style="width: 90px; height: 14px;"></span>
                        <span class="abo-admin-campaigns__skeleton-cell" style="width: 110px; height: 14px;"></span>
                        <span class="abo-admin-campaigns__skeleton-cell" style="width: 70px; height: 14px;"></span>
                        <span class="abo-admin-campaigns__skeleton-cell" style="width: 90px; height: 14px;"></span>
                    </div>
                @endfor
            </div>
        </div>
    </article>

    {{-- ─── Pagination footer (static, styled) ─────────────────── --}}
    <nav class="abo-admin-campaigns__pagination" aria-label="Pagination"
         data-abo-admin-campaigns-pagination>
        <p class="abo-admin-campaigns__pagination-info">
            <span>1 to 8 of 8 campaigns</span>
        </p>
        <ul class="abo-admin-campaigns__pagination-list">
            <li>
                <button type="button" class="abo-admin-campaigns__page-btn" disabled
                        aria-label="Previous page" data-abo-admin-campaigns-page="prev">‹</button>
            </li>
            <li>
                <button type="button" class="abo-admin-campaigns__page-btn is-active"
                        aria-current="page" aria-label="Page 1">1</button>
            </li>
            <li>
                <button type="button" class="abo-admin-campaigns__page-btn"
                        aria-label="Next page" data-abo-admin-campaigns-page="next">›</button>
            </li>
        </ul>
    </nav>

    {{-- ─── New / edit slide-over ──────────────────────────────── --}}
    <div class="abo-admin-campaigns__slideover-overlay"
         hidden
         data-abo-admin-campaigns-slideover-overlay></div>

    <aside class="abo-admin-campaigns__slideover"
           role="dialog"
           aria-modal="true"
           aria-labelledby="abo-admin-campaigns-slideover-title"
           hidden
           data-abo-admin-campaigns-slideover>
        <header class="abo-admin-campaigns__slideover-head">
            <div>
                <p class="abo-admin-campaigns__slideover-eyebrow">NEW CAMPAIGN</p>
                <h2 class="abo-admin-campaigns__slideover-title"
                    id="abo-admin-campaigns-slideover-title"
                    data-abo-admin-campaigns-slideover-title>Untitled campaign</h2>
            </div>
            <button type="button"
                    class="abo-admin-campaigns__slideover-close"
                    aria-label="Close panel"
                    data-abo-admin-campaigns-slideover-close>
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M6 6l12 12M18 6L6 18"
                          stroke="currentColor" stroke-width="1.6" fill="none" stroke-linecap="round"/>
                </svg>
            </button>
        </header>

        <ol class="abo-admin-campaigns__progress" role="list" aria-label="Steps"
            data-abo-admin-campaigns-progress>
            <li class="is-active" data-abo-admin-campaigns-step="1"><span>Content</span></li>
            <li data-abo-admin-campaigns-step="2"><span>Audience</span></li>
            <li data-abo-admin-campaigns-step="3"><span>Schedule</span></li>
            <li data-abo-admin-campaigns-step="4"><span>Review</span></li>
        </ol>

        <div class="abo-admin-campaigns__slideover-body">
            {{-- Step 1: Content --}}
            <section class="abo-admin-campaigns__pane is-active"
                     data-abo-admin-campaigns-pane="1" aria-label="Content">
                <div class="abo-admin-campaigns__field">
                    <label for="abo-admin-campaigns-name">Campaign name</label>
                    <input id="abo-admin-campaigns-name"
                           type="text"
                           placeholder="e.g. Winter drop"
                           autocomplete="off"
                           maxlength="80"
                           data-abo-admin-campaigns-field="name">
                </div>

                <div class="abo-admin-campaigns__field">
                    <label for="abo-admin-campaigns-type-field">Type</label>
                    <select id="abo-admin-campaigns-type-field" class="abo-admin-campaigns__select"
                            data-abo-admin-campaigns-field="type">
                        <option value="push">Push notification</option>
                        <option value="email">Email</option>
                        <option value="sms">SMS</option>
                    </select>
                </div>

                <div class="abo-admin-campaigns__lang" role="tablist" aria-label="Language">
                    <button type="button" role="tab" aria-selected="true"
                            class="is-active"
                            data-abo-admin-campaigns-lang="en">EN</button>
                    <button type="button" role="tab" aria-selected="false"
                            data-abo-admin-campaigns-lang="ar">AR</button>
                </div>

                <div class="abo-admin-campaigns__field">
                    <label for="abo-admin-campaigns-title">Title</label>
                    <input id="abo-admin-campaigns-title"
                           type="text"
                           placeholder="Cozy is here."
                           autocomplete="off"
                           maxlength="60"
                           data-abo-admin-campaigns-field="title">
                </div>

                <div class="abo-admin-campaigns__field">
                    <label for="abo-admin-campaigns-message">Message</label>
                    <textarea id="abo-admin-campaigns-message"
                              rows="3"
                              maxlength="160"
                              placeholder="Get ready for cozy season. Up to 30% off winter essentials."
                              data-abo-admin-campaigns-field="message"></textarea>
                    <p class="abo-admin-campaigns__field-counter" aria-live="polite">
                        <span data-abo-admin-campaigns-counter>0</span> / 160
                    </p>
                </div>

                <div class="abo-admin-campaigns__field">
                    <label for="abo-admin-campaigns-image">Image (optional)</label>
                    <input id="abo-admin-campaigns-image"
                           type="file"
                           accept="image/*"
                           data-abo-admin-campaigns-field="image">
                    <div class="abo-admin-campaigns__image-preview" hidden
                         data-abo-admin-campaigns-image-preview>
                        <img alt="" data-abo-admin-campaigns-image-thumb>
                        <button type="button"
                                class="abo-admin-campaigns__image-remove"
                                aria-label="Remove image"
                                data-abo-admin-campaigns-image-remove>×</button>
                    </div>
                </div>

                <div class="abo-admin-campaigns__field">
                    <label for="abo-admin-campaigns-deeplink">Deep link</label>
                    <select id="abo-admin-campaigns-deeplink" class="abo-admin-campaigns__select"
                            data-abo-admin-campaigns-field="deeplink">
                        <option value="">Shop home</option>
                        <option value="category:hoodies">Category: Hoodies</option>
                        <option value="category:jackets">Category: Jackets</option>
                        <option value="product:oversize-hoodie">Product: Oversize hoodie</option>
                        <option value="coupon:winter20">Coupon: WINTER20</option>
                    </select>
                </div>

                <div class="abo-admin-campaigns__field">
                    <label for="abo-admin-campaigns-coupon">Attach coupon (optional)</label>
                    <select id="abo-admin-campaigns-coupon" class="abo-admin-campaigns__select"
                            data-abo-admin-campaigns-field="coupon">
                        <option value="">No coupon</option>
                        <option value="winter20">WINTER20 — 20% off</option>
                        <option value="summer15">SUMMER15 — 15% off</option>
                        <option value="vip30">VIP30 — 30% off (VIP only)</option>
                    </select>
                </div>

                {{-- Live phone preview (updates while typing in step 1) --}}
                <div class="abo-admin-campaigns__phone-preview" aria-live="polite"
                     aria-label="Live notification preview">
                    <p class="abo-admin-campaigns__phone-label">PREVIEW</p>
                    <div class="abo-admin-campaigns__phone">
                        <div class="abo-admin-campaigns__phone-bar">
                            <span class="abo-admin-campaigns__phone-time">9:41</span>
                            <span class="abo-admin-campaigns__phone-dots" aria-hidden="true">•••</span>
                        </div>
                        <div class="abo-admin-campaigns__phone-notif" data-abo-admin-campaigns-phone-notif>
                            <div class="abo-admin-campaigns__phone-notif-head">
                                <span class="abo-admin-campaigns__phone-icon" aria-hidden="true">AB</span>
                                <span class="abo-admin-campaigns__phone-app">ABO</span>
                                <span class="abo-admin-campaigns__phone-now">now</span>
                            </div>
                            <p class="abo-admin-campaigns__phone-title"
                               data-abo-admin-campaigns-phone-title>Title</p>
                            <p class="abo-admin-campaigns__phone-body"
                               data-abo-admin-campaigns-phone-body>Your message will appear here.</p>
                            <div class="abo-admin-campaigns__phone-thumb" hidden
                                 data-abo-admin-campaigns-phone-thumb></div>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Step 2: Audience. The cards are driven by App\Enums\CampaignAudience
                 so the picker can only ever offer a segment the backend knows how
                 to resolve; $audienceCounts carries the live size of each one
                 (Admin\CampaignController::index), which campaigns.js refreshes
                 through admin.campaigns.audienceCount when the data changes. --}}
            <section class="abo-admin-campaigns__pane"
                     data-abo-admin-campaigns-pane="2" aria-label="Audience" hidden>
                <div class="abo-admin-campaigns__radio-cards" role="radiogroup"
                     aria-label="Audience"
                     data-abo-admin-campaigns-audience>
                    @foreach (\App\Enums\CampaignAudience::cases() as $audience)
                        @php($audienceReach = number_format($audienceCounts[$audience->value] ?? 0))
                        <label class="abo-admin-campaigns__radio-card">
                            <input type="radio" name="audience" value="{{ $audience->value }}" @checked($loop->first)>
                            <span class="abo-admin-campaigns__radio-card-title">{{ $audience->label() }}</span>
                            <span class="abo-admin-campaigns__radio-card-desc">{{ $audience->description() }}</span>
                            <span class="abo-admin-campaigns__radio-card-reach" data-reach="{{ $audienceReach }}">~{{ $audienceReach }} customers</span>
                        </label>
                    @endforeach
                </div>

                <p class="abo-admin-campaigns__reach" aria-live="polite">
                    Estimated reach:
                    <strong data-abo-admin-campaigns-reach>{{ number_format($audienceCounts[\App\Enums\CampaignAudience::All->value] ?? 0) }}</strong>
                    customers
                </p>
            </section>

            {{-- Step 3: Schedule --}}
            <section class="abo-admin-campaigns__pane"
                     data-abo-admin-campaigns-pane="3" aria-label="Schedule" hidden>
                <div class="abo-admin-campaigns__field">
                    <label>When to send</label>
                    <div class="abo-admin-campaigns__radio-inline" role="radiogroup"
                         aria-label="Send time"
                         data-abo-admin-campaigns-when>
                        <label class="abo-admin-campaigns__radio-inline-item is-active">
                            <input type="radio" name="when" value="now" checked>
                            Send now
                        </label>
                        <label class="abo-admin-campaigns__radio-inline-item">
                            <input type="radio" name="when" value="later">
                            Schedule
                        </label>
                    </div>
                </div>

                <div class="abo-admin-campaigns__schedule-fields" hidden
                     data-abo-admin-campaigns-schedule-fields>
                    <div class="abo-admin-campaigns__field">
                        <label for="abo-admin-campaigns-date">Date</label>
                        <input id="abo-admin-campaigns-date"
                               type="date"
                               data-abo-admin-campaigns-field="date">
                    </div>
                    <div class="abo-admin-campaigns__field">
                        <label for="abo-admin-campaigns-time">Time</label>
                        <input id="abo-admin-campaigns-time"
                               type="time"
                               value="10:00"
                               data-abo-admin-campaigns-field="time">
                    </div>
                </div>

                <div class="abo-admin-campaigns__field">
                    <label for="abo-admin-campaigns-repeat">Repeat</label>
                    <select id="abo-admin-campaigns-repeat" class="abo-admin-campaigns__select"
                            data-abo-admin-campaigns-field="repeat">
                        <option value="none">None</option>
                        <option value="weekly">Weekly</option>
                    </select>
                </div>
            </section>

            {{-- Step 4: Review --}}
            <section class="abo-admin-campaigns__pane"
                     data-abo-admin-campaigns-pane="4" aria-label="Review" hidden>
                <dl class="abo-admin-campaigns__summary" data-abo-admin-campaigns-summary>
                    <div class="abo-admin-campaigns__summary-row">
                        <dt>Name</dt>
                        <dd data-abo-admin-campaigns-sum="name">—</dd>
                    </div>
                    <div class="abo-admin-campaigns__summary-row">
                        <dt>Type</dt>
                        <dd data-abo-admin-campaigns-sum="type">—</dd>
                    </div>
                    <div class="abo-admin-campaigns__summary-row">
                        <dt>Title</dt>
                        <dd data-abo-admin-campaigns-sum="title">—</dd>
                    </div>
                    <div class="abo-admin-campaigns__summary-row">
                        <dt>Message</dt>
                        <dd data-abo-admin-campaigns-sum="message">—</dd>
                    </div>
                    <div class="abo-admin-campaigns__summary-row">
                        <dt>Deep link</dt>
                        <dd data-abo-admin-campaigns-sum="deeplink">—</dd>
                    </div>
                    <div class="abo-admin-campaigns__summary-row">
                        <dt>Coupon</dt>
                        <dd data-abo-admin-campaigns-sum="coupon">—</dd>
                    </div>
                    <div class="abo-admin-campaigns__summary-row">
                        <dt>Audience</dt>
                        <dd data-abo-admin-campaigns-sum="audience">—</dd>
                    </div>
                    <div class="abo-admin-campaigns__summary-row">
                        <dt>Reach</dt>
                        <dd data-abo-admin-campaigns-sum="reach">—</dd>
                    </div>
                    <div class="abo-admin-campaigns__summary-row">
                        <dt>Send</dt>
                        <dd data-abo-admin-campaigns-sum="when">—</dd>
                    </div>
                </dl>
            </section>
        </div>

        <footer class="abo-admin-campaigns__slideover-foot">
            <button type="button" class="abo-admin-btn abo-admin-btn--ghost"
                    data-abo-admin-campaigns-slideover-back
                    hidden
                    data-abo-admin-campaigns-back-btn>BACK</button>
            <button type="button" class="abo-admin-btn abo-admin-btn--ghost"
                    data-abo-admin-campaigns-slideover-next
                    data-abo-admin-campaigns-next-btn>NEXT</button>
            <button type="button" class="abo-admin-btn abo-admin-btn--ghost"
                    data-abo-admin-campaigns-slideover-draft
                    data-abo-admin-campaigns-draft-btn>SAVE AS DRAFT</button>
            <button type="button" class="abo-admin-btn abo-admin-btn--primary"
                    data-abo-admin-campaigns-slideover-send
                    hidden
                    data-abo-admin-campaigns-send-btn>
                <span data-abo-admin-campaigns-send-label>SEND / SCHEDULE</span>
            </button>
        </footer>
    </aside>

    {{-- ─── Single shared modal + toast containers ──────────────── --}}
    <div class="abo-admin-campaigns__modal" hidden
         role="dialog"
         aria-modal="true"
         aria-labelledby="abo-admin-campaigns-modal-title"
         data-abo-admin-campaigns-modal>
        <div class="abo-admin-campaigns__modal-backdrop" data-abo-admin-campaigns-modal-close></div>
        <div class="abo-admin-campaigns__modal-card">
            <h2 class="abo-admin-campaigns__modal-title" id="abo-admin-campaigns-modal-title">CONFIRM</h2>
            <p class="abo-admin-campaigns__modal-body"
               data-abo-admin-campaigns-modal-body>Are you sure?</p>
            <div class="abo-admin-campaigns__modal-actions">
                <button type="button" class="abo-admin-btn abo-admin-btn--ghost"
                        data-abo-admin-campaigns-modal-close>CANCEL</button>
                <button type="button" class="abo-admin-campaigns__modal-confirm"
                        data-abo-admin-campaigns-modal-confirm>CONFIRM</button>
            </div>
        </div>
    </div>

    <div class="abo-admin-campaigns__toast" role="status" aria-live="polite" hidden
         data-abo-admin-campaigns-toast>
        <span class="abo-admin-campaigns__toast-dot" aria-hidden="true"></span>
        <span class="abo-admin-campaigns__toast-text" data-abo-admin-campaigns-toast-text>Saved (demo)</span>
    </div>
@endsection

@push('head')
    {{-- Page-local stylesheet. Loaded only on /admin/campaigns. --}}
    <link rel="stylesheet" href="{{ asset('admin/css/campaigns.css') }}">
@endpush

@push('scripts')
    {{-- Live DB payload prepared by Admin\CampaignController::index
         via App\Services\Admin\CampaignService. campaigns.js reads
         window.CAMPAIGNS_DATA as the source of truth instead of the
         old static fixture it used to ship with. CSRF + named routes
         are exposed here so the slide-over can POST/PUT/DELETE through
         the Laravel API instead of "demo toasts". --}}
    <script>
        window.CAMPAIGNS_DATA = {!! $campaignsJson !!};
        window.CAMPAIGNS_API = {
            store: @json(route('admin.campaigns.store')),
            update: @json(route('admin.campaigns.update', ['campaign' => '__ID__'])),
            destroy: @json(route('admin.campaigns.destroy', ['campaign' => '__ID__'])),
            duplicate: @json(route('admin.campaigns.duplicate', ['campaign' => '__ID__'])),
            sendNow: @json(route('admin.campaigns.sendNow', ['campaign' => '__ID__'])),
            audienceCount: @json(route('admin.campaigns.audienceCount', ['audience' => '__AUDIENCE__'])),
            csrf: @json(csrf_token()),
        };
    </script>

    {{-- Page-local script. Loaded only on /admin/campaigns. --}}
    <script src="{{ asset('admin/js/campaigns.js') }}" defer></script>
@endpush