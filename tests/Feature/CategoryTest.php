<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Super Admin',
            'email' => 'admin@store.com',
            'password' => 'password',
            'is_active' => true,
        ]);
    }

    public function test_guest_cannot_access_categories_page(): void
    {
        $response = $this->get('/admin/categories');
        $response->assertRedirect(route('admin.login'));
    }

    public function test_admin_can_view_categories_page(): void
    {
        Category::create([
            'name' => 'Hoodies',
            'slug' => 'hoodies',
        ]);

        $response = $this->actingAs($this->admin, 'admin')->get('/admin/categories');

        $response->assertOk();
        $response->assertSee('Hoodies');
    }

    public function test_admin_can_create_parent_category(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->image('category.jpg');

        $response = $this->actingAs($this->admin, 'admin')->postJson('/admin/categories', [
            'name' => 'T-Shirts',
            'name_ar' => 'تيشيرتات',
            'slug' => 't-shirts',
            'description' => 'Best summer tees',
            'image' => $file,
            'is_active' => true,
            'is_visible' => true,
            'show_in_homepage' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'category' => [
                'name' => 'T-Shirts',
                'slug' => 't-shirts',
            ],
        ]);

        $this->assertDatabaseHas('categories', [
            'name' => 'T-Shirts',
            'slug' => 't-shirts',
            'parent_id' => null,
        ]);

        $category = Category::where('slug', 't-shirts')->first();
        $this->assertNotNull($category->image);
        Storage::disk('public')->assertExists($category->image);
    }

    public function test_admin_can_create_subcategory(): void
    {
        $parent = Category::create([
            'name' => 'Pants',
            'slug' => 'pants',
        ]);

        $response = $this->actingAs($this->admin, 'admin')->postJson('/admin/categories', [
            'name' => 'Cargo Pants',
            'slug' => 'cargo-pants',
            'parent_id' => $parent->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('categories', [
            'name' => 'Cargo Pants',
            'slug' => 'cargo-pants',
            'parent_id' => $parent->id,
        ]);
    }

    public function test_admin_can_update_category(): void
    {
        $category = Category::create([
            'name' => 'Jackets',
            'slug' => 'jackets',
            'description' => 'Old description',
        ]);

        $response = $this->actingAs($this->admin, 'admin')->putJson("/admin/categories/{$category->id}", [
            'name' => 'Winter Jackets',
            'slug' => 'winter-jackets',
            'description' => 'Updated description',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => 'Winter Jackets',
            'slug' => 'winter-jackets',
        ]);
    }

    public function test_category_cannot_be_its_own_parent(): void
    {
        $category = Category::create([
            'name' => 'Hoodies',
            'slug' => 'hoodies',
        ]);

        $response = $this->actingAs($this->admin, 'admin')->putJson("/admin/categories/{$category->id}", [
            'name' => 'Hoodies',
            'slug' => 'hoodies',
            'parent_id' => $category->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('parent_id');
    }

    public function test_admin_can_delete_category(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->image('cover.jpg');
        $path = $file->store('categories', 'public');

        $category = Category::create([
            'name' => 'Accessories',
            'slug' => 'accessories',
            'image' => $path,
        ]);

        $response = $this->actingAs($this->admin, 'admin')->deleteJson("/admin/categories/{$category->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_admin_can_switch_a_category_on_and_off(): void
    {
        $category = Category::create([
            'name' => 'Hoodies',
            'slug' => 'hoodies',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin, 'admin')
            ->patchJson("/admin/categories/{$category->id}/toggle")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('category.active', false)
            ->assertJsonPath('category.id', (string) $category->id);

        $this->assertFalse($category->fresh()->is_active);

        $this->actingAs($this->admin, 'admin')
            ->patchJson("/admin/categories/{$category->id}/toggle")
            ->assertOk()
            ->assertJsonPath('category.active', true);

        $this->assertTrue($category->fresh()->is_active);
    }

    public function test_admin_can_reorder_the_category_tree(): void
    {
        $hoodies = Category::create(['name' => 'Hoodies', 'slug' => 'hoodies', 'sort_order' => 0]);
        $pants = Category::create(['name' => 'Pants', 'slug' => 'pants', 'sort_order' => 1]);
        $oversize = Category::create([
            'name' => 'Oversize',
            'slug' => 'oversize',
            'parent_id' => $hoodies->id,
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($this->admin, 'admin')->postJson('/admin/categories/reorder', [
            'categories' => [
                ['id' => $pants->id, 'parent_id' => null, 'sort_order' => 0],
                ['id' => $hoodies->id, 'parent_id' => null, 'sort_order' => 1],
                ['id' => $oversize->id, 'parent_id' => $hoodies->id, 'sort_order' => 0],
            ],
        ]);

        $response->assertOk()->assertJsonPath('success', true);

        $this->assertSame(0, $pants->fresh()->sort_order);
        $this->assertSame(1, $hoodies->fresh()->sort_order);
        $this->assertSame($hoodies->id, $oversize->fresh()->parent_id);
    }

    public function test_reordering_refuses_to_move_a_category_under_its_own_child(): void
    {
        $parent = Category::create(['name' => 'Hoodies', 'slug' => 'hoodies']);
        $child = Category::create([
            'name' => 'Oversize',
            'slug' => 'oversize',
            'parent_id' => $parent->id,
        ]);

        $this->actingAs($this->admin, 'admin')
            ->postJson('/admin/categories/reorder', [
                'categories' => [
                    ['id' => $parent->id, 'parent_id' => $child->id, 'sort_order' => 0],
                    ['id' => $child->id, 'parent_id' => $parent->id, 'sort_order' => 0],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertNull($parent->fresh()->parent_id);
    }

    public function test_the_categories_page_carries_the_product_count(): void
    {
        $category = Category::create(['name' => 'Hoodies', 'slug' => 'hoodies']);

        $response = $this->actingAs($this->admin, 'admin')->get('/admin/categories');

        $response->assertOk();
        $response->assertSee('"products":0', false);
    }
}
