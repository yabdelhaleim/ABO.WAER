<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AdminNotificationController;
use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\BannerController;
use App\Http\Controllers\Admin\CampaignController as AdminCampaignController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ColorSizeController;
use App\Http\Controllers\Admin\CouponController as AdminCouponController;
use App\Http\Controllers\Admin\CustomerController as AdminCustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\PageController as AdminPageController;
use App\Http\Controllers\Admin\PasswordResetController;
use App\Http\Controllers\Admin\ProductBulkController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\ShippingController;
use App\Http\Controllers\Admin\SizeGuideController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\CampaignTrackingController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\Store\AddressController;
use App\Http\Controllers\Store\CartController;
use App\Http\Controllers\Store\CheckoutController;
use App\Http\Controllers\Store\CouponController as StoreCouponController;
use App\Http\Controllers\Store\HomeController;
use App\Http\Controllers\Store\OrderController;
use App\Http\Controllers\Store\PageController as StorePageController;
use App\Http\Controllers\Store\SearchController;
use App\Http\Controllers\Store\ShopController;
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

    // Search — DB-driven via Store\SearchController. The term travels in
    // the `q` query string, so a result page is a shareable URL and the
    // page works with JavaScript disabled. Declared before the sitemap
    // route and outside `/shop/{slug}` so it can never be shadowed by
    // the product route above.
    Route::get('/search', [SearchController::class, 'index'])->name('search');

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

// ─── Admin: Password reset (no admin session required) ───────────────────────
// A recovery link is only useful to an admin who cannot sign in, so these
// routes deliberately sit OUTSIDE the `admin` middleware group further down
// — a guest reaching them is the expected case, not a mistake.
//
// The paths and names are namespaced under `admin.` so they never collide
// with the store front's `password.*` routes, and so the link the admin
// notification builds lands on the admin flow.
//
// The request route answers the same way for every address, so it cannot be
// used to discover which addresses belong to an admin.
Route::middleware('web')->prefix('admin')->name('admin.')->group(function () {
    Route::get('/password/request', [PasswordResetController::class, 'showLinkRequestForm'])
        ->name('password.request');
    Route::post('/password/email', [PasswordResetController::class, 'sendResetLinkEmail'])
        ->name('password.email')
        ->middleware('throttle:6,1');
    Route::get('/password/reset/{token}', [PasswordResetController::class, 'showResetForm'])
        ->name('password.reset');
    Route::post('/password/update', [PasswordResetController::class, 'reset'])
        ->name('password.update');
});

// ─── Admin: Dashboard preview (front-end only) ──────────────────────────────
// The dashboard is now backed by Admin\DashboardController (the preview
// closure used to live here while the front-end was front-end-only).
// The former preview route is removed; visit /admin after logging in
// to see the wired dashboard, and /admin/login to authenticate first.

// ─── Admin: Products (no preview) ───────────────────────────────────────────
// The products preview group is gone. `/admin/products` and
// `/admin/products/create` were registered here and then registered again
// by the protected group further down, and Laravel keys its route table by
// method + URI — so the protected copies silently replaced these and the
// previews were unreachable.
//
// The third one was not harmless: `/admin/products/{id}/edit` used a
// different parameter name than the protected `/admin/products/{product}/edit`,
// which makes them two different table keys, and the preview won the match.
// Its fallback branch rendered an edit form for a product id that may not
// exist at all. The protected route answers that path now, and answers it
// the same way for every id — see the protected group below.

// (Categories moved to protected admin routes below)

// ─── Admin: Colors & Sizes (no preview) ─────────────────────────────────────
// The `/admin/colors-sizes` preview closure is gone for the same reason as
// the products one: the protected group registers the very same method +
// URI below, so the preview was already unreachable. The screen is served
// by Admin\ColorSizeController.

// ─── Admin: Size guides (no preview) ────────────────────────────────────────
// Likewise: `/admin/size-guides` was registered twice, and the protected
// copy below is the one that answers.

// ─── Admin: Customers ───────────────────────────────────────────────────────
// The customers screen is served by the protected group further down, from
// Admin\CustomerController::index — the list, the figures and the detail
// slide-over all come from the database. The `/admin/customers` preview that
// used to sit here is gone: it rendered forty invented customers, and the
// wired one is the only one that should be reachable.

// ─── Admin: Coupons ────────────────────────────────────────────────────────
// Coupons is now backed by Admin\CouponController. The previous preview
// closure (admin.coupons.v2) has been removed; the five routes below
// are the only ones /admin/coupons serves.

// ─── Admin: Banners (no preview) ────────────────────────────────────────────
// The `/admin/banners` preview closure is gone: the protected group below
// registers the same method + URI, so the preview was already unreachable.
// The screen is served by Admin\BannerController.

// ─── Admin: Settings (no preview) ──────────────────────────────────────────
// The `/admin/settings` preview closure is gone. It rendered the settings
// screen with a static array behind it and saved nothing; the protected
// group below serves the very same URL from Admin\SettingsController, with
// the settings read from the database and every change written back to it.

// ─── Admin: Admins ─────────────────────────────────────────────────────────
// The admins page is served by the protected group further down, from
// Admin\AdminController::index — the list, the role map and the trail
// all come from the database. The `/admin/admins-v2` preview that used
// to sit here is gone: it rendered the same screen with made-up
// accounts, and the wired one is the only one that should be reachable.

// ─── Admin: Campaigns (no preview) ──────────────────────────────────────────
// The `/admin/campaigns` preview closure is gone. It was registered on the
// very same method + URI as the protected `admin.campaigns.index` route
// further down, so the protected copy had already replaced it and the
// name `admin.campaigns.v2` never resolved. The screen is served by
// Admin\CampaignController.

// ─── Admin: Reports (no preview) ────────────────────────────────────────────
// The `/admin/reports` preview closure is gone. It served the same URL as
// the protected `admin.reports.index` route further down with a static
// REPORTS_DATA object behind it, so the preview had already replaced the
// wired page and the name `admin.reports.v2` never resolved. The screen is
// served by Admin\ReportController and every number on it is measured from
// the database.

// ─── Admin: Protected ────────────────────────────────────────────────────────
//
// Every route below sits behind two middlewares:
//
//   admin             — the caller is an active admin of the back office.
//   admin.permission  — the caller's ROLE reaches the module the route
//                       belongs to. A read needs the "view" level, a
//                       write the "manage" level; the levels live in
//                       App\Enums\AdminRole.
//
// The second one is the real protection. The interface used to describe
// the roles in JavaScript only, so every signed-in admin could reach
// every screen no matter what their role said. The rules now live on
// the server, and the interface merely mirrors them.

Route::middleware(['web', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::post('/logout', [AdminAuthController::class, 'destroy'])->name('logout');

    // Dashboard
    Route::middleware('admin.permission:dashboard')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        // The JSON half of the dashboard's Today / 7 days / 30 days
        // segmented control. admin.js fetches this and re-draws the KPI
        // cards, the Sales vs Profit bars and the sparklines in place, so
        // switching range never reloads the page. The range travels as a
        // path segment and is validated inside
        // DashboardController::rangeData against DashboardService::RANGES —
        // the same list the buttons render from.
        Route::get('/dashboard/range/{range}', [DashboardController::class, 'rangeData'])->name('dashboard.range');
    });

    // Admins — the back office itself. Only the owner reaches the
    // "manage" level here, so creating, editing, suspending and deleting
    // an account stay with them; a manager may look at the list.
    Route::middleware('admin.permission:admins')->group(function () {
        Route::get('/admins', [AdminController::class, 'index'])->name('admins.index');
        Route::post('/admins', [AdminController::class, 'store'])->name('admins.store');
        Route::match(['put', 'patch'], '/admins/{admin}', [AdminController::class, 'update'])->name('admins.update');
        Route::patch('/admins/{admin}/toggle', [AdminController::class, 'toggleActive'])->name('admins.toggle');
        Route::delete('/admins/{admin}', [AdminController::class, 'destroy'])->name('admins.destroy');
    });

    // Customers — the list, the two things an operator may change about
    // a customer (their tags and note, and whether they are blocked),
    // and the export.
    Route::middleware('admin.permission:customers')->group(function () {
        Route::get('/customers', [AdminCustomerController::class, 'index'])->name('customers.index');
        // The export is declared ahead of the {customer} route: Laravel
        // matches in registration order, so a later `/customers/{customer}`
        // would swallow the word "export" as a customer id.
        Route::get('/customers/export', [AdminCustomerController::class, 'export'])->name('customers.export');
        Route::match(['put', 'patch'], '/customers/{customer}', [AdminCustomerController::class, 'update'])->name('customers.update');
        Route::patch('/customers/{customer}/block', [AdminCustomerController::class, 'block'])->name('customers.block');
    });

    // Categories
    Route::middleware('admin.permission:categories')->group(function () {
        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        // Declared before the {category} routes so the word "reorder"
        // is never read as a category id.
        Route::post('/categories/reorder', [CategoryController::class, 'reorder'])->name('categories.reorder');
        Route::match(['put', 'patch'], '/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::patch('/categories/{category}/toggle', [CategoryController::class, 'toggle'])->name('categories.toggle');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
    });

    // Colors & Sizes
    Route::middleware('admin.permission:colors_sizes')->group(function () {
        Route::get('/colors-sizes', [ColorSizeController::class, 'index'])->name('colors-sizes.index');
        Route::post('/colors', [ColorSizeController::class, 'storeColor'])->name('colors.store');
        // Declared before the {color} routes so the word "reorder" is
        // never read as a color id.
        Route::post('/colors/reorder', [ColorSizeController::class, 'reorderColors'])->name('colors.reorder');
        Route::match(['put', 'patch'], '/colors/{color}', [ColorSizeController::class, 'updateColor'])->name('colors.update');
        Route::patch('/colors/{color}/toggle', [ColorSizeController::class, 'toggleColor'])->name('colors.toggle');
        Route::delete('/colors/{color}', [ColorSizeController::class, 'destroyColor'])->name('colors.destroy');
        Route::post('/sizes', [ColorSizeController::class, 'storeSize'])->name('sizes.store');
        Route::post('/sizes/reorder', [ColorSizeController::class, 'reorderSizes'])->name('sizes.reorder');
        Route::patch('/sizes/group/{group}/toggle', [ColorSizeController::class, 'toggleSizeGroup'])->name('sizes.group.toggle');
        Route::match(['put', 'patch'], '/sizes/{size}', [ColorSizeController::class, 'updateSize'])->name('sizes.update');
        Route::delete('/sizes/{size}', [ColorSizeController::class, 'destroySize'])->name('sizes.destroy');
    });

    // Products
    //
    // `/products/bulk` and `/products/export` are declared BEFORE the
    // `{product}` routes: Laravel matches in registration order, so a
    // later `/products/{product}` would swallow the word "bulk" as a
    // product id.
    Route::middleware('admin.permission:products')->group(function () {
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::post('/products/bulk', ProductBulkController::class)->name('products.bulk');
        Route::get('/products/export', [ProductController::class, 'export'])->name('products.export');
        Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
        Route::match(['put', 'patch'], '/products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
        Route::patch('/products/{product}/toggle-archive', [ProductController::class, 'toggleArchive'])->name('products.toggle-archive');
    });

    // Size Guides
    Route::middleware('admin.permission:size_guides')->group(function () {
        Route::get('/size-guides', [SizeGuideController::class, 'index'])->name('size-guides.index');
        Route::post('/size-guides', [SizeGuideController::class, 'store'])->name('size-guides.store');
        Route::match(['put', 'patch'], '/size-guides/{sizeGuide}', [SizeGuideController::class, 'update'])->name('size-guides.update');
        Route::patch('/size-guides/{sizeGuide}/toggle', [SizeGuideController::class, 'toggle'])->name('size-guides.toggle');
        Route::post('/size-guides/{sizeGuide}/duplicate', [SizeGuideController::class, 'duplicate'])->name('size-guides.duplicate');
        Route::post('/size-guides/{sizeGuide}/image', [SizeGuideController::class, 'image'])->name('size-guides.image');
        Route::delete('/size-guides/{sizeGuide}', [SizeGuideController::class, 'destroy'])->name('size-guides.destroy');
    });

    // Orders — the list page serialises the whole order table into one
    // JSON array that public/admin/js/orders.js hydrates; the three
    // mutations below are the only calls that file makes back. An order
    // binds by primary key or by order number (see Order::resolveRouteBinding)
    // because the browser addresses orders by their order number.
    Route::middleware('admin.permission:orders')->group(function () {
        Route::get('/orders', [AdminOrderController::class, 'index'])->name('orders.index');
        // The export sits next to the list it exports: it reads the
        // same filters off the query string and streams the rows the
        // screen is showing.
        Route::get('/orders/export', [AdminOrderController::class, 'export'])->name('orders.export');
        Route::patch('/orders/{order}/status', [AdminOrderController::class, 'updateStatus'])->name('orders.status');
        Route::post('/orders/{order}/cancel', [AdminOrderController::class, 'cancel'])->name('orders.cancel');
        Route::patch('/orders/{order}/tracking', [AdminOrderController::class, 'updateTracking'])->name('orders.tracking');
    });

    // Banners
    Route::middleware('admin.permission:banners')->group(function () {
        Route::get('/banners', [BannerController::class, 'index'])->name('banners.index');
        Route::post('/banners', [BannerController::class, 'store'])->name('banners.store');
        Route::match(['put', 'patch'], '/banners/{banner}', [BannerController::class, 'update'])->name('banners.update');
        Route::post('/banners/{banner}/duplicate', [BannerController::class, 'duplicate'])->name('banners.duplicate');
        Route::delete('/banners/{banner}', [BannerController::class, 'destroy'])->name('banners.destroy');
    });

    // Coupons — see Admin\CouponController. The list page hydrates from
    // a single JSON payload (admin.coupons.index); the four mutations
    // below are the only AJAX calls coupons.js makes back.
    Route::middleware('admin.permission:coupons')->group(function () {
        Route::get('/coupons', [AdminCouponController::class, 'index'])->name('coupons.index');
        Route::post('/coupons', [AdminCouponController::class, 'store'])->name('coupons.store');
        Route::match(['put', 'patch'], '/coupons/{coupon}', [AdminCouponController::class, 'update'])->name('coupons.update');
        Route::patch('/coupons/{coupon}/toggle', [AdminCouponController::class, 'toggle'])->name('coupons.toggle');
        Route::delete('/coupons/{coupon}', [AdminCouponController::class, 'destroy'])->name('coupons.destroy');
    });

    // Campaigns — see Admin\CampaignController. The list page hydrates
    // from window.CAMPAIGNS_DATA that CampaignController::index prepares
    // via App\Services\Admin\CampaignService. The five mutations below
    // are the AJAX calls campaigns.js makes back.
    Route::middleware('admin.permission:campaigns')->group(function () {
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
    });

    // Pages
    Route::middleware('admin.permission:pages')->group(function () {
        Route::get('/pages', [AdminPageController::class, 'index'])->name('pages.index');
        Route::match(['put', 'patch'], '/pages/{page}', [AdminPageController::class, 'update'])->name('pages.update');
    });

    // Reports — the analytics screen. The page carries the report the
    // server measured; the range endpoint answers the same report for
    // another range so the toolbar never reloads the page, and the
    // export streams the report as a CSV.
    Route::middleware('admin.permission:reports')->group(function () {
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/range/{range}', [ReportController::class, 'range'])->name('reports.range');
        Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');
    });

    // Shipping — zones, their governorates, and the global shipping settings.
    // Every mutation affects either a single row or a zone/its children, so
    // the multi-table cases are wrapped in a transaction inside ShippingService.
    Route::middleware('admin.permission:shipping')->group(function () {
        Route::get('/shipping', [ShippingController::class, 'index'])->name('shipping.index');
        Route::post('/shipping/settings', [ShippingController::class, 'updateSettings'])->name('shipping.settings.update');
        Route::post('/shipping/zones', [ShippingController::class, 'storeZone'])->name('shipping.zones.store');
        Route::match(['put', 'patch'], '/shipping/zones/{shippingZone}', [ShippingController::class, 'updateZone'])->name('shipping.zones.update');
        Route::post('/shipping/zones/{shippingZone}/reorder', [ShippingController::class, 'reorderGovernorates'])->name('shipping.zones.reorder');
        Route::delete('/shipping/zones/{shippingZone}', [ShippingController::class, 'destroyZone'])->name('shipping.zones.destroy');
        Route::post('/shipping/governorates', [ShippingController::class, 'storeGovernorate'])->name('shipping.governorates.store');
        Route::match(['put', 'patch'], '/shipping/governorates/{governorate}', [ShippingController::class, 'updateGovernorate'])->name('shipping.governorates.update');
        Route::delete('/shipping/governorates/{governorate}', [ShippingController::class, 'destroyGovernorate'])->name('shipping.governorates.destroy');
    });

    // Settings — the store's own settings: identity, order rules,
    // stock thresholds, the cancellation reasons, the receipt layout and
    // the notification switches. The screen draws what the server stored
    // and writes every change back through the endpoints below.
    //
    // Only the owner reaches the "manage" level on this module, so the
    // settings are the owner's to change; a manager may look at them.
    Route::middleware('admin.permission:settings')->group(function () {
        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('/settings/branding', [SettingsController::class, 'branding'])->name('settings.branding');
        Route::post('/settings/reset', [SettingsController::class, 'reset'])->name('settings.reset');
    });

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
    //
    // No module permission applies here: the bell is the signed-in
    // admin's own inbox, and marking one's own notifications read is
    // not a change to any module's rows.
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [AdminNotificationController::class, 'index'])->name('index');
        Route::post('/mark-all-read', [AdminNotificationController::class, 'markAllAsRead'])->name('markAllRead');
        Route::patch('/{id}/read', [AdminNotificationController::class, 'markAsRead'])->name('read');
    });
});
