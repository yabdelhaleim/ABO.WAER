<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLiveAuditTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Super Admin',
            'email' => 'admin@store.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
    }

    public function test_all_admin_pages_render_with_full_markup_and_scripts(): void
    {
        $pages = [
            'Overview (Dashboard)' => '/admin',
            'Orders' => '/admin/orders',
            'Products' => '/admin/products',
            'Customers' => '/admin/customers',
            'Categories' => '/admin/categories',
            'Colors & Sizes' => '/admin/colors-sizes',
            'Size Guides' => '/admin/size-guides',
            'Banners' => '/admin/banners',
            'Shipping' => '/admin/shipping',
            'Content Pages' => '/admin/pages',
            'Coupons' => '/admin/coupons',
            'Campaigns' => '/admin/campaigns',
            'Reports' => '/admin/reports',
            'Admins' => '/admin/admins-v2',
            'Settings' => '/admin/settings',
        ];

        foreach ($pages as $label => $uri) {
            $response = $this->actingAs($this->admin, 'admin')->get($uri);

            $response->assertStatus(200);

            // Verify core shell layout is present on every single page
            $response->assertSee('abo-admin-sidebar', false);
            $response->assertSee('abo-admin-topbar', false);
            $response->assertSee('abo-admin-main', false);
            $response->assertSee('admin/css/admin.css', false);
            $response->assertSee('admin/js/admin.js', false);
        }

        // Test product create and edit (both mock p-008 and Eloquent)
        $createResponse = $this->actingAs($this->admin, 'admin')->get('/admin/products/create');
        $createResponse->assertStatus(200);

        $mockEditResponse = $this->actingAs($this->admin, 'admin')->get('/admin/products/p-008/edit');
        $mockEditResponse->assertStatus(200);
        $mockEditResponse->assertSee('EDIT PRODUCT', false);
        $mockEditResponse->assertSee('p-008', false);
    }
}
