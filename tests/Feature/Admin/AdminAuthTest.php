<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\AuthController;
use App\Http\Middleware\EnsureAdmin;
use App\Models\Admin;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * The admin sign-in contract.
 *
 * Three guarantees are pinned here, one per failure mode they close:
 *
 *  1. An active admin signs in exactly as before.
 *  2. A suspended admin cannot sign in, and the failure looks exactly like
 *     a wrong password — the same message, so the response never reveals
 *     that the account exists.
 *  3. A suspension that lands *while a session is open* closes that session:
 *     the admin row is re-read on every request, so the next one is denied.
 *
 * A store customer is a guest as far as the back office is concerned, so a
 * signed-in customer never reaches the admin panel either.
 *
 * @see AuthController
 * @see EnsureAdmin
 */
class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The message every failed admin sign-in returns. It is deliberately
     * identical for a wrong password, an unknown address and a suspended
     * account, so the form never discloses which one it was.
     */
    private const GENERIC_FAILURE = 'Email or password is incorrect.';

    protected function makeAdmin(array $attributes = []): Admin
    {
        return Admin::create(array_merge([
            'name' => 'Security Admin',
            'email' => 'security@store.com',
            'password' => 'password',
            'is_active' => true,
        ], $attributes));
    }

    public function test_active_admin_can_log_in(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->post('/admin/login', [
            'email' => 'security@store.com',
            'password' => 'password',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_deactivated_admin_cannot_log_in(): void
    {
        $this->makeAdmin(['is_active' => false]);

        $response = $this->post('/admin/login', [
            'email' => 'security@store.com',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest('admin');
    }

    /**
     * The suspended-account failure must be indistinguishable from the
     * wrong-password one. Anything else — a different message, a different
     * field — turns the login form into an account-existence oracle.
     */
    public function test_deactivated_admin_login_failure_is_indistinguishable_from_a_wrong_password(): void
    {
        $this->makeAdmin(['email' => 'wrong@store.com', 'is_active' => true]);

        $this->post('/admin/login', [
            'email' => 'wrong@store.com',
            'password' => 'not-the-password',
        ])->assertSessionHasErrors(['email' => self::GENERIC_FAILURE]);

        $this->makeAdmin(['email' => 'suspended@store.com', 'is_active' => false]);

        $this->post('/admin/login', [
            'email' => 'suspended@store.com',
            'password' => 'password',
        ])->assertSessionHasErrors(['email' => self::GENERIC_FAILURE]);
    }

    /**
     * An admin whose account is suspended while a session is open loses the
     * dashboard on the very next request.
     *
     * The guard caches the model it authenticated, so the test rebuilds it
     * between requests — `Auth::forgetGuards()` throws the cached guard
     * away, and the next request re-reads the admin row from the database
     * exactly the way a fresh request does in production.
     */
    public function test_admin_deactivated_after_logging_in_is_denied_dashboard_access(): void
    {
        $admin = $this->makeAdmin();

        $this->post('/admin/login', [
            'email' => 'security@store.com',
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard'));

        // The session is live: the admin reaches the dashboard.
        Auth::forgetGuards();
        $this->get('/admin')->assertOk();

        // The account is suspended while that session is still open.
        $admin->update(['is_active' => false]);

        Auth::forgetGuards();

        $response = $this->get('/admin');

        $response->assertRedirect(route('admin.login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest('admin');
    }

    /**
     * A store customer signed in on the `web` guard is a guest on the
     * `admin` guard, so the back office stays out of reach.
     */
    public function test_signed_in_customer_cannot_access_the_admin_panel(): void
    {
        $customer = Customer::create([
            'name' => 'Store Customer',
            'email' => 'store.customer@store.com',
            'phone' => '01012345678',
            'password' => 'password',
        ]);

        $response = $this->actingAs($customer, 'web')->get('/admin');

        $response->assertRedirect(route('admin.login'));
        $this->assertGuest('admin');
    }
}
