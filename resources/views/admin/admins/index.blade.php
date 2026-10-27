{{--
    Admin · Admins list — wired to the database.

    The page renders the accounts that are really there: the list, the
    roles and what each role reaches, and the trail of what the team
    has changed. Everything the page offers is a real action against a
    real endpoint:

      - the list       → Admin\AdminController::index
      - add an admin  → POST   /admin/admins
      - edit an admin → PUT    /admin/admins/{admin}
      - suspend       → PATCH  /admin/admins/{admin}/toggle
      - remove        → DELETE /admin/admins/{admin}
      - reset link    → POST   /admin/password/email

    The role map is not editable here. It is defined once, on the
    server, in App\Enums\AdminRole, and the table below is a picture of
    it — so the rules the page shows are the rules the server enforces.

    Page sections (top to bottom):
      1. Page header     — SYSTEM eyebrow, "AD" + "MINS" title,
                           + ADD ADMIN button on the right
      2. Main tabs       — "Admins (n)" / "Roles"
      3. Tab 1 — toolbar — search, Role, Status, Clear filters
      4. Tab 1 — table   — Admin, Role, Status, Last login, Edit
                           Mobile: stacked cards (CSS swap)
      5. Tab 2 — roles   — the module map, read-only
      6. Slide-over      — Edit admin: profile, role, status,
                           recent activity, save / remove
      7. Add modal       — Full name, Email, Role, Password
      8. Remove modal    — "lose access immediately" copy
      9. Toast           — single shared instance

    Reuses the brand tokens (--abo-lime, --abo-bg,
    --abo-admin-panel/border/text/coral/gray), the page-header /
    eyebrow / title classes, .abo-admin-card, .abo-admin-btn,
    .abo-admin-empty and .sr-only. The page-local stylesheet and
    script are loaded only on this page via @stack('head') and
    @stack('scripts'). All admins-specific classes are prefixed
    `abo-admin-admins-`.
--}}

@extends('admin.layouts.app')

@section('title', 'Admins · System')
@section('description', 'ABO — admin accounts: create, edit, suspend and remove, with the role each one holds.')

@php
    // The two states an account can be in. An account is created active;
    // "suspended" is the opposite of that.
    $statuses = [
        'active'    => 'Active',
        'suspended' => 'Suspended',
    ];

    $rolesJson            = json_encode($roles, JSON_UNESCAPED_UNICODE);
    $statusesJson         = json_encode($statuses, JSON_UNESCAPED_UNICODE);
    $adminsJson           = json_encode($admins, JSON_UNESCAPED_UNICODE);
    $adminActivityJson    = json_encode($activity, JSON_UNESCAPED_UNICODE);
    $moduleLabelsJson     = json_encode($moduleLabels, JSON_UNESCAPED_UNICODE);
    $permissionMatrixJson = json_encode($permissions, JSON_UNESCAPED_UNICODE);
    $routesJson           = json_encode($routes, JSON_UNESCAPED_UNICODE);
@endphp

@section('content')
    {{-- ─── Page header ────────────────────────────────────────── --}}
    <header class="abo-admin-page__head">
        <div class="abo-admin-page__head-text">
            <p class="abo-admin-page__eyebrow">
                <span class="abo-admin-page__eyebrow-line" aria-hidden="true"></span>
                <span>SYSTEM</span>
            </p>
            <h1 class="abo-admin-page__title">
                <span>AD</span><span class="abo-admin-admins__title-lime">MINS</span>
            </h1>
        </div>

        <div class="abo-admin-page__head-actions">
            <button type="button" class="abo-admin-btn abo-admin-btn--primary abo-admin-admins__invite-btn"
                    data-abo-admin-admins-action="invite">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.8"
                          fill="none" stroke-linecap="round"/>
                </svg>
                ADD ADMIN
            </button>
        </div>
    </header>

    {{-- ─── Main tabs (Admins / Roles) ─────────────────────────── --}}
    <nav class="abo-admin-admins__main-tabs"
         role="tablist"
         aria-label="Admin sections"
         data-abo-admin-admins-main-tabs>
        <button type="button" role="tab" aria-selected="true"  data-abo-admin-admins-main-tab="list"  class="is-active">
            Admins <span data-abo-admin-admins-main-tab-count="list">0</span>
        </button>
        <button type="button" role="tab" aria-selected="false" data-abo-admin-admins-main-tab="roles">
            Roles
        </button>
    </nav>

    {{-- ─── Tab 1: Admins ──────────────────────────────────────── --}}
    <section data-abo-admin-admins-pane="list">

        {{-- Toolbar --}}
        <div class="abo-admin-card abo-admin-admins__toolbar" aria-label="Filter admins">
            <div class="abo-admin-admins__filter-search">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" class="abo-admin-admins__filter-icon">
                    <path d="M21 20.3 16.7 16a8 8 0 1 0-1.4 1.4L20.3 21 21 20.3zM4 10a6 6 0 1 1 12 0 6 6 0 0 1-12 0z"
                          fill="currentColor"/>
                </svg>
                <label class="sr-only" for="abo-admin-admins-search">Search admins</label>
                <input id="abo-admin-admins-search"
                       type="search"
                       placeholder="Search by name or email…"
                       autocomplete="off"
                       data-abo-admin-admins-search>
            </div>

            <label class="sr-only" for="abo-admin-admins-filter-role">Role</label>
            <select id="abo-admin-admins-filter-role" class="abo-admin-admins__select"
                    data-abo-admin-admins-filter="role">
                <option value="">All roles</option>
                @foreach ($roles as $key => $meta)
                    <option value="{{ $key }}">{{ $meta['label'] }}</option>
                @endforeach
            </select>

            <label class="sr-only" for="abo-admin-admins-filter-status">Status</label>
            <select id="abo-admin-admins-filter-status" class="abo-admin-admins__select"
                    data-abo-admin-admins-filter="status">
                <option value="">All statuses</option>
                @foreach ($statuses as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>

            <button type="button" class="abo-admin-admins__clear" hidden
                    data-abo-admin-admins-clear>
                Clear filters
            </button>
        </div>

        {{-- Admins table --}}
        <article class="abo-admin-card abo-admin-admins__table-card" aria-label="Admins">
            <p class="sr-only" aria-live="polite" data-abo-admin-admins-result-count>0 admins</p>

            <div class="abo-admin-admins__wrap abo-admin-admins__table-wrap">
                <table class="abo-admin-admins__table">
                    <caption class="sr-only">Admins list — every account in the back office.</caption>
                    <thead>
                        <tr>
                            <th scope="col">Admin</th>
                            <th scope="col">Role</th>
                            <th scope="col">Status</th>
                            <th scope="col">Last login</th>
                            <th scope="col" class="abo-admin-admins__th-actions"><span class="sr-only">Edit</span></th>
                        </tr>
                    </thead>
                    <tbody data-abo-admin-admins-tbody>
                        {{-- Rows + mobile cards are both injected by
                             public/admin/js/admins.js from the JSON
                             dataset below. No server-side rows here
                             by design — every cell is generated in JS. --}}
                    </tbody>
                </table>

                <ul class="abo-admin-admins__cards" data-abo-admin-admins-cards></ul>
            </div>

            <div class="abo-admin-admins__state" data-abo-admin-admins-state="noresults" hidden>
                <p class="abo-admin-empty">No results. Try clearing the filters above.</p>
                <button type="button" class="abo-admin-btn abo-admin-btn--ghost"
                        data-abo-admin-admins-clear>
                    CLEAR FILTERS
                </button>
            </div>
            <div class="abo-admin-admins__state" data-abo-admin-admins-state="loading" hidden>
                <div class="abo-admin-admins__skeleton" aria-hidden="true">
                    @for ($i = 0; $i < 6; $i++)
                        <div class="abo-admin-admins__skeleton-row">
                            <span class="abo-admin-admins__skeleton-cell" style="width: 32px; height: 32px;"></span>
                            <span class="abo-admin-admins__skeleton-cell" style="width: 60%; height: 14px;"></span>
                            <span class="abo-admin-admins__skeleton-cell" style="width: 90px; height: 14px;"></span>
                            <span class="abo-admin-admins__skeleton-cell" style="width: 80px; height: 18px;"></span>
                            <span class="abo-admin-admins__skeleton-cell" style="width: 60%; height: 14px;"></span>
                            <span class="abo-admin-admins__skeleton-cell" style="width: 30px; height: 30px;"></span>
                        </div>
                    @endfor
                </div>
            </div>
        </article>
    </section>

    {{-- ─── Tab 2: Roles ────────────────────────────────────────── --}}
    {{-- The map below is the one the server enforces. It is drawn
         from App\Enums\AdminRole, so it cannot drift away from the
         rules the middleware applies. --}}
    <section data-abo-admin-admins-pane="roles" hidden>
        <article class="abo-admin-card abo-admin-admins__matrix-card" aria-label="Roles and their modules">
            <header class="abo-admin-card__head">
                <h2 class="abo-admin-card__title">What each role reaches</h2>
            </header>

            <p class="abo-admin-card__soft">
                These are the rules the server applies on every request — a read
                needs <strong>View</strong>, a change needs <strong>Manage</strong>.
            </p>

            <div class="abo-admin-admins__matrix-wrap">
                <table class="abo-admin-admins__matrix">
                    <caption class="sr-only">Roles and the modules they reach.</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="abo-admin-admins__matrix-th-module">Module</th>
                            @foreach ($roles as $rKey => $rMeta)
                                <th scope="col" class="abo-admin-admins__matrix-th-role">
                                    {{ $rMeta['label'] }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($modules as $module)
                            <tr>
                                <th scope="row" class="abo-admin-admins__matrix-module">
                                    {{ $moduleLabels[$module] ?? ucfirst(str_replace('_', ' ', $module)) }}
                                </th>
                                @foreach ($roles as $rKey => $rMeta)
                                    @php
                                        $level = $permissions[$rKey][$module] ?? 0;
                                    @endphp
                                    <td>
                                        <span class="abo-admin-admins__chip abo-admin-admins__chip--{{ $level === 2 ? 'manage' : ($level === 1 ? 'view' : 'none') }}">
                                            <span>{{ $level === 2 ? 'Manage' : ($level === 1 ? 'View' : 'No access') }}</span>
                                        </span>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="abo-admin-admins__matrix-note">
                Only an owner may manage the back office itself — creating,
                editing, suspending and removing an account.
            </p>
        </article>
    </section>

    {{-- ─── Edit admin slide-over ──────────────────────────────── --}}
    <div class="abo-admin-admins__drawer"
         role="dialog"
         aria-modal="true"
         aria-labelledby="abo-admin-admins-drawer-title"
         hidden
         data-abo-admin-admins-drawer>
        <div class="abo-admin-admins__drawer-overlay" data-abo-admin-admins-drawer-close></div>
        <aside class="abo-admin-admins__drawer-panel" tabindex="-1">
            <header class="abo-admin-admins__drawer-head">
                <div class="abo-admin-admins__drawer-head-text">
                    <p class="abo-admin-admins__drawer-eyebrow">
                        <span>EDIT ADMIN</span>
                        <span class="abo-admin-admins__dirty"
                              data-abo-admin-admins-drawer-dirty
                              hidden aria-label="Unsaved changes"></span>
                    </p>
                    <h2 class="abo-admin-admins__drawer-title" id="abo-admin-admins-drawer-title"
                        data-abo-admin-admins-drawer-name>—</h2>
                </div>
                <button type="button"
                        class="abo-admin-admins__drawer-close"
                        aria-label="Close admin details"
                        data-abo-admin-admins-drawer-close>
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M6 6l12 12M18 6 6 18"
                              stroke="currentColor" stroke-width="1.6"
                              fill="none" stroke-linecap="round"/>
                    </svg>
                </button>
            </header>

            <div class="abo-admin-admins__drawer-body">

                {{-- Profile --}}
                <section class="abo-admin-admins__block" aria-label="Profile">
                    <div class="abo-admin-admins__profile-row">
                        <span class="abo-admin-admins__avatar" data-abo-admin-admins-drawer-avatar>—</span>
                        <div class="abo-admin-admins__profile-meta">
                            <h3 class="abo-admin-admins__profile-name" data-abo-admin-admins-drawer-name-2>—</h3>
                            <p class="abo-admin-admins__profile-email" data-abo-admin-admins-drawer-email>—</p>
                            <p class="abo-admin-admins__profile-you" data-abo-admin-admins-drawer-you hidden>
                                <span class="abo-admin-admins__you-pill">YOU</span>
                                <span class="abo-admin-admins__you-note">Signed-in admin</span>
                            </p>
                        </div>
                    </div>

                    <div class="abo-admin-admins__field">
                        <label for="abo-admin-admins-drawer-fullname">Full name</label>
                        <input id="abo-admin-admins-drawer-fullname"
                               type="text"
                               autocomplete="name"
                               data-abo-admin-admins-drawer-fullname>
                    </div>

                    <div class="abo-admin-admins__field">
                        <label for="abo-admin-admins-drawer-email-input">Email</label>
                        <input id="abo-admin-admins-drawer-email-input"
                               type="email"
                               autocomplete="email"
                               data-abo-admin-admins-drawer-email-input>
                    </div>

                    <div class="abo-admin-admins__field">
                        <label for="abo-admin-admins-drawer-role">Role</label>
                        <select id="abo-admin-admins-drawer-role"
                                data-abo-admin-admins-drawer-role>
                            @foreach ($roles as $rKey => $rMeta)
                                <option value="{{ $rKey }}">{{ $rMeta['label'] }}</option>
                            @endforeach
                        </select>
                        <p class="abo-admin-admins__field-note"
                           data-abo-admin-admins-drawer-role-desc>—</p>
                    </div>

                    <div class="abo-admin-admins__chips" aria-label="Permissions for the selected role">
                        <p class="abo-admin-admins__chips-label">WHAT THIS ROLE CAN DO</p>
                        <ul class="abo-admin-admins__chip-list" data-abo-admin-admins-drawer-chips></ul>
                    </div>
                </section>

                {{-- Status --}}
                <section class="abo-admin-admins__block" aria-label="Status">
                    <div class="abo-admin-admins__switch-row">
                        <div>
                            <p class="abo-admin-admins__switch-label">Active</p>
                            <p class="abo-admin-admins__switch-note">
                                Off means <strong>Suspended</strong>. A suspended admin can't sign in.
                            </p>
                        </div>
                        <label class="abo-admin-admins__switch">
                            <input type="checkbox"
                                   data-abo-admin-admins-drawer-active>
                            <span class="abo-admin-admins__switch-track" aria-hidden="true">
                                <span class="abo-admin-admins__switch-thumb"></span>
                            </span>
                        </label>
                    </div>
                    <p class="abo-admin-admins__field-note abo-admin-admins__field-note--warn"
                       data-abo-admin-admins-drawer-status-note hidden></p>
                </section>

                {{-- Password --}}
                <section class="abo-admin-admins__block" aria-label="Password">
                    <h3 class="abo-admin-admins__block-label">PASSWORD</h3>
                    <p class="abo-admin-admins__field-note">
                        Send a reset link to this account. The link lets them choose
                        a new password; it does not change it on its own.
                    </p>
                    <div class="abo-admin-admins__security-actions">
                        <button type="button" class="abo-admin-admins__security-btn"
                                data-abo-admin-admins-drawer-reset-pwd>
                            Send password reset link
                        </button>
                    </div>
                </section>

                {{-- Recent activity --}}
                <section class="abo-admin-admins__block" aria-label="Recent activity">
                    <h3 class="abo-admin-admins__block-label">RECENT ACTIVITY</h3>
                    <ul class="abo-admin-admins__activity" data-abo-admin-admins-drawer-activity></ul>
                    <p class="abo-admin-admins__activity-empty"
                       data-abo-admin-admins-drawer-activity-empty hidden>
                        No activity yet.
                    </p>
                </section>
            </div>

            <footer class="abo-admin-admins__drawer-foot">
                <button type="button" class="abo-admin-admins__remove-btn"
                        data-abo-admin-admins-drawer-remove>
                    Remove admin
                </button>
                <button type="button" class="abo-admin-btn abo-admin-btn--primary abo-admin-admins__save-btn"
                        data-abo-admin-admins-drawer-save>
                    SAVE
                </button>
            </footer>
        </aside>
    </div>

    {{-- ─── Add admin modal ────────────────────────────────────── --}}
    <div class="abo-admin-admins__modal"
         role="dialog"
         aria-modal="true"
         aria-labelledby="abo-admin-admins-invite-title"
         hidden
         data-abo-admin-admins-invite>
        <div class="abo-admin-admins__modal-backdrop" data-abo-admin-admins-invite-close></div>
        <div class="abo-admin-admins__modal-card">
            <header class="abo-admin-admins__modal-head">
                <h2 class="abo-admin-admins__modal-title" id="abo-admin-admins-invite-title">ADD ADMIN</h2>
                <button type="button" class="abo-admin-admins__drawer-close"
                        aria-label="Close"
                        data-abo-admin-admins-invite-close>
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="M6 6l12 12M18 6 6 18"
                              stroke="currentColor" stroke-width="1.6"
                              fill="none" stroke-linecap="round"/>
                    </svg>
                </button>
            </header>

            <div class="abo-admin-admins__modal-body">
                <div class="abo-admin-admins__field">
                    <label for="abo-admin-admins-invite-name">Full name</label>
                    <input id="abo-admin-admins-invite-name"
                           type="text"
                           autocomplete="name"
                           data-abo-admin-admins-invite-name>
                </div>

                <div class="abo-admin-admins__field">
                    <label for="abo-admin-admins-invite-email">Email</label>
                    <input id="abo-admin-admins-invite-email"
                           type="email"
                           autocomplete="email"
                           data-abo-admin-admins-invite-email
                           aria-describedby="abo-admin-admins-invite-email-err">
                    <p class="abo-admin-admins__field-note abo-admin-admins__field-note--coral"
                       id="abo-admin-admins-invite-email-err"
                       data-abo-admin-admins-invite-email-err
                       hidden>Enter a valid email</p>
                </div>

                <div class="abo-admin-admins__field">
                    <label for="abo-admin-admins-invite-role">Role</label>
                    <select id="abo-admin-admins-invite-role"
                            data-abo-admin-admins-invite-role>
                        @foreach ($roles as $rKey => $rMeta)
                            <option value="{{ $rKey }}">{{ $rMeta['label'] }}</option>
                        @endforeach
                    </select>
                    <p class="abo-admin-admins__field-note"
                       data-abo-admin-admins-invite-role-desc>—</p>
                </div>

                <div class="abo-admin-admins__field">
                    <label for="abo-admin-admins-invite-password">Password</label>
                    <input id="abo-admin-admins-invite-password"
                           type="password"
                           autocomplete="new-password"
                           data-abo-admin-admins-invite-password>
                    <p class="abo-admin-admins__field-note">At least 8 characters.</p>
                </div>

                <div class="abo-admin-admins__field">
                    <label for="abo-admin-admins-invite-password-confirm">Confirm password</label>
                    <input id="abo-admin-admins-invite-password-confirm"
                           type="password"
                           autocomplete="new-password"
                           data-abo-admin-admins-invite-password-confirm>
                </div>
            </div>

            <footer class="abo-admin-admins__modal-foot">
                <button type="button" class="abo-admin-btn abo-admin-btn--ghost"
                        data-abo-admin-admins-invite-close>CANCEL</button>
                <button type="button" class="abo-admin-btn abo-admin-btn--primary"
                        data-abo-admin-admins-invite-send>ADD ADMIN</button>
            </footer>
        </div>
    </div>

    {{-- ─── Remove admin confirmation modal ────────────────────── --}}
    <div class="abo-admin-admins__modal"
         role="dialog"
         aria-modal="true"
         aria-labelledby="abo-admin-admins-remove-title"
         hidden
         data-abo-admin-admins-remove>
        <div class="abo-admin-admins__modal-backdrop" data-abo-admin-admins-remove-close></div>
        <div class="abo-admin-admins__modal-card">
            <h2 class="abo-admin-admins__modal-title" id="abo-admin-admins-remove-title">REMOVE ADMIN?</h2>
            <p class="abo-admin-admins__modal-body" data-abo-admin-admins-remove-body>
                This admin will lose access immediately. Their past activity stays in the log.
            </p>
            <footer class="abo-admin-admins__modal-foot">
                <button type="button" class="abo-admin-btn abo-admin-btn--ghost"
                        data-abo-admin-admins-remove-close>CANCEL</button>
                <button type="button" class="abo-admin-admins__modal-confirm"
                        data-abo-admin-admins-remove-confirm>REMOVE</button>
            </footer>
        </div>
    </div>

    {{-- ─── Single shared toast ───────────────────────────────── --}}
    <div class="abo-admin-admins__toast" role="status" aria-live="polite" hidden
         data-abo-admin-admins-toast>
        <span class="abo-admin-admins__toast-dot" aria-hidden="true"></span>
        <span data-abo-admin-admins-toast-text>Saved</span>
    </div>

    {{-- Datasets generated in PHP at the top of this view and
         consumed by public/admin/js/admins.js. --}}
    <script type="application/json" id="abo-admin-admins-data-roles">{!! $rolesJson !!}</script>
    <script type="application/json" id="abo-admin-admins-data-statuses">{!! $statusesJson !!}</script>
    <script type="application/json" id="abo-admin-admins-data-admins">{!! $adminsJson !!}</script>
    <script type="application/json" id="abo-admin-admins-data-activity">{!! $adminActivityJson !!}</script>
    <script type="application/json" id="abo-admin-admins-data-modules">{!! $moduleLabelsJson !!}</script>
    <script type="application/json" id="abo-admin-admins-data-matrix">{!! $permissionMatrixJson !!}</script>
    <script type="application/json" id="abo-admin-admins-data-routes">{!! $routesJson !!}</script>
@endsection

@push('head')
    {{-- Page-local stylesheet. Loaded only on the admins page. --}}
    <link rel="stylesheet" href="{{ asset('admin/css/admins.css') }}">
@endpush

@push('scripts')
    {{-- Page-local script. Loaded only on the admins page. --}}
    <script src="{{ asset('admin/js/admins.js') }}" defer></script>
@endpush
