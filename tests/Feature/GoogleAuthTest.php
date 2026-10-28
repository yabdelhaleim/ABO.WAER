<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_redirect_route_redirects_to_google(): void
    {
        $response = $this->get(route('auth.google'));

        $response->assertRedirect();
        $this->assertStringContainsString('accounts.google.com', $response->getTargetUrl());
    }

    public function test_google_callback_creates_new_customer_and_logs_in(): void
    {
        $abstractUser = Mockery::mock(SocialiteUser::class);
        $abstractUser->shouldReceive('getId')->andReturn('google-unique-id-12345');
        $abstractUser->shouldReceive('getEmail')->andReturn('customer@test.com');
        $abstractUser->shouldReceive('getName')->andReturn('Omar Sherif');
        $abstractUser->shouldReceive('getNickname')->andReturn(null);

        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('user')->andReturn($abstractUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('home'));
        $this->assertAuthenticated('web');

        $this->assertDatabaseHas('customers', [
            'email' => 'customer@test.com',
            'name' => 'Omar Sherif',
            'google_id' => 'google-unique-id-12345',
        ]);
    }

    public function test_google_callback_links_existing_customer_by_email(): void
    {
        $existing = Customer::factory()->create([
            'email' => 'existing@test.com',
            'google_id' => null,
        ]);

        $abstractUser = Mockery::mock(SocialiteUser::class);
        $abstractUser->shouldReceive('getId')->andReturn('google-linked-id-999');
        $abstractUser->shouldReceive('getEmail')->andReturn('existing@test.com');
        $abstractUser->shouldReceive('getName')->andReturn($existing->name);
        $abstractUser->shouldReceive('getNickname')->andReturn(null);

        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('user')->andReturn($abstractUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($existing, 'web');

        $this->assertDatabaseHas('customers', [
            'id' => $existing->id,
            'google_id' => 'google-linked-id-999',
        ]);
    }

    public function test_blocked_customer_cannot_login_via_google(): void
    {
        $blocked = Customer::factory()->create([
            'email' => 'blocked@test.com',
            'google_id' => 'blocked-id',
            'is_blocked' => true,
            'block_reason' => 'Fraudulent behavior',
        ]);

        $abstractUser = Mockery::mock(SocialiteUser::class);
        $abstractUser->shouldReceive('getId')->andReturn('blocked-id');
        $abstractUser->shouldReceive('getEmail')->andReturn('blocked@test.com');
        $abstractUser->shouldReceive('getName')->andReturn($blocked->name);
        $abstractUser->shouldReceive('getNickname')->andReturn(null);

        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('user')->andReturn($abstractUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get(route('auth.google.callback'));

        $response->assertRedirect(route('login'));
        $this->assertGuest('web');
        $response->assertSessionHasErrors('identifier');
    }
}
