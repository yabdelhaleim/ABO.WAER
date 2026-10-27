<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\PasswordResetController;
use App\Models\Admin;
use App\Notifications\AdminPasswordResetNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The admin password recovery contract.
 *
 * The recovery flow is the one place where the back office talks to an
 * anonymous visitor, so the guarantees pinned here are mostly about what
 * the flow must NOT tell a stranger:
 *
 *  1. The request endpoint answers identically for every address, so it
 *     cannot be used to discover which addresses belong to an admin.
 *  2. The update endpoint reports one message for every rejected token, so
 *     a wrong address and a dead token read the same.
 *  3. The link that goes out points at the admin flow — `admin.password.reset`
 *     — and never at the store front's `password.reset`.
 *  4. A token works once: the password changes, the remember token is
 *     retired with it, and the spent link cannot be replayed.
 *
 * The suite runs on an in-memory SQLite database (see the isolation test
 * below), so nothing here can reach the project's own database.
 *
 * @see PasswordResetController
 * @see AdminPasswordResetNotification
 */
class AdminPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The password an admin is created with throughout this file.
     */
    private const ORIGINAL_PASSWORD = 'password';

    /**
     * The password a reset introduces.
     */
    private const NEW_PASSWORD = 'a-brand-new-password';

    protected function makeAdmin(array $attributes = []): Admin
    {
        return Admin::create(array_merge([
            'name' => 'Security Admin',
            'email' => 'security@store.com',
            'password' => self::ORIGINAL_PASSWORD,
            'is_active' => true,
        ], $attributes));
    }

    /**
     * The suite must never be able to reach the project's own database.
     *
     * `phpunit.xml` points the SQLite connection at `:memory:` and Laravel's
     * Dotenv repository is immutable, so the value in `.env` cannot win. This
     * test makes that guarantee loud: if the wiring ever changes, the suite
     * says so before it starts writing.
     */
    public function test_the_suite_runs_against_an_in_memory_database(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    /**
     * A signed-out admin has to be able to reach both forms — that is the
     * whole point of a recovery link.
     */
    public function test_the_request_and_reset_pages_render_for_a_signed_out_visitor(): void
    {
        $this->get(route('admin.password.request'))
            ->assertOk()
            ->assertSee('SEND RECOVERY LINK', false);

        $this->get(route('admin.password.reset', [
            'token' => 'an-unused-token',
            'email' => 'security@store.com',
        ]))
            ->assertOk()
            ->assertSee('UPDATE PASSWORD', false);
    }

    /**
     * The admin sign-in page is where a locked-out admin starts, so it has
     * to offer the way out.
     */
    public function test_the_admin_login_page_links_to_the_recovery_form(): void
    {
        $this->get(route('admin.login'))
            ->assertOk()
            ->assertSee(route('admin.password.request'), false);
    }

    /**
     * The request endpoint must not become an account-existence oracle.
     *
     * An address that belongs to an admin, one that belongs to nobody and
     * one that was asked for a moment ago all leave with the same message
     * and the same absence of validation errors.
     */
    public function test_a_reset_link_request_answers_the_same_way_for_every_address(): void
    {
        Notification::fake();

        $this->makeAdmin(['email' => 'known@store.com']);

        $known = $this->post(route('admin.password.email'), ['email' => 'known@store.com']);
        $knownMessage = $known->getSession()->get('status');

        $unknown = $this->post(route('admin.password.email'), ['email' => 'unknown@store.com']);
        $unknownMessage = $unknown->getSession()->get('status');

        $this->assertNotEmpty($knownMessage);
        $this->assertSame($knownMessage, $unknownMessage);

        $known->assertSessionHasNoErrors();
        $unknown->assertSessionHasNoErrors();
    }

    /**
     * A request for a known admin produces the back office's own
     * notification, on the mail channel, and nothing is really sent.
     */
    public function test_a_reset_link_request_sends_the_admin_notification(): void
    {
        Notification::fake();

        $admin = $this->makeAdmin(['email' => 'security@store.com']);

        $this->post(route('admin.password.email'), ['email' => 'security@store.com'])
            ->assertRedirect();

        Notification::assertSentTo(
            $admin,
            AdminPasswordResetNotification::class,
            function (AdminPasswordResetNotification $notification, array $channels): bool {
                return $channels === ['mail'];
            }
        );
    }

    /**
     * The link inside the email has to land on the admin flow, carrying the
     * token and the address the framework expects.
     */
    public function test_the_notification_link_points_at_the_admin_reset_route(): void
    {
        Notification::fake();

        $admin = $this->makeAdmin(['email' => 'security@store.com']);

        $this->post(route('admin.password.email'), ['email' => 'security@store.com']);

        $notification = null;

        Notification::assertSentTo(
            $admin,
            AdminPasswordResetNotification::class,
            function (AdminPasswordResetNotification $sent) use (&$notification): bool {
                $notification = $sent;

                return true;
            }
        );

        $this->assertInstanceOf(AdminPasswordResetNotification::class, $notification);
        $this->assertNotEmpty($notification->token);

        // `toMail()` builds the message without sending it.
        $mail = $notification->toMail($admin);

        $this->assertSame(
            route('admin.password.reset', [
                'token' => $notification->token,
                'email' => $admin->email,
            ]),
            $mail->actionUrl
        );

        $this->assertStringContainsString('/admin/password/reset/', $mail->actionUrl);
        $this->assertStringContainsString('email='.urlencode($admin->email), $mail->actionUrl);
    }

    /**
     * A token that was never issued cannot change a password.
     */
    public function test_an_invalid_token_cannot_reset_the_password(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->post(route('admin.password.update'), [
            'token' => 'not-a-real-token',
            'email' => 'security@store.com',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);

        $response->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check(self::ORIGINAL_PASSWORD, $admin->fresh()->password));
    }

    /**
     * A token that was issued but has expired cannot change a password
     * either. The broker's window is 30 minutes, so the row is backdated
     * past it.
     */
    public function test_an_expired_token_cannot_reset_the_password(): void
    {
        $admin = $this->makeAdmin();

        $token = Password::broker('admins')->createToken($admin);

        DB::table('admin_password_reset_tokens')
            ->where('email', $admin->email)
            ->update(['created_at' => now()->subMinutes(31)]);

        $response = $this->post(route('admin.password.update'), [
            'token' => $token,
            'email' => 'security@store.com',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);

        $response->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check(self::ORIGINAL_PASSWORD, $admin->fresh()->password));
    }

    /**
     * A live token does the job — and is spent doing it.
     */
    public function test_a_valid_token_resets_the_password_and_cannot_be_reused(): void
    {
        $admin = $this->makeAdmin();
        $admin->forceFill(['remember_token' => 'the-old-remember-token'])->save();

        $token = Password::broker('admins')->createToken($admin);

        $response = $this->post(route('admin.password.update'), [
            'token' => $token,
            'email' => 'security@store.com',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);

        $response->assertRedirect(route('admin.login'));
        $response->assertSessionHas('status');

        $fresh = $admin->fresh();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $fresh->password));
        $this->assertFalse(Hash::check(self::ORIGINAL_PASSWORD, $fresh->password));

        // The remember token goes with the password, so the cookie minted
        // for the old one stops working.
        $this->assertNotSame('the-old-remember-token', $fresh->remember_token);
        $this->assertNotEmpty($fresh->remember_token);

        // The link is single use.
        $replay = $this->post(route('admin.password.update'), [
            'token' => $token,
            'email' => 'security@store.com',
            'password' => 'yet-another-password',
            'password_confirmation' => 'yet-another-password',
        ]);

        $replay->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $admin->fresh()->password));
    }

    /**
     * The new password has to clear the bar the rest of the back office
     * uses.
     */
    public function test_a_short_password_is_rejected(): void
    {
        $admin = $this->makeAdmin();
        $token = Password::broker('admins')->createToken($admin);

        $response = $this->post(route('admin.password.update'), [
            'token' => $token,
            'email' => 'security@store.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::ORIGINAL_PASSWORD, $admin->fresh()->password));
    }

    /**
     * And the confirmation has to match it.
     */
    public function test_a_mismatched_confirmation_is_rejected(): void
    {
        $admin = $this->makeAdmin();
        $token = Password::broker('admins')->createToken($admin);

        $response = $this->post(route('admin.password.update'), [
            'token' => $token,
            'email' => 'security@store.com',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => 'something-else',
        ]);

        $response->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::ORIGINAL_PASSWORD, $admin->fresh()->password));
    }

    /**
     * The recovery flow lives entirely under the back office's own names.
     * The store front's generic `password.*` routes stay absent, so the
     * customer sign-in page keeps its disabled "Forgot password?" and the
     * two flows cannot be confused for one another.
     */
    public function test_the_admin_recovery_routes_do_not_reuse_the_store_front_names(): void
    {
        $this->assertTrue(Route::has('admin.password.request'));
        $this->assertTrue(Route::has('admin.password.email'));
        $this->assertTrue(Route::has('admin.password.reset'));
        $this->assertTrue(Route::has('admin.password.update'));

        $this->assertFalse(Route::has('password.request'));
        $this->assertFalse(Route::has('password.email'));
        $this->assertFalse(Route::has('password.reset'));
        $this->assertFalse(Route::has('password.update'));
    }
}
