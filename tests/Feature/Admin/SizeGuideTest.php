<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Category;
use App\Models\Size;
use App\Models\SizeGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SizeGuideTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Size Guide Admin',
            'email' => 'sizeguide@store.com',
            'password' => 'password',
            'is_active' => true,
        ]);

        $this->category = Category::create([
            'name' => 'Hoodies',
            'slug' => 'hoodies',
        ]);
    }

    public function test_guest_cannot_view_size_guides_page(): void
    {
        $this->get('/admin/size-guides')
            ->assertRedirect(route('admin.login'));
    }

    public function test_admin_can_view_size_guides_index(): void
    {
        SizeGuide::create([
            'category_id' => $this->category->id,
            'name' => 'Hoodie Guide',
        ]);

        $response = $this->actingAs($this->admin, 'admin')->get('/admin/size-guides');

        $response->assertOk();
        $response->assertSee('SIZE', false);
        $response->assertSee('GUIDES', false);
    }

    /**
     * The screen draws the guides the database holds — not a list
     * written into the view.
     */
    public function test_the_screen_draws_the_guides_the_database_holds(): void
    {
        SizeGuide::create([
            'category_id' => $this->category->id,
            'name' => 'Oversize Tee Guide',
            'measurements' => ['Chest', 'Length'],
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin, 'admin')->get('/admin/size-guides');

        $response->assertOk();

        $payload = json_decode(
            (string) $response->viewData('payload')->toJson(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertCount(1, $payload);
        $this->assertSame('Oversize Tee Guide', $payload[0]['name']);
        $this->assertSame(['Chest', 'Length'], $payload[0]['measurements']);
        $this->assertTrue($payload[0]['active']);
    }

    public function test_admin_can_create_size_guide_with_rows(): void
    {
        $response = $this->actingAs($this->admin, 'admin')->postJson('/admin/size-guides', [
            'name' => 'Oversize Tee Guide',
            'name_ar' => 'دليل مقاسات التيشيرت الأوفرسايز',
            'category_id' => $this->category->id,
            'description' => 'Measurements for our oversize tees.',
            'note' => 'Measurements are in cm.',
            'unit' => 'cm',
            'measurements' => ['Chest', 'Length', 'Shoulder'],
            'is_active' => true,
            'rows' => [
                ['size_name' => 'M', 'measurements' => ['Chest' => 56, 'Length' => 72, 'Shoulder' => 48]],
                ['size_name' => 'L', 'measurements' => ['Chest' => 58, 'Length' => 74, 'Shoulder' => 50]],
                ['size_name' => 'XL', 'measurements' => ['Chest' => 60, 'Length' => 76, 'Shoulder' => 52]],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('size_guides', [
            'name' => 'Oversize Tee Guide',
            'name_ar' => 'دليل مقاسات التيشيرت الأوفرسايز',
            'category_id' => $this->category->id,
            'is_active' => 1,
        ]);

        $guide = SizeGuide::where('name', 'Oversize Tee Guide')->first();
        $this->assertNotNull($guide);
        $this->assertSame(['Chest', 'Length', 'Shoulder'], $guide->measurements);
        $this->assertDatabaseCount('size_guide_rows', 3);

        $row = $guide->rows()->where('size_name', 'M')->first();
        $this->assertNotNull($row);
        $this->assertEquals(['Chest' => 56, 'Length' => 72, 'Shoulder' => 48], $row->measurements);
    }

    /**
     * The answer carries the guide in the shape the editor draws, so
     * the browser shows what the server stored.
     */
    public function test_the_answer_carries_the_saved_guide(): void
    {
        $response = $this->actingAs($this->admin, 'admin')->postJson('/admin/size-guides', [
            'name' => 'Pants Guide',
            'measurements' => ['Waist', 'Hip'],
            'rows' => [
                ['size_name' => '32', 'measurements' => ['Waist' => 72, 'Hip' => 92]],
            ],
        ]);

        $response->assertStatus(201);

        $guide = $response->json('guide');

        $this->assertSame('Pants Guide', $guide['name']);
        $this->assertSame(['Waist', 'Hip'], $guide['measurements']);
        $this->assertSame(['32'], $guide['sizes']);
        $this->assertSame('32', $guide['rows'][0]['size_name']);
        $this->assertSame(['Waist' => '72', 'Hip' => '92'], $guide['rows'][0]['measurements']);
    }

    public function test_size_guide_creation_validates_required_fields(): void
    {
        $response = $this->actingAs($this->admin, 'admin')
            ->postJson('/admin/size-guides', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_size_guide_validates_category_exists(): void
    {
        $response = $this->actingAs($this->admin, 'admin')
            ->postJson('/admin/size-guides', [
                'name' => 'Mystery Guide',
                'category_id' => 99999,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('category_id');
    }

    public function test_admin_can_update_size_guide_and_sync_rows(): void
    {
        $guide = SizeGuide::create([
            'category_id' => $this->category->id,
            'name' => 'Original Guide',
            'measurements' => ['Chest', 'Length'],
        ]);

        $rowM = $guide->rows()->create([
            'size_name' => 'M',
            'measurements' => ['Chest' => 50, 'Length' => 68],
        ]);
        $rowL = $guide->rows()->create([
            'size_name' => 'L',
            'measurements' => ['Chest' => 52, 'Length' => 70],
        ]);

        $response = $this->actingAs($this->admin, 'admin')
            ->putJson("/admin/size-guides/{$guide->id}", [
                'name' => 'Updated Guide',
                'category_id' => $this->category->id,
                'measurements' => ['Chest', 'Length'],
                'is_active' => false,
                'rows' => [
                    // keep M (updated), drop L, add new XL
                    ['id' => $rowM->id, 'size_name' => 'M', 'measurements' => ['Chest' => 51, 'Length' => 69]],
                    ['size_name' => 'XL', 'measurements' => ['Chest' => 55, 'Length' => 73]],
                ],
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('size_guides', [
            'id' => $guide->id,
            'name' => 'Updated Guide',
            'is_active' => 0,
        ]);

        $this->assertDatabaseMissing('size_guide_rows', ['id' => $rowL->id]);
        $this->assertDatabaseHas('size_guide_rows', [
            'id' => $rowM->id,
            'size_guide_id' => $guide->id,
            'size_name' => 'M',
        ]);
        $this->assertEquals(['Chest' => 51, 'Length' => 69], $rowM->fresh()->measurements);
        $this->assertDatabaseHas('size_guide_rows', [
            'size_guide_id' => $guide->id,
            'size_name' => 'XL',
        ]);
        $this->assertDatabaseCount('size_guide_rows', 2);
    }

    public function test_admin_can_delete_size_guide_and_rows(): void
    {
        $guide = SizeGuide::create([
            'category_id' => $this->category->id,
            'name' => 'Doomed Guide',
        ]);

        $guide->rows()->create([
            'size_name' => 'S',
            'measurements' => ['Chest' => 48, 'Length' => 66],
        ]);

        $response = $this->actingAs($this->admin, 'admin')
            ->deleteJson("/admin/size-guides/{$guide->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('size_guides', ['id' => $guide->id]);
        $this->assertDatabaseCount('size_guide_rows', 0);
    }

    public function test_size_guide_supports_arabic_name_and_optional_fields(): void
    {
        $response = $this->actingAs($this->admin, 'admin')
            ->postJson('/admin/size-guides', [
                'name' => 'Pants Guide',
                'name_ar' => 'دليل المقاسات للبناطيل',
                'rows' => [
                    ['size_name' => '32', 'measurements' => ['Waist' => 80]],
                ],
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('size_guides', [
            'name' => 'Pants Guide',
            'name_ar' => 'دليل المقاسات للبناطيل',
        ]);
    }

    // ─── Showing and hiding a guide ─────────────────────────────────

    public function test_admin_can_hide_a_guide(): void
    {
        $guide = SizeGuide::create([
            'name' => 'Hoodie Guide',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin, 'admin')
            ->patchJson("/admin/size-guides/{$guide->id}/toggle", ['is_active' => false]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('guide.active', false);

        $this->assertFalse($guide->fresh()->is_active);
    }

    public function test_admin_can_show_a_guide_again(): void
    {
        $guide = SizeGuide::create([
            'name' => 'Hoodie Guide',
            'is_active' => false,
        ]);

        $this->actingAs($this->admin, 'admin')
            ->patchJson("/admin/size-guides/{$guide->id}/toggle", ['is_active' => true])
            ->assertOk();

        $this->assertTrue($guide->fresh()->is_active);
    }

    // ─── Duplicating a guide ─────────────────────────────────────────

    public function test_admin_can_duplicate_a_guide_with_its_chart(): void
    {
        $guide = SizeGuide::create([
            'category_id' => $this->category->id,
            'name' => 'Hoodie Guide',
            'measurements' => ['Chest', 'Length'],
            'is_active' => true,
        ]);

        $guide->rows()->create([
            'size_name' => 'M',
            'measurements' => ['Chest' => 54, 'Length' => 68],
        ]);

        $response = $this->actingAs($this->admin, 'admin')
            ->postJson("/admin/size-guides/{$guide->id}/duplicate");

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);

        $copy = $response->json('guide');

        $this->assertNotSame($guide->id, $copy['id']);
        $this->assertSame('Hoodie Guide (copy)', $copy['name']);
        // The copy is a draft: it is hidden and carries no category.
        $this->assertFalse($copy['active']);
        $this->assertNull($copy['category_id']);
        // The chart came with it.
        $this->assertSame(['M'], $copy['sizes']);
        $this->assertSame(['Chest' => '54', 'Length' => '68'], $copy['rows'][0]['measurements']);

        $this->assertDatabaseCount('size_guides', 2);
        $this->assertDatabaseCount('size_guide_rows', 2);
    }

    /**
     * The size palette the editor offers comes from the shop's own
     * sizes, not from a list written into the view.
     */
    public function test_the_size_palette_comes_from_the_sizes_table(): void
    {
        Size::create(['name' => 'M', 'group' => 'tops', 'is_active' => true]);
        Size::create(['name' => 'L', 'group' => 'tops', 'is_active' => true]);
        Size::create(['name' => 'XXL', 'group' => 'tops', 'is_active' => false]);

        $response = $this->actingAs($this->admin, 'admin')->get('/admin/size-guides');

        $response->assertOk();

        $sizes = $response->viewData('sizes');

        $this->assertEqualsCanonicalizing(['M', 'L'], $sizes->all());
    }

    /**
     * The panel offers the categories the shop has: the storefront
     * reads the chart of the product's category.
     */
    public function test_the_panel_offers_the_categories_the_shop_has(): void
    {
        Category::create(['name' => 'Jackets', 'slug' => 'jackets']);

        $response = $this->actingAs($this->admin, 'admin')->get('/admin/size-guides');

        $response->assertOk();

        $categories = $response->viewData('categories');

        $this->assertEqualsCanonicalizing(
            ['Hoodies', 'Jackets'],
            $categories->pluck('name')->all(),
        );
    }
}
