<?php

use App\Enums\CampaignAudience;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AdminNotificationController;
use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\BannerController;
use App\Http\Controllers\Admin\CampaignController as AdminCampaignController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ColorSizeController;
use App\Http\Controllers\Admin\CouponController as AdminCouponController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\PageController as AdminPageController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ShippingController;
use App\Http\Controllers\Admin\SizeGuideController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\CampaignTrackingController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\Store\AddressController;
use App\Http\Controllers\Store\CartController;
use App\Http\Controllers\Store\CheckoutController;
use App\Http\Controllers\Store\ContactController as StoreContactController;
use App\Http\Controllers\Store\CouponController as StoreCouponController;
use App\Http\Controllers\Store\HomeController;
use App\Http\Controllers\Store\OrderController;
use App\Http\Controllers\Store\PageController as StorePageController;
use App\Http\Controllers\Store\ShopController;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Support\Facades\Route;

// ─── Store: Public ───────────────────────────────────────────────────────────

Route::middleware('web')->group(function () {
    Route::get('/', [HomeController::class, 'index'])->name('home');

    // Campaign tracking — public endpoints hit by the email's tracking pixel
    // (open) and the rewritten CTA href (click). They are not behind the
    // customer auth middleware because the mail client that fires them
    // does not carry the recipient's session.
    Route::get('/campaigns/{campaign}/open', [CampaignTrackingController::class, 'trackOpen'])->name('campaigns.track.open');
    Route::get('/campaigns/{campaign}/click', [CampaignTrackingController::class, 'trackClick'])->name('campaigns.track.click');

    // Shop catalog — DB-driven via ShopController. Filter URL params:
    // category, color, size, price_min, price_max, sort.
    Route::get('/shop', [ShopController::class, 'index'])->name('shop');

    // Product detail — DB-driven, eager-loaded with variants, sizes, colors,
    // and per-color images for the JS variant selector.
    Route::get('/shop/{slug}', [ShopController::class, 'show'])->name('shop.show');

    // Sitemap — the crawlable map of the public catalog (home, shop,
    // categories, active products, content pages), served as
    // application/xml by SitemapController. Public and unauthenticated
    // because crawlers carry no session. It is declared here rather than
    // under /shop so it can never be shadowed by the /shop/{slug}
    // product route above.
    Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');

    // Cart — rendered by Store\CartController. The page keeps using the
    // shared home.php data file for its editorial chrome (announcement bar,
    // footer), but the product list the JS validates and prices against now
    // comes from the live catalog in the database (products + variants +
    // colors) instead of that static array.
    //
    // The browser stays the source of truth for what is currently in the
    // cart: cart.js keeps it in localStorage and reads the payload from the
    // <script type="application/json" data-cart-products> tag. The routes
    // below are the server-side half — they persist a cart against a
    // customer account (or the visitor's session) so it survives a device
    // change and can be merged in at login. Guests are welcome: an
    // unauthenticated caller is keyed by session id instead of customer id.
    Route::get('/cart', [CartController::class, 'index'])->name('cart');
    Route::get('/cart/summary', [CartController::class, 'summary'])->name('cart.summary');
    Route::post('/cart/items', [CartController::class, 'store'])->name('cart.items.store');
    Route::patch('/cart/items/{cartItem}', [CartController::class, 'update'])->name('cart.items.update');
    Route::delete('/cart/items/{cartItem}', [CartController::class, 'destroy'])->name('cart.items.destroy');
    Route::post('/cart/merge', [CartController::class, 'merge'])->name('cart.merge');

    // Checkout — DB-backed. The form is rendered by the server
    // (Store\CheckoutController::show), submission is processed by
    // process (the JS still drives the busy state, but the order
    // is now persisted against the real DB), and the success page
    // reads the order back from the database. Throttled to 3/min to
    // keep order-spam bots out of the orders table.
    Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout.show');
    Route::post('/checkout', [CheckoutController::class, 'process'])
        ->middleware('throttle:checkout')
        ->name('checkout.process');

    // Coupon validation — AJAX pre-check from the checkout summary.
    // Accepts the live cart subtotal + shipping fee, returns the
    // discount and the new grand total without placing the order.
    // Throttled to 10/min because the endpoint is reachable
    // anonymously and a brute-forcer would otherwise iterate all
    // four- and five-letter coupon code combinations cheaply.
    Route::post('/checkout/coupon/validate', [StoreCouponController::class, 'validateCoupon'])
        ->middleware('throttle:coupons')
        ->name('checkout.coupon.validate');

    // Order success — looks up the order by its generated number
    // and renders the same Blade view the closure route used to.
    Route::get('/checkout/success/{order}', [CheckoutController::class, 'success'])
        ->name('checkout.success')
        ->where('order', '^ABO-\d{4}-\d+$');

    // Content pages — DB-driven via Store\PageController. All 5 pages
    // share a single Blade view (content.blade.php) which receives
    // the active Page record from the database. Inactive slugs
    // (is_active = false) return 404 so the store never exposes
    // unpublished content.
    Route::get('/about', [StorePageController::class, 'show'])->defaults('slug', 'about')->name('about');
    Route::get('/contact', [StorePageController::class, 'show'])->defaults('slug', 'contact')->name('contact');
    Route::get('/shipping', [StorePageController::class, 'show'])->defaults('slug', 'shipping')->name('shipping');
    Route::get('/returns', [StorePageController::class, 'show'])->defaults('slug', 'returns')->name('returns');
    Route::get('/exchange', [StorePageController::class, 'show'])->defaults('slug', 'exchange')->name('exchange');

    // Newsletter sign-up — the footer form on every storefront page.
    // Stores the address in `newsletter_subscribers` and answers with JSON
    // so home.js can swap the form for the confirmation state.
    Route::post('/newsletter', [StoreContactController::class, 'subscribe'])
        ->middleware('throttle:public-forms')
        ->name('newsletter.subscribe');

    // Contact form — backs the form on /contact. Stores the message in
    // `contact_messages` for the admin inbox.
    Route::post('/contact', [StoreContactController::class, 'message'])
        ->middleware('throttle:public-forms')
        ->name('contact.message');
});

// ─── Store: Guest ────────────────────────────────────────────────────────────

Route::middleware(['web', 'guest'])->group(function () {
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store']);

    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:login');
});

// ─── Store: Auth ─────────────────────────────────────────────────────────────

Route::middleware(['web', 'auth', 'not_blocked'])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // My Account — closure route, no controller, no DB. Renders the
    // account view with the authenticated Customer. Mirrors the pattern
    // used for /shop, /cart, /checkout (presentation-only closures).
    Route::get('/account', function () {
        $data = require resource_path('views/store/data/home.php');
        $data['user'] = auth()->user();

        return view('account', $data);
    })->name('account');

    // My Orders — the page shell plus the JSON it hydrates from. The view
    // paints no order data itself: Store\OrderController::list returns the
    // customer's own orders shaped for the stepper in resources/js/orders.js,
    // and `cancel` returns stock to inventory when they cancel a still-pending
    // order. Ownership is re-checked per order — see
    // Store\OrderController::assertOwnedBy.
    Route::get('/orders', [OrderController::class, 'index'])->name('orders');
    Route::get('/orders/data', [OrderController::class, 'list'])->name('orders.data');
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');

    // Address book — the addresses a customer ships to, matching the fields
    // on the checkout form. The address routes are declared explicitly
    // rather than through Route::resource() because "make default" is a
    // custom action that has no place in the resource vocabulary.
    //
    // Every action resolves the address on its own and then checks ownership
    // (Store\AddressController::assertOwnedBy), answering 404 rather than
    // 403 so one customer cannot probe for another's addresses.
    Route::get('/account/addresses', [AddressController::class, 'index'])->name('addresses.index');
    Route::post('/account/addresses', [AddressController::class, 'store'])->name('addresses.store');
    Route::match(['put', 'patch'], '/account/addresses/{address}', [AddressController::class, 'update'])->name('addresses.update');
    Route::delete('/account/addresses/{address}', [AddressController::class, 'destroy'])->name('addresses.destroy');
    Route::patch('/account/addresses/{address}/default', [AddressController::class, 'makeDefault'])->name('addresses.default');
});

// ─── Admin: Guest ────────────────────────────────────────────────────────────

Route::middleware('web')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/login', [AdminAuthController::class, 'create'])->name('login');
    Route::post('/login', [AdminAuthController::class, 'store'])
        ->middleware('throttle:login');
});

// ─── Admin: Dashboard preview (front-end only) ──────────────────────────────
// The dashboard is now backed by Admin\DashboardController (the preview
// closure used to live here while the front-end was front-end-only).
// The former preview route is removed; visit /admin after logging in
// to see the wired dashboard, and /admin/login to authenticate first.

// ─── Admin: Products preview (front-end only) ──────────────────────────────
// Placeholder routes that render the new admin.products views. They used
// to run WITHOUT auth so the design could be previewed before the catalog
// was connected to the database; that left the whole catalog screen
// readable by anyone. They now sit behind the `admin` middleware like
// every other admin route — the URL and the route name are unchanged, so
// the sidebar keeps working.
Route::middleware(['web', 'admin'])->prefix('admin/products')->name('admin.products.v2.')->group(function () {
    Route::get('/', function () {
        return view('admin.products.index');
    })->name('index');

    Route::get('/create', function () {
        return view('admin.products.form', ['mode' => 'create', 'product' => null]);
    })->name('create');

    Route::get('/{id}/edit', function (string $id) {
        $product = is_numeric($id) ? Product::find($id) : null;
        if ($product) {
            return app(ProductController::class)->edit($product);
        }

        $service = app(ProductService::class);

        // `array_merge`, not `+`: the union operator keeps the LEFT
        // operand's keys, so `+` silently dropped the `product` override
        // and handed the view an unsaved `new Product` — whose route key
        // is null, which blew up `route('admin.products.update', …)`.
        return view('admin.products.form', array_merge($service->loadFormData(), [
            'mode' => 'edit',
            'product' => ['id' => $id],
        ]));
    })->name('edit');
});

// (Categories moved to protected admin routes below)

// ─── Admin: Colors & Sizes preview (front-end only) ─────────────────────────
// Placeholder route that renders the new admin.colors-sizes.index view.
// It now sits behind the `admin` middleware — see the products group
// above for the full reasoning. Closure-only, no controller, no model,
// no validation. To open the page in a browser: log in, then visit
// /admin/colors-sizes.
Route::middleware(['web', 'admin'])->get('/admin/colors-sizes', function () {
    return view('admin.colors-sizes.index');
})->name('admin.colors-sizes.v2');

// ─── Admin: Size guides preview (front-end only) ────────────────────────────
// Placeholder route that renders the new admin.size-guides view. Behind
// the `admin` middleware like every other admin screen. Closure-only, no
// controller, no model, no validation. To open the page in a browser:
// log in, then visit /admin/size-guides.
Route::middleware(['web', 'admin'])->get('/admin/size-guides', function () {
    return view('admin.size-guides.index');
})->name('admin.size-guides.v2');

// ─── Admin: Customers preview (front-end only) ─────────────────────────────
// Placeholder route that renders the new admin.customers.index view WITHOUT
// auth — used to preview the front-end design before the customers catalog
// is connected to the database. Closure-only, no controller, no model, no
// validation. The existing protected admin routes are untouched. To open the
// page in a browser: visit /admin/customers.
Route::middleware('web')->get('/admin/customers', function () {
    return view('admin.customers.index');
})->name('admin.customers.v2');

// ─── Admin: Coupons ────────────────────────────────────────────────────────
// Coupons is now backed by Admin\CouponController. The previous preview
// closure (admin.coupons.v2) has been removed; the five routes below
// are the only ones /admin/coupons serves.

// ─── Admin: Banners preview (front-end only) ────────────────────────────────
// Placeholder route that renders the new admin.banners.index view WITHOUT
// auth — used to preview the front-end design before the banners catalog
// is wired up to the database. Closure-only, no controller, no model, no
// validation. The existing protected admin routes are untouched. To open
// the page in a browser: visit /admin/banners.

// ─── Admin: Settings preview (front-end only) ─────────────────────────────
// Placeholder route that renders the new admin.settings.index view WITHOUT
// auth — used to preview the front-end design before the settings catalog
// is connected to the database. Closure-only, no controller, no model,
// no validation. No protected admin.settings route exists, so the URL
// stays `/admin/settings` (no `-v2` suffix needed) and only the route
// name is suffixed with `.v2` (per the project convention). To open the
// page in a browser: visit /admin/settings.
Route::middleware('web')->get('/admin/settings', function () {
    return view('admin.settings.index');
})->name('admin.settings.v2');

// ─── Admin: Admins preview (front-end only) ──────────────────────────────
// Placeholder route that renders the new admin.admins.index-v2 view WITHOUT
// auth — used to preview the front-end design before the admins catalog
// is connected to the database. Closure-only, no controller, no model, no
// validation. The existing protected admin.admins.index / store / toggle /
// destroy routes and the legacy admin.admins.index Blade view are
// untouched (this view is `index-v2.blade.php`, not the legacy index).
//
// Note: the URL is also suffixed with `-v2` (`/admin/admins-v2`) because
// Laravel's RouteCollection keys routes by HTTP method + URI, so a
// second GET to the same `/admin/admins` URI as the protected
// `admin.admins.index` route below would be silently overwritten. The
// route name is `admin.admins.v2` (also suffixed, as the spec requires).
// To open the page in a browser: visit /admin/admins-v2.
Route::middleware('web')->get('/admin/admins-v2', function () {
    return view('admin.admins.index-v2');
})->name('admin.admins.v2');

// ─── Admin: Campaigns preview (front-end only) ─────────────────────────
// Placeholder route that renders the new admin.campaigns.index view
// WITHOUT auth — used to preview the front-end design before the
// marketing catalog is connected to the database. The fully wired
// (and auth-protected) admin.campaigns.* routes live in the protected
// admin group below; visit /admin/campaigns after logging in.
//
// This placeholder is intentionally kept so the front-end preview still
// works in local development — it just returns the view without data.
// To open the page in a browser: visit /admin/campaigns (the v2
// preview) or /admin/login → /admin/campaigns (the wired version).
//
// NOTE: unlike the admins-v2 preview above, this one is NOT suffixed in the
// URI, so it collides with the protected `admin.campaigns.index` route
// registered later in the same file. Laravel's RouteCollection keys routes
// by method + URI, so this closure is silently overwritten and the name
// `admin.campaigns.v2` does not resolve — the protected route wins. The
// `audienceCounts` below is kept in step with the wired route's view data so
// the closure still renders correctly if the collision is ever resolved.
Route::middleware('web')->get('/admin/campaigns', function () {
    return view('admin.campaigns.index', [
        'stats' => ['active_campaigns_count' => 0, 'total_sent_count' => 0, 'avg_open_rate' => 0.0, 'avg_click_rate' => 0.0],
        'campaignsJson' => '[]',
        // Zeroed for the same reason the stats above are: this route is a
        // database-free preview, so the audience cards render empty rather
        // than querying for a segment size nobody is going to send to.
        'audienceCounts' => array_fill_keys(array_map(
            fn (CampaignAudience $audience) => $audience->value,
            CampaignAudience::cases(),
        ), 0),
    ]);
})->name('admin.campaigns.v2');

// ─── Admin: Reports preview (front-end only) ──────────────────────────
// Placeholder route that renders the new admin.reports.index view WITHOUT
// auth — used to preview the front-end design before the analytics page
// is wired up to the database. Closure-only, no controller, no model,
// no validation. No protected admin.reports route exists, so the URL
// stays `/admin/reports` (no `-v2` suffix needed) and only the route name
// is suffixed with `.v2` (per the project convention). To open the page in
// a browser: visit /admin/reports. The Reports link in the sidebar
// partial (resources/views/admin/partials/sidebar.blade.php) is the
// shared `#/reports` item; we pass `activeHash` here so the sidebar
// highlights Reports while this preview is open.
Route::middleware('web')->get('/admin/reports', function () {
    return view('admin.reports.index', ['activeHash' => '#/reports']);
})->name('admin.reports.v2');

// ─── Admin: Protected ────────────────────────────────────────────────────────

Route::middleware(['web', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::post('/logout', [AdminAuthController::class, 'destroy'])->name('logout');

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // The JSON half of the dashboard's Today / 7 days / 30 days
    // segmented control. admin.js fetches this and re-draws the KPI
    // cards, the Sales vs Profit bars and the sparklines in place, so
    // switching range never reloads the page. The range travels as a
    // path segment and is validated inside
    // DashboardController::rangeData against DashboardService::RANGES —
    // the same list the buttons render from.
    Route::get('/dashboard/range/{range}', [DashboardController::class, 'rangeData'])->name('dashboard.range');

    Route::get('/admins', [AdminController::class, 'index'])->name('admins.index');
    Route::post('/admins', [AdminController::class, 'store'])->name('admins.store');
    Route::patch('/admins/{admin}/toggle', [AdminController::class, 'toggleActive'])->name('admins.toggle');
    Route::delete('/admins/{admin}', [AdminController::class, 'destroy'])->name('admins.destroy');

    // Categories
    Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::match(['put', 'patch'], '/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

    // Colors & Sizes
    Route::get('/colors-sizes', [ColorSizeController::class, 'index'])->name('colors-sizes.index');
    Route::post('/colors', [ColorSizeController::class, 'storeColor'])->name('colors.store');
    Route::match(['put', 'patch'], '/colors/{color}', [ColorSizeController::class, 'updateColor'])->name('colors.update');
    Route::delete('/colors/{color}', [ColorSizeController::class, 'destroyColor'])->name('colors.destroy');
    Route::post('/sizes', [ColorSizeController::class, 'storeSize'])->name('sizes.store');
    Route::match(['put', 'patch'], '/sizes/{size}', [ColorSizeController::class, 'updateSize'])->name('sizes.update');
    Route::delete('/sizes/{size}', [ColorSizeController::class, 'destroySize'])->name('sizes.destroy');

    // Products
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
    Route::match(['put', 'patch'], '/products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
    Route::patch('/products/{product}/toggle-archive', [ProductController::class, 'toggleArchive'])->name('products.toggle-archive');

    // Size Guides
    Route::get('/size-guides', [SizeGuideController::class, 'index'])->name('size-guides.index');
    Route::post('/size-guides', [SizeGuideController::class, 'store'])->name('size-guides.store');
    Route::match(['put', 'patch'], '/size-guides/{sizeGuide}', [SizeGuideController::class, 'update'])->name('size-guides.update');
    Route::delete('/size-guides/{sizeGuide}', [SizeGuideController::class, 'destroy'])->name('size-guides.destroy');

    // Orders — the list page serialises the whole order table into one
    // JSON array that public/admin/js/orders.js hydrates; the three
    // mutations below are the only calls that file makes back. An order
    // binds by primary key or by order number (see Order::resolveRouteBinding)
    // because the browser addresses orders by their order number.
    Route::get('/orders', [AdminOrderController::class, 'index'])->name('orders.index');
    Route::patch('/orders/{order}/status', [AdminOrderController::class, 'updateStatus'])->name('orders.status');
    Route::post('/orders/{order}/cancel', [AdminOrderController::class, 'cancel'])->name('orders.cancel');
    Route::patch('/orders/{order}/tracking', [AdminOrderController::class, 'updateTracking'])->name('orders.tracking');

    // Banners
    Route::get('/banners', [BannerController::class, 'index'])->name('banners.index');
    Route::post('/banners', [BannerController::class, 'store'])->name('banners.store');
    Route::match(['put', 'patch'], '/banners/{banner}', [BannerController::class, 'update'])->name('banners.update');
    Route::delete('/banners/{banner}', [BannerController::class, 'destroy'])->name('banners.destroy');

    // Coupons — see Admin\CouponController. The list page hydrates from
    // a single JSON payload (admin.coupons.index); the four mutations
    // below are the only AJAX calls coupons.js makes back.
    Route::get('/coupons', [AdminCouponController::class, 'index'])->name('coupons.index');
    Route::post('/coupons', [AdminCouponController::class, 'store'])->name('coupons.store');
    Route::match(['put', 'patch'], '/coupons/{coupon}', [AdminCouponController::class, 'update'])->name('coupons.update');
    Route::patch('/coupons/{coupon}/toggle', [AdminCouponController::class, 'toggle'])->name('coupons.toggle');
    Route::delete('/coupons/{coupon}', [AdminCouponController::class, 'destroy'])->name('coupons.destroy');

    // Campaigns — see Admin\CampaignController. The list page hydrates
    // from window.CAMPAIGNS_DATA that CampaignController::index prepares
    // via App\Services\Admin\CampaignService. The five mutations below are
    // the AJAX calls campaigns.js makes back.
    Route::get('/campaigns', [AdminCampaignController::class, 'index'])->name('campaigns.index');
    Route::post('/campaigns', [AdminCampaignController::class, 'store'])->name('campaigns.store');
    // Declared ahead of /campaigns/{campaign} so the literal first segment
    // wins if the bindings are ever loosened to a single-segment form.
    Route::get('/campaigns/audience-count/{audience}', [AdminCampaignController::class, 'previewAudienceCount'])->name('campaigns.audienceCount');
    Route::get('/campaigns/{campaign}', [AdminCampaignController::class, 'show'])->name('campaigns.show');
    Route::post('/campaigns/{campaign}/send-now', [AdminCampaignController::class, 'sendNow'])->name('campaigns.sendNow');
    Route::match(['put', 'patch'], '/campaigns/{campaign}', [AdminCampaignController::class, 'update'])->name('campaigns.update');
    Route::delete('/campaigns/{campaign}', [AdminCampaignController::class, 'destroy'])->name('campaigns.destroy');
    Route::post('/campaigns/{campaign}/duplicate', [AdminCampaignController::class, 'duplicate'])->name('campaigns.duplicate');

    // Pages
    Route::get('/pages', [AdminPageController::class, 'index'])->name('pages.index');
    Route::match(['put', 'patch'], '/pages/{page}', [AdminPageController::class, 'update'])->name('pages.update');

    // Shipping — zones, their governorates, and the global shipping settings.
    // Every mutation affects either a single row or a zone/its children, so
    // the multi-table cases are wrapped in a transaction inside ShippingService.
    Route::get('/shipping', [ShippingController::class, 'index'])->name('shipping.index');
    Route::post('/shipping/settings', [ShippingController::class, 'updateSettings'])->name('shipping.settings.update');
    Route::post('/shipping/zones', [ShippingController::class, 'storeZone'])->name('shipping.zones.store');
    Route::match(['put', 'patch'], '/shipping/zones/{shippingZone}', [ShippingController::class, 'updateZone'])->name('shipping.zones.update');
    Route::delete('/shipping/zones/{shippingZone}', [ShippingController::class, 'destroyZone'])->name('shipping.zones.destroy');
    Route::post('/shipping/governorates', [ShippingController::class, 'storeGovernorate'])->name('shipping.governorates.store');
    Route::match(['put', 'patch'], '/shipping/governorates/{governorate}', [ShippingController::class, 'updateGovernorate'])->name('shipping.governorates.update');
    Route::delete('/shipping/governorates/{governorate}', [ShippingController::class, 'destroyGovernorate'])->name('shipping.governorates.destroy');

    // Admin bell — the JSON half behind the notification bell in
    // resources/views/admin/partials/topbar.blade.php.
    //
    //   GET    /admin/notifications           — list + unread counter (polled)
    //   PATCH  /admin/notifications/{id}/read — mark a single row read
    //   POST   /admin/notifications/mark-all-read — wipe the badge
    //
    // The "/mark-all-read" route is declared before the "{id}/read"
    // route would collide with it (admin.js POSTs to the literal
    // /mark-all-read). Both routes are JSON-only — every payload the
    // front-end works with travels through /admin/notifications.
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [AdminNotificationController::class, 'index'])->name('index');
        Route::post('/mark-all-read', [AdminNotificationController::class, 'markAllAsRead'])->name('markAllRead');
        Route::patch('/{id}/read', [AdminNotificationController::class, 'markAsRead'])->name('read');
    });
});
