<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_renders_abo_design(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('CREATE YOUR STYLE');
        $response->assertSee('WELCOME BACK');
        $response->assertSee('LOGIN TO');
        $response->assertSee('YOUR ACCOUNT');
        $response->assertSee('MODERN FIT.');
        $response->assertSee('URBAN VIBE.');
        $response->assertSee('EST. 2024');
        $response->assertSee('Better');
        $response->assertSee('© 2024 ABO');
        $response->assertSee('Email or Phone', false);
        $response->assertSee('Remember me');
        $response->assertSee('Continue with Google');
        $response->assertSee('Register');
        $response->assertSee('abo-logo.svg', false);
        $response->assertSee('streetwear-hero.jpg', false);
        $response->assertSee('class="abo-auth"', false);
    }

    public function test_login_page_displays_brand_assets(): void
    {
        $this->assertFileExists(public_path('images/abo-logo.svg'));
        $this->assertFileExists(public_path('images/streetwear-hero.jpg'));
    }

    public function test_customer_can_login_with_email(): void
    {
        Customer::create([
            'name' => 'Ahmed Ali',
            'email' => 'ahmed@example.com',
            'phone' => '01012345678',
            'whatsapp' => '01012345678',
            'password' => 'password123',
        ]);

        $response = $this->post('/login', [
            'identifier' => 'ahmed@example.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_customer_can_login_with_email_in_mixed_case(): void
    {
        Customer::create([
            'name' => 'Ahmed Ali',
            'email' => 'ahmed@example.com',
            'phone' => '01012345678',
            'whatsapp' => '01012345678',
            'password' => 'password123',
        ]);

        $response = $this->post('/login', [
            'identifier' => 'Ahmed@Example.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_login_with_email_keeps_identifier_on_failure(): void
    {
        $response = $this->post('/login', [
            'identifier' => 'missing@example.com',
            'password' => 'whatever',
        ]);

        $response->assertSessionHasErrors('identifier');
        $response->assertSessionHasInput('identifier', 'missing@example.com');
    }

    public function test_login_with_remember_me_keeps_user_logged_in_across_requests(): void
    {
        Customer::create([
            'name' => 'Ahmed Ali',
            'email' => 'ahmed@example.com',
            'phone' => '01012345678',
            'whatsapp' => '01012345678',
            'password' => 'password123',
        ]);

        $response = $this->post('/login', [
            'identifier' => 'ahmed@example.com',
            'password' => 'password123',
            'remember' => '1',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticated();

        $cookies = $response->headers->getCookies();
        $this->assertNotEmpty($cookies);
    }

    public function test_login_page_redirects_authenticated_customers_home(): void
    {
        $customer = Customer::create([
            'name' => 'Ahmed Ali',
            'email' => 'ahmed@example.com',
            'phone' => '01012345678',
            'whatsapp' => '01012345678',
            'password' => 'password123',
        ]);

        $response = $this->actingAs($customer)->get('/login');

        $response->assertRedirect('/');
    }

    public function test_legacy_phone_login_still_works(): void
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
}
