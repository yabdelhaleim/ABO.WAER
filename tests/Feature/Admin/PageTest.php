<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
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
}
