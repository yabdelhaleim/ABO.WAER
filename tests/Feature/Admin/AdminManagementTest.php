<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\AdminController;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Managing admin accounts from the back office.
 *
 * The rule that matters here is who may remove whom:
 *
 *  1. An admin may not delete the account they are signed in with.
 *  2. An admin may not deactivate the account they are signed in with.
 *  3. Everything else stays as it was — an admin may still delete or
 *     suspend a colleague, and the panel may never end up without a single
 *     active admin.
 *
 * Both refusals are enforced by the controller, not by hiding a button:
 * the checks run on the server and the tests below call the routes
 * directly, so a crafted request is refused exactly the way a click is.
 *
 * @see AdminController
 */
class AdminManagementTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Signed In Admin',
            'email' => 'signed.in@store.com',
            'password' => 'password',
            'is_active' => true,
        ]);
    }

    private function createAdmin(array $attributes = []): Admin
    {
        static $sequence = 0;
        $sequence++;

        return Admin::create(array_merge([
            'name' => 'Colleague '.$sequence,
            'email' => 'colleague'.$sequence.'@store.com',
            'password' => 'password',
            'is_active' => true,
        ], $attributes));
    }

    // ─── Self-protection ──────────────────────────────────────────────────────

    /**
     * Deleting your own account is refused even when a second active admin
     * exists — so the refusal comes from the self-guard and not from the
     * "last active admin" rule that follows it.
     */
    public function test_admin_cannot_delete_own_account(): void
    {
        $colleague = $this->createAdmin();

        $response = $this->actingAs($this->admin, 'admin')
            ->delete("/admin/admins/{$this->admin->id}");

        $response->assertSessionHasErrors('admin');
        $this->assertDatabaseHas('admins', ['id' => $this->admin->id]);
        $this->assertDatabaseHas('admins', ['id' => $colleague->id]);
    }

    /**
     * The same refusal for the status switch: an admin cannot switch off
     * the account they are working from.
     */
    public function test_admin_cannot_deactivate_own_account(): void
    {
        $colleague = $this->createAdmin();

        $response = $this->actingAs($this->admin, 'admin')
            ->patch("/admin/admins/{$this->admin->id}/toggle");

        $response->assertSessionHasErrors('admin');
        $this->assertTrue($this->admin->fresh()->is_active);
        $this->assertTrue($colleague->fresh()->is_active);
    }

    // ─── Operations on another admin stay allowed ────────────────────────────

    public function test_admin_can_delete_another_admin(): void
    {
        $colleague = $this->createAdmin();

        $response = $this->actingAs($this->admin, 'admin')
            ->delete("/admin/admins/{$colleague->id}");

        $response->assertRedirect(route('admin.admins.index'));
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('admins', ['id' => $colleague->id]);
    }

    public function test_admin_can_deactivate_another_admin(): void
    {
        $colleague = $this->createAdmin();

        $response = $this->actingAs($this->admin, 'admin')
            ->patch("/admin/admins/{$colleague->id}/toggle");

        $response->assertSessionHasNoErrors();
        $this->assertFalse($colleague->fresh()->is_active);
    }

    public function test_admin_can_activate_another_admin(): void
    {
        $colleague = $this->createAdmin(['is_active' => false]);

        $response = $this->actingAs($this->admin, 'admin')
            ->patch("/admin/admins/{$colleague->id}/toggle");

        $response->assertSessionHasNoErrors();
        $this->assertTrue($colleague->fresh()->is_active);
    }

    /**
     * Deleting a colleague who is already suspended is allowed — the
     * "last active admin" rule only protects accounts that are still
     * active, and this one is not.
     */
    public function test_admin_can_delete_a_suspended_colleague(): void
    {
        $colleague = $this->createAdmin(['is_active' => false]);

        $response = $this->actingAs($this->admin, 'admin')
            ->delete("/admin/admins/{$colleague->id}");

        $response->assertRedirect(route('admin.admins.index'));
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('admins', ['id' => $colleague->id]);
    }

    // ─── The last active admin stays protected ───────────────────────────────

    /**
     * The last active admin cannot be deleted.
     *
     * The rule is checked on the controller directly here, with a suspended
     * account in the guard: over HTTP the request would be turned away by
     * the middleware first, because the account behind the session has to
     * be active to reach the panel at all. Calling the controller pins the
     * guard itself, which is the piece that has to keep working.
     */
    public function test_the_last_active_admin_cannot_be_deleted(): void
    {
        // The signed-in admin is suspended, so the account under attack is
        // the only active one left.
        $this->admin->update(['is_active' => false]);
        $lastActive = $this->createAdmin(['is_active' => true]);

        Auth::guard('admin')->login($this->admin);

        app(AdminController::class)->destroy(request(), $lastActive);

        $this->assertDatabaseHas('admins', ['id' => $lastActive->id]);
        $this->assertTrue($lastActive->fresh()->is_active);
    }

    /**
     * The same for the status switch: the last active admin cannot be
     * suspended.
     */
    public function test_the_last_active_admin_cannot_be_deactivated(): void
    {
        $this->admin->update(['is_active' => false]);
        $lastActive = $this->createAdmin(['is_active' => true]);

        Auth::guard('admin')->login($this->admin);

        app(AdminController::class)->toggleActive(request(), $lastActive);

        $this->assertTrue($lastActive->fresh()->is_active);
    }

    /**
     * The panel can never be left without a way in. Suspending the last
     * colleague is allowed — one active admin remains — but that remaining
     * admin cannot then switch themselves off, so the count never drops to
     * zero.
     */
    public function test_the_panel_never_ends_up_without_an_active_admin(): void
    {
        $colleague = $this->createAdmin();

        $this->actingAs($this->admin, 'admin')
            ->patch("/admin/admins/{$colleague->id}/toggle")
            ->assertSessionHasNoErrors();

        $this->assertFalse($colleague->fresh()->is_active);
        $this->assertTrue($this->admin->fresh()->is_active);

        $this->actingAs($this->admin, 'admin')
            ->patch("/admin/admins/{$this->admin->id}/toggle")
            ->assertSessionHasErrors('admin');

        $this->assertTrue($this->admin->fresh()->is_active);
        $this->assertSame(
            1,
            Admin::where('is_active', true)->count(),
            'Exactly one active admin must remain.'
        );
    }

    public function test_the_topbar_shows_the_signed_in_admin(): void
    {
        // The topbar used to say "Admin" to everybody, so an operator
        // could not tell whose session they were looking at. It now
        // carries the name of the account that is actually signed in.
        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Signed In Admin')
            ->assertSee('abo-admin-topbar__admin-name', false);
    }
}
