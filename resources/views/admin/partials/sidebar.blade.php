{{--
    Admin sidebar partial.

    Sidebar items use a hash router (#/dashboard etc.) — this is
    pure front-end: no Laravel route is hit when the user clicks
    them. The "active" state is derived server-side from the
    current route name so the shell works without JS too.
--}}
@php
    // Each item: {num, label, hash, group}. Sections are split by
    // a section label so the sidebar reads top to bottom.
    // TODO(backend): replace with real menu config from the admin
    // permission system (e.g. spatie/laravel-permission).
    $menuSections = [
        'MAIN' => [
            ['num' => '01', 'label' => 'Overview',     'hash' => '#/dashboard'],
            ['num' => '02', 'label' => 'Orders',       'hash' => '#/orders'],
            ['num' => '03', 'label' => 'Products',     'hash' => '#/products'],
            ['num' => '04', 'label' => 'Customers',    'hash' => '#/customers'],
        ],
        'FULFILMENT' => [
            ['num' => '05', 'label' => 'Shipping',     'hash' => '#/shipping'],
            ['num' => '06', 'label' => 'Returns',      'hash' => '#/returns'],
            ['num' => '07', 'label' => 'Size guides',  'hash' => route('admin.size-guides.index')],
        ],
        'GROWTH' => [
            ['num' => '08', 'label' => 'Marketing',    'hash' => '#/marketing'],
            ['num' => '09', 'label' => 'Reports',      'hash' => '#/reports'],
        ],
        'SYSTEM' => [
            ['num' => '10', 'label' => 'Settings',     'hash' => '#/settings'],
        ],
    ];

    // Active page is the dashboard route by default. Other admin
    // pages would set this via a $activeNav variable.
    $activeHash = $activeHash ?? '#/dashboard';
@endphp

<aside class="abo-admin-sidebar" aria-label="Admin navigation">
    <a class="abo-admin-sidebar__brand" href="{{ route('admin.dashboard') }}" aria-label="ABO — Admin home">
        <img src="{{ asset('images/abo-logo.svg') }}" alt="ABO" width="36" height="36">
        <span class="abo-admin-sidebar__brand-text">
            <strong>ABO</strong>
            <em>Admin</em>
        </span>
    </a>

    <nav class="abo-admin-sidebar__nav">
        @foreach ($menuSections as $sectionLabel => $items)
            <p class="abo-admin-sidebar__section">{{ $sectionLabel }}</p>
            <ul>
                @foreach ($items as $item)
                    <li>
                        <a href="{{ $item['hash'] }}"
                           class="abo-admin-sidebar__link {{ $item['hash'] === $activeHash ? 'is-active' : '' }}"
                           aria-current="{{ $item['hash'] === $activeHash ? 'page' : 'false' }}">
                            <span class="abo-admin-sidebar__num" aria-hidden="true">{{ $item['num'] }}</span>
                            <span class="abo-admin-sidebar__label">{{ $item['label'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endforeach
    </nav>

    <div class="abo-admin-sidebar__foot">
        <a class="abo-admin-sidebar__link abo-admin-sidebar__link--quiet" href="#/docs">
            <span class="abo-admin-sidebar__num" aria-hidden="true">·</span>
            <span class="abo-admin-sidebar__label">Docs</span>
        </a>
    </div>
</aside>