<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Admin::create([
            'name' => 'Pages Admin',
            'email' => 'pages@store.com',
            'password' => 'password',
            'is_active' => true,
        ]);
    }

    private function actingAsAdmin(): static
    {
        return $this->actingAs($this->admin, 'admin');
    }

    public function test_admin_can_view_pages_index(): void
    {
        $this->actingAsAdmin()
            ->get(route('admin.pages.index'))
            ->assertOk()
            ->assertViewIs('admin.pages.index');
    }

    public function test_admin_can_update_page_content(): void
    {
        $page = Page::factory()->create(['slug' => 'about']);

        $this->actingAsAdmin()
            ->putJson(route('admin.pages.update', $page), [
                'title' => 'About ABO Store',
                'content' => 'We are a premium menswear brand.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('pages', [
            'id' => $page->id,
            'title' => 'About ABO Store',
        ]);
    }

    public function test_page_title_is_required(): void
    {
        $page = Page::factory()->create();

        $this->actingAsAdmin()
            ->putJson(route('admin.pages.update', $page), [
                'content' => 'Some content',
            ])
            ->assertUnprocessable();
    }

    public function test_guest_cannot_access_pages_admin(): void
    {
        $this->get(route('admin.pages.index'))
            ->assertRedirect();
    }

    public function test_store_can_show_active_page(): void
    {
        Page::factory()->create([
            'slug' => 'about',
            'title' => 'About Us',
            'content' => 'Our story...',
        ]);

        $this->get(route('about'))
            ->assertOk()
            ->assertViewIs('content');
    }

    public function test_store_returns_404_for_inactive_page(): void
    {
        Page::factory()->create([
            'slug' => 'about',
            'is_active' => false,
        ]);

        $this->get(route('about'))
            ->assertNotFound();
    }

    // ─── The admin list is built from the database ─────────────

    public function test_admin_pages_index_lists_the_database_records(): void
    {
        Page::factory()->create([
            'slug' => 'about',
            'title' => 'A DISTINCT DATABASE TITLE',
            'title_ar' => 'عنوان من قاعدة البيانات',
        ]);

        $response = $this->actingAsAdmin()->get(route('admin.pages.index'));

        $response->assertOk();
        $response->assertSee('A DISTINCT DATABASE TITLE', false);
        $response->assertSee('عنوان من قاعدة البيانات', false);
    }

    public function test_admin_pages_index_contains_no_sample_records(): void
    {
        $response = $this->actingAsAdmin()->get(route('admin.pages.index'));

        $response->assertOk();
        // The six sample pages that used to be generated inside the
        // Blade view (about-us, contact-us, size-care-guide, …).
        $response->assertDontSee('size-care-guide', false);
        $response->assertDontSee('contact-us', false);
        $response->assertDontSee('"about-us"', false);
    }

    // ─── Save round-trip ───────────────────────────────────────

    public function test_admin_update_is_visible_on_the_public_page(): void
    {
        $page = Page::factory()->create([
            'slug' => 'about',
            'title' => 'About Us',
            'content' => 'The old copy.',
        ]);

        $this->actingAsAdmin()
            ->putJson(route('admin.pages.update', $page), [
                'title' => 'About ABO Store',
                'content' => '<p>THE-NEW-COPY</p>',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('pages', [
            'id' => $page->id,
            'content' => '<p>THE-NEW-COPY</p>',
        ]);

        $this->get(route('about'))
            ->assertOk()
            ->assertSee('THE-NEW-COPY', false)
            ->assertDontSee('The old copy.', false);
    }

    public function test_admin_update_can_unpublish_a_page_so_the_storefront_returns_404(): void
    {
        $page = Page::factory()->create(['slug' => 'about', 'is_active' => true]);

        $this->actingAsAdmin()
            ->putJson(route('admin.pages.update', $page), [
                'title' => 'About Us',
                'is_active' => false,
            ])
            ->assertOk();

        $this->assertDatabaseHas('pages', ['id' => $page->id, 'is_active' => false]);

        $this->get(route('about'))->assertNotFound();
    }

    public function test_update_cannot_change_the_slug(): void
    {
        $page = Page::factory()->create(['slug' => 'about']);

        $this->actingAsAdmin()
            ->putJson(route('admin.pages.update', $page), [
                'title' => 'About Us',
                'slug' => 'hijacked',
            ])
            ->assertOk();

        $this->assertDatabaseHas('pages', ['id' => $page->id, 'slug' => 'about']);
        $this->assertDatabaseMissing('pages', ['slug' => 'hijacked']);
    }

    // ─── Authorization ─────────────────────────────────────────

    public function test_guest_cannot_update_page_content(): void
    {
        $page = Page::factory()->create([
            'slug' => 'about',
            'title' => 'About Us',
            'content' => 'Untouched.',
        ]);

        $this->putJson(route('admin.pages.update', $page), [
            'title' => 'Hijacked',
            'content' => 'Hacked.',
        ])
            ->assertRedirect(route('admin.login'));

        $this->assertDatabaseHas('pages', [
            'id' => $page->id,
            'title' => 'About Us',
            'content' => 'Untouched.',
        ]);
    }

    public function test_a_signed_in_customer_cannot_update_page_content(): void
    {
        $page = Page::factory()->create(['slug' => 'about', 'title' => 'About Us']);
        $customer = Customer::factory()->create();

        $this->actingAs($customer)
            ->putJson(route('admin.pages.update', $page), ['title' => 'Hijacked'])
            ->assertRedirect(route('admin.login'));

        $this->assertDatabaseHas('pages', ['id' => $page->id, 'title' => 'About Us']);
    }

    public function test_a_deactivated_admin_cannot_update_page_content(): void
    {
        $page = Page::factory()->create(['slug' => 'about', 'title' => 'About Us']);

        $this->admin->update(['is_active' => false]);

        $this->actingAsAdmin()
            ->putJson(route('admin.pages.update', $page), ['title' => 'Hijacked'])
            ->assertRedirect(route('admin.login'));

        $this->assertDatabaseHas('pages', ['id' => $page->id, 'title' => 'About Us']);
    }

    // ─── Validation ────────────────────────────────────────────

    public function test_update_rejects_a_payload_without_a_title_and_changes_nothing(): void
    {
        $page = Page::factory()->create(['slug' => 'about', 'title' => 'About Us']);

        $this->actingAsAdmin()
            ->putJson(route('admin.pages.update', $page), [
                'content' => 'Some content',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title']);

        $this->assertDatabaseHas('pages', [
            'id' => $page->id,
            'title' => 'About Us',
        ]);
    }

    public function test_update_rejects_a_whitespace_only_title(): void
    {
        $page = Page::factory()->create(['slug' => 'about', 'title' => 'About Us']);

        $this->actingAsAdmin()
            ->putJson(route('admin.pages.update', $page), [
                'title' => '   ',
                'content' => 'Some content',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title']);

        $this->assertDatabaseHas('pages', [
            'id' => $page->id,
            'title' => 'About Us',
        ]);
    }

    // ─── Missing / inactive pages ──────────────────────────────

    public function test_store_returns_404_when_the_page_record_is_missing(): void
    {
        $this->get(route('about'))->assertNotFound();
    }

    public function test_store_returns_404_for_an_inactive_page(): void
    {
        Page::factory()->create([
            'slug' => 'about',
            'is_active' => false,
        ]);

        $this->get(route('about'))->assertNotFound();
    }

    // ─── The editor script ─────────────────────────────────────

    /**
     * The editor may only report success after the server confirmed it.
     *
     * The project ships no JavaScript test runner (package.json has
     * only `build` and `dev`), so the editor script's error contract is
     * guarded here at the source level: every failure status the
     * endpoint can answer with must be handled before the success
     * branch, and the success toast must sit behind the `res.ok` guard.
     */
    public function test_the_editor_script_handles_every_failure_status_before_reporting_success(): void
    {
        $source = file_get_contents(public_path('admin/js/pages.js'));

        $this->assertStringContainsString('res.status === 422', $source);
        $this->assertStringContainsString('res.status === 419', $source);
        $this->assertStringContainsString('res.status === 401', $source);
        $this->assertStringContainsString('res.status === 403', $source);
        // A signed-out admin is redirected to the login page by the
        // `admin` middleware, and fetch follows that redirect.
        $this->assertStringContainsString('res.redirected', $source);

        $guard = strpos($source, 'if (!res.ok)');
        $success = strpos($source, "toast('Saved')");

        $this->assertNotFalse($guard);
        $this->assertNotFalse($success);
        $this->assertGreaterThan($guard, $success);
    }
}
