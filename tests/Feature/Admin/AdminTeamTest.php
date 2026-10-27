<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Http\Middleware\EnsureAdminPermission;
use App\Models\Admin;
use App\Models\AdminActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The back-office team: roles, what they may reach, and the rules that
 * keep the panel from locking itself out.
 *
 * The interface used to describe the roles in JavaScript only — the
 * chips, the 15x5 matrix, the "you can't change your own account here"
 * note. Every one of those was a drawing. The rules now live on the
 * server, in App\Enums\AdminRole and the middleware that enforces it, and
 * these tests call the routes directly: a crafted request is refused
 * exactly the way a click is.
 *
 * @see AdminRole
 * @see EnsureAdminPermission
 */
class AdminTeamTest extends TestCase
{
    use RefreshDatabase;

    private Admin $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->makeAdmin('Owner', 'owner@store.com', AdminRole::Owner);
    }

    /**
     * Build an account with a role.
     */
    private function makeAdmin(string $name, string $email, AdminRole $role, bool $isActive = true): Admin
    {
        return Admin::create([
            'name' => $name,
            'email' => $email,
            'password' => 'password',
            'role' => $role->value,
            'is_active' => $isActive,
        ]);
    }

    // ─── The role map ─────────────────────────────────────────────────────────

    /**
     * A viewer may look at the catalogue but may not change it.
     */
    public function test_a_viewer_may_read_a_module_but_not_write_to_it(): void
    {
        $viewer = $this->makeAdmin('Viewer', 'viewer@store.com', AdminRole::Viewer);

        $this->actingAs($viewer, 'admin')
            ->get('/admin/products')
            ->assertOk();

        $this->actingAs($viewer, 'admin')
            ->post('/admin/products', ['name' => 'Sneaky', 'slug' => 'sneaky'])
            ->assertForbidden();
    }

    /**
     * A content editor has no reach into the orders screen at all — the
     * module is closed to them, not merely read-only.
     */
    public function test_a_module_the_role_cannot_reach_is_refused_entirely(): void
    {
        $editor = $this->makeAdmin('Editor', 'editor@store.com', AdminRole::ContentEditor);

        $this->actingAs($editor, 'admin')
            ->get('/admin/orders')
            ->assertForbidden();

        $this->actingAs($editor, 'admin')
            ->get('/admin/products')
            ->assertOk();
    }

    /**
     * The orders staff own the orders and the customers, and nothing else.
     */
    public function test_the_orders_staff_own_the_orders_and_the_customers(): void
    {
        $staff = $this->makeAdmin('Staff', 'staff@store.com', AdminRole::OrdersStaff);

        $this->actingAs($staff, 'admin')->get('/admin/orders')->assertOk();
        $this->actingAs($staff, 'admin')->get('/admin/customers')->assertOk();
        $this->actingAs($staff, 'admin')->get('/admin/categories')->assertForbidden();
    }

    /**
     * A manager may look at the admins list but may not touch it: the
     * "manage" level on that module belongs to the owner alone.
     */
    public function test_only_the_owner_manages_the_back_office_itself(): void
    {
        $manager = $this->makeAdmin('Manager', 'manager@store.com', AdminRole::Manager);

        $this->actingAs($manager, 'admin')->get('/admin/admins')->assertOk();

        $this->actingAs($manager, 'admin')
            ->post('/admin/admins', [
                'name' => 'Sneaky',
                'email' => 'sneaky@store.com',
                'role' => 'owner',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('admins', ['email' => 'sneaky@store.com']);
    }

    /**
     * The owner reaches every module, write included.
     */
    public function test_the_owner_reaches_every_module(): void
    {
        $this->actingAs($this->owner, 'admin')->get('/admin/orders')->assertOk();
        $this->actingAs($this->owner, 'admin')->get('/admin/admins')->assertOk();
        $this->actingAs($this->owner, 'admin')->get('/admin/settings')->assertOk();
    }

    /**
     * A role the application does not know must not inherit the owner's
     * powers. The column can only hold the five values, but a row that
     * arrives from an import must not be handed the keys to everything.
     */
    public function test_an_unknown_role_is_treated_as_the_least_privileged_one(): void
    {
        $stranger = $this->makeAdmin('Stranger', 'stranger@store.com', AdminRole::Viewer);
        $stranger->update(['role' => 'superuser']);

        $this->assertSame(AdminRole::Viewer, $stranger->fresh()->role);

        $this->actingAs($stranger, 'admin')
            ->get('/admin/orders')
            ->assertOk();

        $this->actingAs($stranger, 'admin')
            ->post('/admin/products', ['name' => 'Sneaky', 'slug' => 'sneaky'])
            ->assertForbidden();
    }

    // ─── The panel never locks itself out ────────────────────────────────────

    /**
     * The last active owner cannot be demoted.
     */
    public function test_the_last_active_owner_cannot_be_demoted(): void
    {
        $this->actingAs($this->owner, 'admin')
            ->put("/admin/admins/{$this->owner->id}", [
                'name' => $this->owner->name,
                'email' => $this->owner->email,
                'role' => 'viewer',
            ])
            ->assertSessionHasErrors('admin');

        $this->assertSame(AdminRole::Owner, $this->owner->fresh()->role);
    }

    /**
     * The last active owner cannot be suspended.
     */
    public function test_the_last_active_owner_cannot_be_suspended(): void
    {
        $this->actingAs($this->owner, 'admin')
            ->patch("/admin/admins/{$this->owner->id}/toggle")
            ->assertSessionHasErrors('admin');

        $this->assertTrue($this->owner->fresh()->is_active);
    }

    /**
     * The last active owner cannot be removed.
     */
    public function test_the_last_active_owner_cannot_be_deleted(): void
    {
        $this->actingAs($this->owner, 'admin')
            ->delete("/admin/admins/{$this->owner->id}")
            ->assertSessionHasErrors('admin');

        $this->assertDatabaseHas('admins', ['id' => $this->owner->id]);
    }

    /**
     * With a second owner in place, the first one may step down.
     */
    public function test_an_owner_may_step_down_when_another_one_remains(): void
    {
        $second = $this->makeAdmin('Second Owner', 'second.owner@store.com', AdminRole::Owner);

        $this->actingAs($this->owner, 'admin')
            ->put("/admin/admins/{$this->owner->id}", [
                'name' => $this->owner->name,
                'email' => $this->owner->email,
                'role' => 'manager',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(AdminRole::Manager, $this->owner->fresh()->role);
        $this->assertSame(AdminRole::Owner, $second->fresh()->role);
    }

    /**
     * An account that is not an owner cannot re-role anybody, including
     * itself — the request never reaches the rule, the permission stops
     * it first.
     */
    public function test_an_account_that_is_not_an_owner_cannot_change_any_role(): void
    {
        $manager = $this->makeAdmin('Manager', 'manager@store.com', AdminRole::Manager);

        $this->actingAs($manager, 'admin')
            ->put("/admin/admins/{$manager->id}", [
                'name' => $manager->name,
                'email' => $manager->email,
                'role' => 'owner',
            ])
            ->assertForbidden();

        $this->assertSame(AdminRole::Manager, $manager->fresh()->role);
    }

    // ─── Addresses ───────────────────────────────────────────────────────────

    /**
     * The address is stored in lower case, and a second spelling of it is
     * refused as a duplicate.
     */
    public function test_the_address_is_stored_in_lower_case_and_is_unique_regardless_of_case(): void
    {
        $this->actingAs($this->owner, 'admin')
            ->post('/admin/admins', [
                'name' => 'Mixed Case',
                'email' => 'Mixed.Case@Store.com',
                'role' => 'viewer',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('admins', ['email' => 'mixed.case@store.com']);

        $this->actingAs($this->owner, 'admin')
            ->post('/admin/admins', [
                'name' => 'Same Address',
                'email' => 'MIXED.CASE@store.com',
                'role' => 'viewer',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame(1, Admin::where('email', 'mixed.case@store.com')->count());
    }

    /**
     * The sign-in folds the address down too, so a differently-cased
     * spelling of a real address still finds the account.
     */
    public function test_the_sign_in_folds_the_address_to_lower_case(): void
    {
        $this->owner->update(['email' => 'owner@store.com']);

        $this->post('/admin/login', [
            'email' => 'Owner@Store.com',
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($this->owner, 'admin');
    }

    /**
     * The sign-in stamps the row with the moment it happened.
     */
    public function test_the_sign_in_stamps_the_last_login(): void
    {
        $this->assertNull($this->owner->last_login_at);

        $this->post('/admin/login', [
            'email' => 'owner@store.com',
            'password' => 'password',
        ]);

        $this->assertNotNull($this->owner->fresh()->last_login_at);
    }

    // ─── The trail ───────────────────────────────────────────────────────────

    /**
     * Every change to an account leaves a row behind: who did it, what
     * they did and to which account.
     */
    public function test_every_change_to_an_account_is_recorded(): void
    {
        $this->actingAs($this->owner, 'admin')
            ->post('/admin/admins', [
                'name' => 'Recorded',
                'email' => 'recorded@store.com',
                'role' => 'viewer',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);

        $created = Admin::where('email', 'recorded@store.com')->firstOrFail();

        $this->actingAs($this->owner, 'admin')
            ->put("/admin/admins/{$created->id}", [
                'name' => 'Recorded Renamed',
                'email' => 'recorded@store.com',
                'role' => 'manager',
            ]);

        $this->actingAs($this->owner, 'admin')
            ->patch("/admin/admins/{$created->id}/toggle");

        $this->actingAs($this->owner, 'admin')
            ->delete("/admin/admins/{$created->id}");

        $actions = AdminActivity::query()
            ->where('admin_id', $this->owner->id)
            ->pluck('action')
            ->all();

        $this->assertContains('admin.created', $actions);
        $this->assertContains('admin.updated', $actions);
        $this->assertContains('admin.suspended', $actions);
        $this->assertContains('admin.deleted', $actions);
    }

    /**
     * The trail keeps the address of the caller, so an action can be
     * traced back to where it came from.
     */
    public function test_the_trail_keeps_the_address_of_the_caller(): void
    {
        $this->actingAs($this->owner, 'admin')
            ->post('/admin/admins', [
                'name' => 'Recorded',
                'email' => 'recorded@store.com',
                'role' => 'viewer',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);

        $row = AdminActivity::query()->where('action', 'admin.created')->firstOrFail();

        $this->assertNotNull($row->ip);
        $this->assertSame($this->owner->id, $row->admin_id);
    }
}
