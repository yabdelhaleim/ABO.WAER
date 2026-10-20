<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\Size;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;

    protected Category $category;

    protected Color $color;

    protected Size $size;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Product Admin',
            'email' => 'product@store.com',
            'password' => 'password',
            'is_active' => true,
        ]);

        $this->category = Category::create([
            'name' => 'Hoodies',
            'slug' => 'hoodies',
        ]);

        $this->color = Color::create([
            'name' => 'Black',
            'hex_code' => '#000000',
        ]);

        $this->size = Size::create([
            'name' => 'M',
            'group' => 'tops',
        ]);
    }

    public function test_guest_cannot_view_products_page(): void
    {
        $response = $this->get('/admin/products');
        $response->assertRedirect(route('admin.login'));
    }

    public function test_admin_can_view_products_page(): void
    {
        Product::create([
            'name' => 'Oversize Hoodie',
            'slug' => 'oversize-hoodie',
            'category_id' => $this->category->id,
        ]);

        $response = $this->actingAs($this->admin, 'admin')->get('/admin/products');

        $response->assertOk();
        $response->assertSee('Oversize Hoodie');
        $response->assertSee('Hoodies');
    }

    public function test_admin_can_view_create_form(): void
    {
        $response = $this->actingAs($this->admin, 'admin')->get('/admin/products/create');

        $response->assertOk();
        $response->assertSee('ADD PRODUCT');
        $response->assertSee('Hoodies');
        $response->assertSee('Black');
    }

    public function test_admin_can_create_product_with_variants_and_images(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->image('cover.jpg');

        $response = $this->actingAs($this->admin, 'admin')->post('/admin/products', [
            'name' => 'Essential Tee',
            'name_ar' => 'تيشيرت أساسي',
            'slug' => 'essential-tee',
            'category_id' => $this->category->id,
            'description' => 'Combed cotton tee.',
            'is_archived' => false,
            'is_featured' => true,
            'colors' => [$this->color->id],
            'variants' => [
                [
                    'color_id' => $this->color->id,
                    'size_id' => $this->size->id,
                    'price' => 250,
                    'cost' => 120,
                    'discount_price' => 199,
                    'quantity' => 15,
                ],
            ],
            'images' => [
                $this->color->id => [$file],
            ],
        ]);

        $response->assertRedirect(route('admin.products.index'));

        $this->assertDatabaseHas('products', [
            'name' => 'Essential Tee',
            'slug' => 'essential-tee',
            'category_id' => $this->category->id,
            'is_featured' => 1,
        ]);

        $product = Product::where('slug', 'essential-tee')->first();
        $this->assertNotNull($product);

        $this->assertDatabaseHas('product_colors', [
            'product_id' => $product->id,
            'color_id' => $this->color->id,
        ]);

        $this->assertDatabaseHas('variants', [
            'product_id' => $product->id,
            'color_id' => $this->color->id,
            'size_id' => $this->size->id,
            'price' => 250,
            'cost' => 120,
            'discount_price' => 199,
            'quantity' => 15,
        ]);

        $this->assertDatabaseCount('product_images', 1);
        $image = $product->images()->first();
        Storage::disk('public')->assertExists($image->path);
        Storage::disk('public')->assertExists($image->thumb_path);
    }

    public function test_product_creation_validates_required_fields(): void
    {
        $response = $this->actingAs($this->admin, 'admin')->postJson('/admin/products', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'slug']);
    }

    public function test_product_slug_must_be_unique(): void
    {
        Product::create([
            'name' => 'First Product',
            'slug' => 'unique-slug',
        ]);

        $response = $this->actingAs($this->admin, 'admin')->postJson('/admin/products', [
            'name' => 'Second Product',
            'slug' => 'unique-slug',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('slug');
    }

    public function test_admin_can_update_product_and_upsert_variants(): void
    {
        $product = Product::create([
            'name' => 'Starter Tee',
            'slug' => 'starter-tee',
            'category_id' => $this->category->id,
        ]);
        $product->colors()->attach($this->color->id);
        $product->variants()->create([
            'color_id' => $this->color->id,
            'size_id' => $this->size->id,
            'sku' => 'STARTER-C'.$this->color->id.'-S'.$this->size->id,
            'price' => 100,
            'cost' => 50,
            'quantity' => 5,
        ]);

        $response = $this->actingAs($this->admin, 'admin')->putJson("/admin/products/{$product->id}", [
            'name' => 'Starter Tee v2',
            'slug' => 'starter-tee',
            'category_id' => $this->category->id,
            'description' => 'Updated description',
            'colors' => [$this->color->id],
            'variants' => [
                [
                    'color_id' => $this->color->id,
                    'size_id' => $this->size->id,
                    'price' => 175,
                    'cost' => 80,
                    'discount_price' => 150,
                    'quantity' => 9,
                ],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Starter Tee v2',
            'description' => 'Updated description',
        ]);

        $this->assertDatabaseCount('variants', 1);
        $variant = Variant::where('product_id', $product->id)->first();
        $this->assertSame(175.0, (float) $variant->price);
        $this->assertSame(80.0, (float) $variant->cost);
        $this->assertSame(150.0, (float) $variant->discount_price);
        $this->assertSame(9, (int) $variant->quantity);
    }

    public function test_admin_can_toggle_product_archive_flag(): void
    {
        $product = Product::create([
            'name' => 'Discontinued',
            'slug' => 'discontinued',
            'is_archived' => false,
        ]);

        $response = $this->actingAs($this->admin, 'admin')
            ->patchJson("/admin/products/{$product->id}/toggle-archive");

        $response->assertOk();
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'is_archived' => 1,
        ]);

        $this->actingAs($this->admin, 'admin')
            ->patchJson("/admin/products/{$product->id}/toggle-archive");

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'is_archived' => 0,
        ]);
    }

    public function test_admin_can_delete_product_and_images(): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->image('front.jpg');

        $product = Product::create([
            'name' => 'Limited Edition',
            'slug' => 'limited-edition',
        ]);
        $product->colors()->attach($this->color->id);
        $productColor = $product->productColors()->first();

        $stored = DB::transaction(function () use ($productColor) {
            return $productColor->images()->create([
                'path' => 'products/limited/front.jpg',
                'thumb_path' => 'products/limited/thumbs/front.jpg',
                'sort_order' => 0,
            ]);
        });
        // Manually create files on disk so we can verify deletion.
        Storage::disk('public')->put('products/limited/front.jpg', 'fake');
        Storage::disk('public')->put('products/limited/thumbs/front.jpg', 'fake');

        $response = $this->actingAs($this->admin, 'admin')
            ->deleteJson("/admin/products/{$product->id}");

        $response->assertOk();
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('product_colors', ['product_id' => $product->id]);
        Storage::disk('public')->assertMissing('products/limited/front.jpg');
        Storage::disk('public')->assertMissing('products/limited/thumbs/front.jpg');
    }

    public function test_products_index_groups_in_real_categories(): void
    {
        Category::create(['name' => 'Pants', 'slug' => 'pants']);
        Product::create([
            'name' => 'Cargo Pants',
            'slug' => 'cargo-pants',
            'category_id' => $this->category->id,
        ]);

        $response = $this->actingAs($this->admin, 'admin')->get('/admin/products');

        $response->assertOk();
        $response->assertSee('Hoodies', false);
        $response->assertSee('Pants', false);
    }
}
