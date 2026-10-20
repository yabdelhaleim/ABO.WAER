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
}
