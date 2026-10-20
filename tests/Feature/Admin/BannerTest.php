<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Banner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BannerTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Admin::create([
            'name' => 'Banner Admin',
            'email' => 'banners@store.com',
            'password' => 'password',
            'is_active' => true,
        ]);
        Storage::fake('public');
    }

    private function actingAsAdmin(): static
    {
        return $this->actingAs($this->admin, 'admin');
    }

    public function test_admin_can_view_banners_page(): void
    {
        $this->actingAsAdmin()
            ->get(route('admin.banners.index'))
            ->assertOk()
            ->assertViewIs('admin.banners.index');
    }

    public function test_admin_can_create_banner(): void
    {
        $this->actingAsAdmin()
            ->post(route('admin.banners.store'), [
                'title' => 'Summer Sale',
                'image' => UploadedFile::fake()->image('banner.jpg'),
            ])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('banners', ['title' => 'Summer Sale']);
    }

    public function test_banner_requires_image_on_create(): void
    {
        $this->actingAsAdmin()
            ->postJson(route('admin.banners.store'), [
                'title' => 'No Image Banner',
            ])
            ->assertUnprocessable();
    }

    public function test_admin_can_update_banner_without_new_image(): void
    {
        $banner = Banner::factory()->create(['title' => 'Old Title']);

        $this->actingAsAdmin()
            ->put(route('admin.banners.update', $banner), [
                'title' => 'New Title',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('banners', ['id' => $banner->id, 'title' => 'New Title']);
    }

    public function test_admin_can_update_banner_with_new_image(): void
    {
        $banner = Banner::factory()->create();

        $this->actingAsAdmin()
            ->put(route('admin.banners.update', $banner), [
                'title' => 'Updated',
                'image' => UploadedFile::fake()->image('new-banner.jpg'),
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_admin_can_delete_banner(): void
    {
        $banner = Banner::factory()->create();

        $this->actingAsAdmin()
            ->delete(route('admin.banners.destroy', $banner))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('banners', ['id' => $banner->id]);
    }

    public function test_guest_cannot_access_banners(): void
    {
        $this->get(route('admin.banners.index'))
            ->assertRedirect();
    }
}
