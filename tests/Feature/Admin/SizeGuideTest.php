<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Category;
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

    public function test_admin_can_create_size_guide_with_rows(): void
    {
        $response = $this->actingAs($this->admin, 'admin')->postJson('/admin/size-guides', [
            'name' => 'Oversize Tee Guide',
            'name_ar' => 'دليل مقاسات التيشيرت الأوفرسايز',
            'category_id' => $this->category->id,
            'description' => 'Measurements for our oversize tees.',
            'is_active' => true,
            'rows' => [
                ['size_name' => 'M', 'chest' => 56, 'length' => 72, 'shoulder' => 48, 'sleeve' => 22],
                ['size_name' => 'L', 'chest' => 58, 'length' => 74, 'shoulder' => 50, 'sleeve' => 23],
                ['size_name' => 'XL', 'chest' => 60, 'length' => 76, 'shoulder' => 52, 'sleeve' => 24],
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
        $this->assertDatabaseCount('size_guide_rows', 3);
        $this->assertDatabaseHas('size_guide_rows', [
            'size_guide_id' => $guide->id,
            'size_name' => 'M',
            'chest' => 56,
            'length' => 72,
            'shoulder' => 48,
            'sleeve' => 22,
        ]);
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
        $response->assertJsonValidationErrors(['category_id']);
    }

    public function test_admin_can_update_size_guide_and_sync_rows(): void
    {
        $guide = SizeGuide::create([
            'category_id' => $this->category->id,
            'name' => 'Original Guide',
        ]);

        $rowM = $guide->rows()->create(['size_name' => 'M', 'chest' => 50, 'length' => 68]);
        $rowL = $guide->rows()->create(['size_name' => 'L', 'chest' => 52, 'length' => 70]);

        $response = $this->actingAs($this->admin, 'admin')
            ->putJson("/admin/size-guides/{$guide->id}", [
                'name' => 'Updated Guide',
                'category_id' => $this->category->id,
                'is_active' => false,
                'rows' => [
                    // keep M (updated), drop L, add new XL
                    ['id' => $rowM->id, 'size_name' => 'M', 'chest' => 51, 'length' => 69],
                    ['size_name' => 'XL', 'chest' => 55, 'length' => 73],
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
            'size_guide_id' => $guide->id,
            'size_name' => 'M',
            'chest' => 51,
            'length' => 69,
        ]);
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

        $guide->rows()->create(['size_name' => 'S', 'chest' => 48, 'length' => 66]);

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
                    ['size_name' => '32', 'chest' => null, 'length' => 100],
                ],
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('size_guides', [
            'name' => 'Pants Guide',
            'name_ar' => 'دليل المقاسات للبناطيل',
        ]);
    }
}
