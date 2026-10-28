<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
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

    /* ─── The screen reads what the server owns ──────────────────── */

    public function test_index_sends_the_banners_in_the_shape_the_screen_reads(): void
    {
        Banner::factory()->create([
            'title' => 'Winter hero',
            'title_ar' => 'بطل الشتاء',
            'placement' => 'hero',
            'alt' => 'A model in a hoodie',
            'text_color' => 'dark',
            'text_position' => 'center',
            'overlay' => 'light',
            'starts_at' => '2026-11-01',
        ]);

        $response = $this->actingAsAdmin()->get(route('admin.banners.index'));

        $response->assertOk();

        $payload = json_decode($this->bannerPayload($response->getContent()), true);

        $this->assertCount(1, $payload);
        $this->assertSame('Winter hero', $payload[0]['title']);
        $this->assertSame('بطل الشتاء', $payload[0]['titleAr']);
        $this->assertSame('hero', $payload[0]['placement']);
        $this->assertTrue($payload[0]['active']);
        $this->assertSame('2026-11-01', $payload[0]['starts']);
        $this->assertSame('A model in a hoodie', $payload[0]['alt']);
        $this->assertSame('dark', $payload[0]['textColor']);
        $this->assertSame('center', $payload[0]['textPosition']);
        $this->assertSame('light', $payload[0]['overlay']);
    }

    public function test_index_sends_the_catalog_for_the_link_pickers(): void
    {
        Category::factory()->create(['name' => 'Hoodies', 'slug' => 'hoodies', 'is_active' => true]);
        Product::factory()->create(['name' => 'Heavyweight Hoodie', 'slug' => 'heavyweight-hoodie']);

        $content = $this->actingAsAdmin()->get(route('admin.banners.index'))->getContent();

        $this->assertStringContainsString('"hoodies"', $content);
        $this->assertStringContainsString('"heavyweight-hoodie"', $content);
    }

    /* ─── Writing the fields the screen edits ───────────────────── */

    public function test_creating_a_banner_stores_the_placement_schedule_and_look(): void
    {
        $this->actingAsAdmin()
            ->post(route('admin.banners.store'), [
                'placement' => 'promo',
                'title' => 'Promo',
                'title_ar' => 'عرض',
                'alt' => 'Promo picture',
                'text_color' => 'dark',
                'text_position' => 'right',
                'overlay' => 'light',
                'starts_at' => '2026-11-01',
                'ends_at' => '2026-11-30',
                'is_active' => 0,
                'image' => UploadedFile::fake()->image('promo.jpg'),
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('banner.placement', 'promo')
            ->assertJsonPath('banner.active', false)
            ->assertJsonPath('banner.starts', '2026-11-01')
            ->assertJsonPath('banner.ends', '2026-11-30')
            ->assertJsonPath('banner.textColor', 'dark');

        $this->assertDatabaseHas('banners', [
            'placement' => 'promo',
            'text_position' => 'right',
            'is_active' => false,
        ]);
    }

    public function test_a_link_to_a_category_is_resolved_into_a_url(): void
    {
        $category = Category::factory()->create(['slug' => 'hoodies', 'is_active' => true]);

        $this->actingAsAdmin()
            ->post(route('admin.banners.store'), [
                'title' => 'Hoodies hero',
                'link_type' => 'category',
                'link_target' => $category->slug,
                'image' => UploadedFile::fake()->image('hero.jpg'),
            ])
            ->assertCreated()
            ->assertJsonPath('banner.linkType', 'category')
            ->assertJsonPath('banner.linkCategory', 'hoodies');

        $this->assertDatabaseHas('banners', [
            'link_type' => 'category',
            'link_target' => 'hoodies',
            'button_url' => route('shop', ['category' => 'hoodies']),
        ]);
    }

    public function test_a_link_to_a_product_that_does_not_exist_is_dropped(): void
    {
        $this->actingAsAdmin()
            ->post(route('admin.banners.store'), [
                'title' => 'Broken link',
                'link_type' => 'product',
                'link_target' => 'gone-forever',
                'image' => UploadedFile::fake()->image('hero.jpg'),
            ])
            ->assertCreated();

        $this->assertDatabaseHas('banners', ['link_type' => 'product', 'button_url' => null]);
    }

    public function test_a_custom_link_must_be_a_path_or_an_https_url(): void
    {
        $this->actingAsAdmin()
            ->postJson(route('admin.banners.store'), [
                'title' => 'Bad link',
                'link_type' => 'url',
                'link_url' => 'javascript:alert(1)',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('link_url');
    }

    public function test_the_schedule_window_must_end_after_it_starts(): void
    {
        $this->actingAsAdmin()
            ->postJson(route('admin.banners.store'), [
                'title' => 'Backwards',
                'starts_at' => '2026-11-30',
                'ends_at' => '2026-11-01',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');
    }

    public function test_an_announcement_can_be_created_without_a_picture(): void
    {
        $this->actingAsAdmin()
            ->post(route('admin.banners.store'), [
                'placement' => 'announcement',
                'title' => 'Free shipping this week',
            ])
            ->assertCreated()
            ->assertJsonPath('banner.placement', 'announcement');

        $this->assertDatabaseHas('banners', [
            'placement' => 'announcement',
            'title' => 'Free shipping this week',
        ]);
    }

    public function test_updating_a_banner_can_remove_its_picture(): void
    {
        $banner = Banner::factory()->create(['image_path' => 'banners/old.jpg']);

        $this->actingAsAdmin()
            ->put(route('admin.banners.update', $banner), [
                'remove_image' => 1,
            ])
            ->assertOk();

        $this->assertNull($banner->fresh()->image_path);
    }

    public function test_duplicating_a_banner_copies_it_switched_off(): void
    {
        $banner = Banner::factory()->create([
            'title' => 'Original',
            'image_path' => 'banners/original.jpg',
            'is_active' => true,
        ]);

        $this->actingAsAdmin()
            ->post(route('admin.banners.duplicate', $banner))
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('banner.active', false);

        $copy = Banner::where('title', 'Original (copy)')->firstOrFail();

        $this->assertSame('banners/original.jpg', $copy->image_path);
        $this->assertFalse($copy->is_active);
        $this->assertNotSame($banner->id, $copy->id);
    }

    /* ─── The storefront reads what the admin wrote ──────────────── */

    public function test_the_home_page_shows_only_the_live_hero_banners(): void
    {
        Banner::factory()->create(['title' => 'Live hero', 'placement' => 'hero']);
        Banner::factory()->create(['title' => 'Hidden hero', 'placement' => 'hero', 'is_active' => false]);
        Banner::factory()->create([
            'title' => 'Scheduled hero',
            'placement' => 'hero',
            'starts_at' => now()->addWeek()->toDateString(),
        ]);
        Banner::factory()->create([
            'title' => 'Expired hero',
            'placement' => 'hero',
            'ends_at' => now()->subWeek()->toDateString(),
        ]);
        Banner::factory()->create(['title' => 'Promo banner', 'placement' => 'promo']);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Live hero', false);
        $response->assertDontSee('Hidden hero', false);
        $response->assertDontSee('Scheduled hero', false);
        $response->assertDontSee('Expired hero', false);
        $response->assertDontSee('Promo banner', false);
    }

    /**
     * The payload the page hands to the browser, out of the inline
     * script the blade writes.
     */
    private function bannerPayload(string $html): string
    {
        preg_match('/window\.__BANNERS__ = (\[.*?\]);/s', $html, $matches);

        return $matches[1] ?? '[]';
    }
}
