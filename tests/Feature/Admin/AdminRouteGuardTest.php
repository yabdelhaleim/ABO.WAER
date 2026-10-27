<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\EnsureAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every route under /admin must sit behind the `admin` middleware.
 *
 * The back office grew a set of "preview" routes while the screens were
 * front-end only: /admin/colors-sizes and /admin/size-guides were
 * registered with nothing but the `web` middleware, and they stayed
 * unreachable only because the protected group registered the same
 * method + URI later and Laravel overwrote the earlier entry. A preview
 * is protected by an accident of the route table, not by a decision.
 *
 * A second accident was waiting behind it: /admin/products/{id}/edit is
 * registered with a different parameter name than the protected
 * /admin/products/{product}/edit, so the two are different route-table
 * keys and the preview wins the match — the "mock" branch of that preview
 * renders a form for a product id that may not exist.
 *
 * These tests read the route table itself, so a new admin route that
 * forgets the middleware fails here instead of quietly going public.
 */
class AdminRouteGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The admin routes a guest is supposed to reach: the login screen and
     * the password recovery flow, which exists precisely for an admin who
     * cannot sign in.
     *
     * They are matched on the URI rather than on the route name: the POST
     * half of the login form carries no name at all, and a name-based
     * filter would silently let it through.
     *
     * @var array<int, string>
     */
    protected const GUEST_URIS = [
        'admin/login',
        'admin/password/request',
        'admin/password/email',
        'admin/password/reset/{token}',
        'admin/password/update',
    ];

    /**
     * Every route the application serves under /admin, minus the ones a
     * guest is meant to reach.
     *
     * @return Collection<int, RoutingRoute>
     */
    protected function adminRoutes()
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route) => str_starts_with($route->uri(), 'admin'))
            ->reject(fn (RoutingRoute $route) => in_array($route->uri(), self::GUEST_URIS, true))
            ->values();
    }

    public function test_every_admin_route_carries_the_admin_middleware(): void
    {
        $routes = $this->adminRoutes();

        $this->assertGreaterThan(20, $routes->count(), 'the admin route table looks suspiciously small');

        $unprotected = [];

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();

            if (! in_array('admin', $middleware, true) && ! in_array(EnsureAdmin::class, $middleware, true)) {
                $unprotected[] = strtoupper(implode('|', $route->methods())).' /'.$route->uri();
            }
        }

        $this->assertSame(
            [],
            $unprotected,
            'these admin routes are reachable without a signed-in admin: '.implode(', ', $unprotected)
        );
    }

    public function test_a_guest_is_redirected_from_every_admin_page_without_parameters(): void
    {
        $pages = $this->adminRoutes()
            ->filter(fn (RoutingRoute $route) => in_array('GET', $route->methods(), true))
            ->reject(fn (RoutingRoute $route) => str_contains($route->uri(), '{'));

        $this->assertGreaterThan(5, $pages->count());

        foreach ($pages as $route) {
            $this->get('/'.$route->uri())
                ->assertRedirect(route('admin.login'));
        }
    }

    /**
     * The two URIs that used to be served by an auth-less preview and are
     * now served by the protected controllers of the same path. The
     * protection is a property of the route table, so it is pinned here
     * explicitly as well.
     */
    public function test_the_shadowed_preview_paths_are_served_by_protected_routes(): void
    {
        foreach (['/admin/colors-sizes', '/admin/size-guides', '/admin/products'] as $uri) {
            $route = Route::getRoutes()->match(
                Request::create($uri, 'GET')
            );

            $this->assertContains(
                'admin',
                $route->gatherMiddleware(),
                $uri.' must be served by a route that carries the admin middleware'
            );
        }
    }

    /**
     * A guest must not be able to tell a product that exists from one that
     * does not: both have to answer the same way.
     */
    public function test_a_guest_cannot_probe_the_admin_catalog_by_id(): void
    {
        $this->get('/admin/products/999999/edit')->assertRedirect(route('admin.login'));
    }
}
