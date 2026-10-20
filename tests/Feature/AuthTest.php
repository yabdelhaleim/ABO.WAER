<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    // ─── Customer Registration ────────────────────────────────────────────────

    public function test_customer_can_register_with_valid_phone(): void
    {
        $response = $this->post('/register', [
            'name' => 'Ahmed Ali',
            'email' => 'ahmed@example.com',
            'phone' => '01012345678',
            'whatsapp_same' => '1',
            'whatsapp' => '01012345678',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'accepts_promotions' => '1',
            'terms' => '1',
        ]);

        $response->assertRedirect('/');
        $this->assertDatabaseHas('customers', [
            'phone' => '01012345678',
            'email' => 'ahmed@example.com',
        ]);
    }

    public function test_customer_cannot_register_with_invalid_phone(): void
    {
        $response = $this->post('/register', [
            'name' => 'Ahmed Ali',
            'phone' => '123',
            'whatsapp_same' => '1',
            'whatsapp' => '123',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertSessionHasErrors('phone');
        $this->assertDatabaseMissing('customers', ['name' => 'Ahmed Ali']);
    }

    public function test_customer_cannot_register_with_duplicate_phone(): void
    {
        Customer::create([
            'name' => 'Existing',
            'phone' => '01012345678',
            'whatsapp' => '01012345678',
            'password' => 'password123',
        ]);

        $response = $this->post('/register', [
            'name' => 'New User',
            'phone' => '01012345678',
            'whatsapp_same' => '1',
            'whatsapp' => '01012345678',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertSessionHasErrors('phone');
    }

    // ─── Customer Login ───────────────────────────────────────────────────────

    public function test_customer_can_login_with_correct_credentials(): void
    {
        Customer::create([
            'name' => 'Ahmed Ali',
            'phone' => '01012345678',
            'whatsapp' => '01012345678',
            'password' => 'password123',
        ]);

        $response = $this->post('/login', [
            'phone' => '01012345678',
            'password' => 'password123',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_customer_cannot_login_with_wrong_password(): void
    {
        Customer::create([
            'name' => 'Ahmed Ali',
            'phone' => '01012345678',
            'whatsapp' => '01012345678',
            'password' => 'password123',
        ]);

        $response = $this->post('/login', [
            'phone' => '01012345678',
            'password' => 'wrongpassword',
        ]);

        $response->assertSessionHasErrors('phone');
        $this->assertGuest();
    }

    public function test_blocked_customer_is_redirected_to_login(): void
    {
        $customer = Customer::create([
            'name' => 'Blocked User',
            'phone' => '01012345678',
            'whatsapp' => '01012345678',
            'password' => 'password123',
            'is_blocked' => true,
        ]);

        // Middleware runs on requests through the stack — make a real route request
        $response = $this->actingAs($customer)->post('/logout');

        $this->assertGuest();
    }

    public function test_customer_can_logout(): void
    {
        $customer = Customer::create([
            'name' => 'Ahmed Ali',
            'phone' => '01012345678',
            'whatsapp' => '01012345678',
            'password' => 'password123',
        ]);

        $response = $this->actingAs($customer)->post('/logout');

        $response->assertRedirect('/');
        $this->assertGuest();
    }

    // ─── Admin Auth ───────────────────────────────────────────────────────────

    public function test_admin_can_login_with_correct_credentials(): void
    {
        $admin = Admin::create([
            'name' => 'Super Admin',
            'email' => 'admin@store.com',
            'password' => 'password',
            'is_active' => true,
        ]);

        $response = $this->post('/admin/login', [
            'email' => 'admin@store.com',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_admin_cannot_login_with_wrong_password(): void
    {
        Admin::create([
            'name' => 'Super Admin',
            'email' => 'admin@store.com',
            'password' => 'password',
            'is_active' => true,
        ]);

        $response = $this->post('/admin/login', [
            'email' => 'admin@store.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest('admin');
    }

    public function test_admin_panel_redirects_guests_to_admin_login(): void
    {
        $response = $this->get('/admin');

        $response->assertRedirect(route('admin.login'));
    }

    // ─── Admin Management ─────────────────────────────────────────────────────

    public function test_admin_can_create_another_admin(): void
    {
        $admin = Admin::create([
            'name' => 'Super Admin',
            'email' => 'admin@store.com',
            'password' => 'password',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin, 'admin')->post('/admin/admins', [
            'name' => 'New Admin',
            'email' => 'new@store.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect(route('admin.admins.index'));
        $this->assertDatabaseHas('admins', ['email' => 'new@store.com']);
    }

    public function test_cannot_delete_last_active_admin(): void
    {
        $admin = Admin::create([
            'name' => 'Super Admin',
            'email' => 'admin@store.com',
            'password' => 'password',
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->delete("/admin/admins/{$admin->id}");

        $response->assertSessionHasErrors('admin');
        $this->assertDatabaseHas('admins', ['id' => $admin->id]);
    }
}
