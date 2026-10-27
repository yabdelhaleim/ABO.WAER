<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Access control for the admin preview pages.
 *
 * The front-end previews were registered outside the protected admin group,
 * so a guest could open the customers list, the settings screen, the reports
 * screen, the admins screen and the product edit form without signing in.
 * `/admin/products/{id}/edit` was the worst of them: it is registered
 * *before* the protected `/admin/products/{product}/edit`, and Laravel keys
 * its route table by method + URI, so the two parameter names made it two
 * separate entries and the first one registered won the match — the preview
 * shadowed the protected route entirely.
 *
 * Every one of them now sits behind the `admin` middleware, and these tests
 * pin that contract:
 *
 *  1. A guest is redirected to the admin login page.
 *  2. A signed-in *customer* is redirected too — a store account does not
 *     grant access to the back office.
 *  3. A signed-in admin still gets the page.
 *
 * @see routes/web.php
 */
class AdminRouteProtectionTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Security Admin',
            'email' => 'security@store.com',
            'password' => 'password',
            'is_active' => true,
        ]);
    }

    /**
     * The preview pages that must never be reachable without an admin.
     *
     * The product edit form is not in the list: the mock branch that
     * used to answer `/admin/products/p-008/edit` is gone, and the
     * protected route behind it now refuses an id that does not exist.
     * The guest case is pinned separately below.
     *
     * @return array<string, array{string}>
     */
    public static function protectedAdminPages(): array
    {
        return [
            'customers' => ['/admin/customers'],
            'settings' => ['/admin/settings'],
            'reports' => ['/admin/reports'],
            'admins' => ['/admin/admins'],
        ];
    }

    #[DataProvider('protectedAdminPages')]
    public function test_guest_is_redirected_to_the_admin_login(string $uri): void
    {
        $response = $this->get($uri);

        $response->assertRedirect(route('admin.login'));
        $response->assertDontSee('abo-admin-sidebar', false);
    }

    #[DataProvider('protectedAdminPages')]
    public function test_signed_in_customer_is_redirected_to_the_admin_login(string $uri): void
    {
        $customer = Customer::create([
            'name' => 'Store Customer',
            'email' => 'store.customer@store.com',
            'phone' => '01012345678',
            'password' => 'password',
        ]);

        $response = $this->actingAs($customer, 'web')->get($uri);

        $response->assertRedirect(route('admin.login'));
    }

    #[DataProvider('protectedAdminPages')]
    public function test_admin_can_open_the_page(string $uri): void
    {
        $response = $this->actingAs($this->admin, 'admin')->get($uri);

        $response->assertOk();
    }

    public function test_guest_cannot_open_the_edit_form_of_an_existing_product(): void
    {
        $product = Product::create([
            'name' => 'Essential Tee',
            'slug' => 'essential-tee',
        ]);

        $response = $this->get("/admin/products/{$product->id}/edit");

        $response->assertRedirect(route('admin.login'));
    }

    /**
     * A guest must not be able to tell a product that exists from one that
     * does not.
     *
     * The route binds the product before the controller runs, and a bound
     * model that does not exist raises a 404 on the spot. The admin check
     * therefore has to run BEFORE the binding — the framework's own
     * priority list puts the two in the wrong order, so the check is moved
     * in bootstrap/app.php. With it in front, a guest is turned away the
     * same way whether the id is real or made up.
     */
    public function test_a_guest_cannot_probe_the_catalog_by_id(): void
    {
        $product = Product::create([
            'name' => 'Essential Tee',
            'slug' => 'essential-tee',
        ]);

        $existing = $this->get("/admin/products/{$product->id}/edit");
        $missing = $this->get('/admin/products/999999/edit');

        $existing->assertRedirect(route('admin.login'));
        $missing->assertRedirect(route('admin.login'));
    }

    public function test_admin_can_open_the_edit_form_of_an_existing_product(): void
    {
        $product = Product::create([
            'name' => 'Essential Tee',
            'slug' => 'essential-tee',
        ]);

        $response = $this->actingAs($this->admin, 'admin')->get("/admin/products/{$product->id}/edit");

        $response->assertOk();
        $response->assertSee('Essential Tee');
    }

    /**
     * The mock branch of the product edit preview is gone: an id that does
     * not exist is a 404 now, for an admin as much as for a guest.
     */
    public function test_a_product_that_does_not_exist_is_not_answered_with_a_form(): void
    {
        $response = $this->actingAs($this->admin, 'admin')->get('/admin/products/p-008/edit');

        $response->assertNotFound();
    }

    /**
     * `/admin/colors-sizes` and `/admin/size-guides` are served by the
     * protected controllers of the same URI — the auth-less previews of the
     * same path are shadowed by them. This pins the shadowing so a future
     * route reshuffle cannot silently reopen the previews to guests.
     */
    public function test_shadowed_preview_routes_are_served_by_the_protected_controllers(): void
    {
        $uris = ['/admin/colors-sizes', '/admin/size-guides'];

        foreach ($uris as $uri) {
            $this->get($uri)->assertRedirect(route('admin.login'));
        }

        foreach ($uris as $uri) {
            $this->actingAs($this->admin, 'admin')->get($uri)->assertOk();
        }
    }
}
