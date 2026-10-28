{{--
    Admin sidebar partial.

    Sidebar items mapped directly to active Laravel routes.
    The "active" state is derived dynamically from the current route.
--}}
@php
    $menuSections = [
        'MAIN' => [
            ['num' => '01', 'label' => 'Overview',    'url' => route('admin.dashboard'),               'active' => request()->routeIs('admin.dashboard*')],
            ['num' => '02', 'label' => 'Orders',      'url' => route('admin.orders.index'),            'active' => request()->routeIs('admin.orders*')],
            ['num' => '03', 'label' => 'Products',    'url' => route('admin.products.index'),          'active' => request()->routeIs('admin.products*')],
            ['num' => '04', 'label' => 'Customers',   'url' => route('admin.customers.index'),         'active' => request()->routeIs('admin.customers*')],
        ],
        'CATALOG' => [
            ['num' => '05', 'label' => 'Categories',  'url' => route('admin.categories.index'),        'active' => request()->routeIs('admin.categories*')],
            ['num' => '06', 'label' => 'Colors & Sizes', 'url' => route('admin.colors-sizes.index'),   'active' => request()->routeIs('admin.colors-sizes*')],
            ['num' => '07', 'label' => 'Size guides', 'url' => route('admin.size-guides.index'),       'active' => request()->routeIs('admin.size-guides*')],
            ['num' => '08', 'label' => 'Banners',     'url' => route('admin.banners.index'),           'active' => request()->routeIs('admin.banners*')],
        ],
        'OPERATIONS' => [
            ['num' => '09', 'label' => 'Shipping',    'url' => route('admin.shipping.index'),          'active' => request()->routeIs('admin.shipping*')],
            ['num' => '10', 'label' => 'Pages',       'url' => route('admin.pages.index'),             'active' => request()->routeIs('admin.pages*')],
        ],
        'GROWTH' => [
            ['num' => '11', 'label' => 'Coupons',     'url' => route('admin.coupons.index'),           'active' => request()->routeIs('admin.coupons*')],
            ['num' => '12', 'label' => 'Campaigns',   'url' => route('admin.campaigns.index'),         'active' => request()->routeIs('admin.campaigns*')],
            ['num' => '13', 'label' => 'Reports',     'url' => route('admin.reports.index'),          'active' => request()->routeIs('admin.reports*')],
        ],
        'SYSTEM' => [
            ['num' => '14', 'label' => 'Admins',      'url' => route('admin.admins.index'),            'active' => request()->routeIs('admin.admins*')],
            ['num' => '15', 'label' => 'Settings',    'url' => route('admin.settings.index'),         'active' => request()->routeIs('admin.settings*')],
        ],
    ];
@endphp

<aside class="abo-admin-sidebar" aria-label="Admin navigation">
    <a class="abo-admin-sidebar__brand" href="{{ route('admin.dashboard') }}" aria-label="{{ \App\Models\StoreSetting::storeName() }} — Admin home">
        <img src="{{ \App\Models\StoreSetting::logoUrl() }}" alt="{{ \App\Models\StoreSetting::storeName() }}" width="36" height="36">
        <span class="abo-admin-sidebar__brand-text">
            <strong>{{ \App\Models\StoreSetting::storeName() }}</strong>
            <em>Admin</em>
        </span>
    </a>

    <nav class="abo-admin-sidebar__nav">
        @foreach ($menuSections as $sectionLabel => $items)
            <p class="abo-admin-sidebar__section">{{ $sectionLabel }}</p>
            <ul>
                @foreach ($items as $item)
                    <li>
                        <a href="{{ $item['url'] }}"
                           class="abo-admin-sidebar__link {{ $item['active'] ? 'is-active' : '' }}"
                           aria-current="{{ $item['active'] ? 'page' : 'false' }}">
                            <span class="abo-admin-sidebar__num" aria-hidden="true">{{ $item['num'] }}</span>
                            <span class="abo-admin-sidebar__label">{{ $item['label'] }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endforeach
    </nav>

    <div class="abo-admin-sidebar__foot">
        <form method="POST" action="{{ route('admin.logout') }}" style="display: block; width: 100%;">
            @csrf
            <button type="submit" class="abo-admin-sidebar__link abo-admin-sidebar__link--quiet" style="background: none; border: none; width: 100%; text-align: left; cursor: pointer; display: flex; align-items: center;">
                <span class="abo-admin-sidebar__num" aria-hidden="true">⏻</span>
                <span class="abo-admin-sidebar__label">Sign Out</span>
            </button>
        </form>
    </div>
</aside>
