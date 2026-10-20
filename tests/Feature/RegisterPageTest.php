<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_page_renders_abo_design(): void
    {
        $response = $this->get('/register');

        $response->assertOk();
        $response->assertSee('CREATE YOUR STYLE');
        $response->assertSee('JOIN THE ABO');
        $response->assertSee('COMMUNITY');
        $response->assertSee('Create your account and discover new collections');
        $response->assertSee('MODERN FIT.');
        $response->assertSee('URBAN VIBE.');
        $response->assertSee('EST. 2024');
        $response->assertSee('Better');
        $response->assertSee('© 2024 ABO');
        $response->assertSee('Full Name', false);
        $response->assertSee('Email Address', false);
        $response->assertSee('Phone Number', false);
        $response->assertSee('Password', false);
        $response->assertSee('Confirm Password', false);
        $response->assertSee('I agree to the');
        $response->assertSee('Terms of Service');
        $response->assertSee('Privacy Policy');
        $response->assertSee('CREATE ACCOUNT');
        $response->assertSee('Continue with Google');
        $response->assertSee('Already have an account?');
        $response->assertSee('class="abo-auth"', false);
        $response->assertSee('streetwear-hero.jpg', false);
    }

    public function test_register_page_redirects_authenticated_customers_home(): void
    {
        $customer = Customer::create([
            'name' => 'Ahmed Ali',
            'email' => 'ahmed@example.com',
            'phone' => '01012345678',
            'whatsapp' => '01012345678',
            'password' => 'password123',
        ]);

        $response = $this->actingAs($customer)->get('/register');

        $response->assertRedirect('/');
    }

    public function test_customer_can_register_with_valid_data(): void
    {
        $response = $this->post('/register', [
            'name' => 'Ahmed Ali',
            'email' => 'ahmed@example.com',
            'phone' => '01012345678',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms' => '1',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticated();
        $this->assertDatabaseHas('customers', [
            'name' => 'Ahmed Ali',
            'email' => 'ahmed@example.com',
            'phone' => '01012345678',
        ]);
    }

    public function test_register_persists_normalized_email(): void
    {
        $this->post('/register', [
            'name' => 'Ahmed Ali',
            'email' => '  Ahmed@Example.COM ',
            'phone' => '01012345678',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms' => '1',
        ]);

        $this->assertDatabaseHas('customers', ['email' => 'ahmed@example.com']);
    }

    public function test_register_uses_phone_as_whatsapp_fallback(): void
    {
        $this->post('/register', [
            'name' => 'Ahmed Ali',
            'email' => 'ahmed@example.com',
            'phone' => '01012345678',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms' => '1',
        ]);

        $customer = Customer::where('email', 'ahmed@example.com')->first();
        $this->assertNotNull($customer);
        $this->assertSame('01012345678', $customer->whatsapp);
    }

    public function test_register_requires_name(): void
    {
        $response = $this->post('/register', [
            'email' => 'ahmed@example.com',
            'phone' => '01012345678',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms' => '1',
        ]);

        $response->assertSessionHasErrors('name');
    }

    public function test_register_requires_email(): void
    {
        $response = $this->post('/register', [
            'name' => 'Ahmed Ali',
            'phone' => '01012345678',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms' => '1',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_register_rejects_invalid_email(): void
    {
        $response = $this->post('/register', [
            'name' => 'Ahmed Ali',
            'email' => 'not-an-email',
            'phone' => '01012345678',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms' => '1',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_register_rejects_duplicate_email(): void
    {
        Customer::create([
            'name' => 'Existing',
            'email' => 'taken@example.com',
            'phone' => '01011111111',
            'whatsapp' => '01011111111',
            'password' => 'password123',
        ]);

        $response = $this->post('/register', [
            'name' => 'New User',
            'email' => 'taken@example.com',
            'phone' => '01012345678',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms' => '1',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_register_requires_phone(): void
    {
        $response = $this->post('/register', [
            'name' => 'Ahmed Ali',
            'email' => 'ahmed@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms' => '1',
        ]);

        $response->assertSessionHasErrors('phone');
    }

    public function test_register_rejects_invalid_phone(): void
    {
        $response = $this->post('/register', [
            'name' => 'Ahmed Ali',
            'email' => 'ahmed@example.com',
            'phone' => '123',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms' => '1',
        ]);

        $response->assertSessionHasErrors('phone');
    }

    public function test_register_rejects_duplicate_phone(): void
    {
        Customer::create([
            'name' => 'Existing',
            'email' => 'existing@example.com',
            'phone' => '01012345678',
            'whatsapp' => '01012345678',
            'password' => 'password123',
        ]);

        $response = $this->post('/register', [
            'name' => 'New User',
            'email' => 'new@example.com',
            'phone' => '01012345678',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms' => '1',
        ]);

        $response->assertSessionHasErrors('phone');
    }

    public function test_register_requires_password(): void
    {
        $response = $this->post('/register', [
            'name' => 'Ahmed Ali',
            'email' => 'ahmed@example.com',
            'phone' => '01012345678',
            'password_confirmation' => 'password123',
            'terms' => '1',
        ]);

        $response->assertSessionHasErrors('password');
    }

    public function test_register_requires_password_minimum_length(): void
    {
        $response = $this->post('/register', [
            'name' => 'Ahmed Ali',
            'email' => 'ahmed@example.com',
            'phone' => '01012345678',
            'password' => 'short',
            'password_confirmation' => 'short',
            'terms' => '1',
        ]);

        $response->assertSessionHasErrors('password');
    }

    public function test_register_requires_password_confirmation_match(): void
    {
        $response = $this->post('/register', [
            'name' => 'Ahmed Ali',
            'email' => 'ahmed@example.com',
            'phone' => '01012345678',
            'password' => 'password123',
            'password_confirmation' => 'different123',
            'terms' => '1',
        ]);

        $response->assertSessionHasErrors('password');
    }

    public function test_register_requires_terms_acceptance(): void
    {
        $response = $this->post('/register', [
            'name' => 'Ahmed Ali',
            'email' => 'ahmed@example.com',
            'phone' => '01012345678',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertSessionHasErrors('terms');
    }

    public function test_register_persists_hashed_password(): void
    {
        $this->post('/register', [
            'name' => 'Ahmed Ali',
            'email' => 'ahmed@example.com',
            'phone' => '01012345678',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms' => '1',
        ]);

        $customer = Customer::where('email', 'ahmed@example.com')->first();
        $this->assertNotNull($customer);
        $this->assertNotSame('password123', $customer->password);
        $this->assertTrue(password_verify('password123', $customer->password));
    }

    public function test_register_preserves_safe_input_on_failure(): void
    {
        $response = $this->post('/register', [
            'name' => 'Ahmed Ali',
            'email' => 'ahmed@example.com',
            'phone' => '01012345678',
            'password' => 'short',
            'password_confirmation' => 'short',
            'terms' => '1',
        ]);

        $response->assertSessionHasInput('name', 'Ahmed Ali');
        $response->assertSessionHasInput('email', 'ahmed@example.com');
        $response->assertSessionHasInput('phone', '01012345678');
    }

    public function test_register_does_not_repopulate_passwords_on_failure(): void
    {
        $response = $this->post('/register', [
            'name' => 'Ahmed Ali',
            'email' => 'ahmed@example.com',
            'phone' => '01012345678',
            'password' => 'short',
            'password_confirmation' => 'short',
            'terms' => '1',
        ]);

        $response->assertSessionMissing('password');
        $response->assertSessionMissing('password_confirmation');
    }
}
