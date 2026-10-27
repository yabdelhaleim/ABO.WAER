<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductColor;
use App\Models\Size;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin catalog used to answer from a hard-coded array of fourteen
 * sample products: the table showed products the store could not sell,
 * the filters compared against labels that no longer existed, and the
 * toggle, the row menu and the bulk bar reported success without
 * writing a single row.
 *
 * These tests pin the contract that replaced it: the list is built
 * from the products table, and every action reaches the database.
 */
class ProductAdminCatalogTest extends TestCase
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
            'name' => 'Catalog Admin',
            'email' => 'catalog@store.com',
            'password' => 'password',
            'is_active' => true,
        ]);

        $this->category = Category::create([
            'name' => 'Winter Hoodies',
            'slug' => 'winter-hoodies',
        ]);

        $this->color = Color::create([
            'name' => 'Onyx',
            'hex_code' => '#0D0F11',
        ]);

        $this->size = Size::create([
            'name' => 'L',
            'group' => 'tops',
        ]);
    }

    /**
     * Give a product one purchasable variant.
     */
    protected function attachVariant(Product $product, float $price = 1890.0, int $stock = 12, ?float $discount = null): Variant
    {
        $pivot = ProductColor::firstOrCreate([
            'product_id' => $product->id,
            'color_id' => $this->color->id,
        ]);

        $variant = Variant::create([
            'product_id' => $product->id,
            'color_id' => $this->color->id,
            'size_id' => $this->size->id,
            'sku' => 'ABO-HD-001',
            'price' => $price,
            'cost' => 760,
            'discount_price' => $discount,
            'quantity' => $stock,
        ]);

        $pivot->setRelation('color', $this->color);

        return $variant;
    }

    protected function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'name' => 'ABO Oversized Heavy Hoodie',
            'name_ar' => 'هودي اوفرسايز ثقيل',
            'slug' => 'abo-oversized-heavy-hoodie',
            'category_id' => $this->category->id,
            'is_archived' => false,
            'is_featured' => false,
        ], $attributes));
    }

    /**
     * Read the payload the admin list hydrates from.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function payload(): array
    {
        $response = $this->actingAs($this->admin, 'admin')->get('/admin/products');

        $response->assertOk();

        preg_match('/window\.__PRODUCTS__\s*=\s*(\[.*?\]);/s', $response->getContent() ?: '', $matches);

        $this->assertArrayHasKey(1, $matches, 'the admin list must publish the catalog payload');

        $decoded = json_decode($matches[1], true);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    // ─── The list is built from the database ────────────────────

    public function test_the_admin_list_publishes_the_products_table(): void
    {
        $product = $this->makeProduct();
        $this->attachVariant($product, 1890.0, 12);

        $payload = $this->payload();

        $this->assertCount(1, $payload);
        $this->assertSame((string) $product->id, $payload[0]['id']);
        $this->assertSame('ABO Oversized Heavy Hoodie', $payload[0]['name']);
        $this->assertSame('هودي اوفرسايز ثقيل', $payload[0]['nameAr']);
    }

    public function test_the_admin_list_carries_no_sample_products(): void
    {
        $this->makeProduct();

        $payload = $this->payload();

        // The fourteen sample products of the old static array must not
        // come back when the database is empty.
        $this->assertCount(1, $payload);
        $this->assertSame('ABO Oversized Heavy Hoodie', $payload[0]['name']);
    }

    public function test_the_payload_prices_the_row_from_the_variant_rows(): void
    {
        $product = $this->makeProduct();
        $this->attachVariant($product, 1890.0, 12);

        $payload = $this->payload();

        $this->assertEquals(1890, $payload[0]['price']);
        $this->assertSame(12, $payload[0]['stock']);
        $this->assertSame(1, $payload[0]['variantsCount']);
        $this->assertSame('ABO-HD-001', $payload[0]['sku']);
    }

    public function test_the_payload_carries_the_real_colours(): void
    {
        $product = $this->makeProduct();
        $this->attachVariant($product);

        $payload = $this->payload();

        $this->assertCount(1, $payload[0]['colors']);
        $this->assertSame('Onyx', $payload[0]['colors'][0]['name']);
        $this->assertSame('#0D0F11', $payload[0]['colors'][0]['hex']);
    }

    public function test_the_payload_marks_an_archived_product(): void
    {
        $archived = $this->makeProduct([
            'name' => 'Retired Hoodie',
            'slug' => 'retired-hoodie',
            'is_archived' => true,
        ]);
        $this->attachVariant($archived);

        $payload = $this->payload();

        $this->assertSame('archived', $payload[0]['status']);
        $this->assertTrue($payload[0]['archived']);
    }

    public function test_the_payload_carries_the_category_id_the_filter_uses(): void
    {
        $product = $this->makeProduct();
        $this->attachVariant($product);

        $payload = $this->payload();

        $this->assertSame((string) $this->category->id, $payload[0]['categoryId']);
        $this->assertSame('Winter Hoodies', $payload[0]['category']);
    }

    public function test_the_admin_list_does_not_render_the_sample_catalog(): void
    {
        $response = $this->actingAs($this->admin, 'admin')->get('/admin/products');

        $response->assertOk();
        $response->assertDontSee('Oversize hoodie');
        $response->assertDontSee('Limo bomber');
    }

    // ─── The actions reach the database ─────────────────────────

    public function test_the_toggle_archives_a_product(): void
    {
        $product = $this->makeProduct();
        $this->attachVariant($product);

        $response = $this->actingAs($this->admin, 'admin')
            ->patchJson("/admin/products/{$product->id}/toggle-archive");

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $this->assertTrue($product->fresh()->is_archived);
    }

    public function test_a_guest_cannot_reach_the_bulk_endpoint(): void
    {
        $product = $this->makeProduct();

        $this->postJson('/admin/products/bulk', [
            'action' => 'archive',
            'ids' => [$product->id],
        ])->assertRedirect(route('admin.login'));

        $this->assertFalse($product->fresh()->is_archived);
    }

    public function test_the_bulk_endpoint_archives_the_selected_products(): void
    {
        $first = $this->makeProduct();
        $second = $this->makeProduct(['name' => 'Second Hoodie', 'slug' => 'second-hoodie']);
        $untouched = $this->makeProduct(['name' => 'Third Hoodie', 'slug' => 'third-hoodie']);

        $response = $this->actingAs($this->admin, 'admin')->postJson('/admin/products/bulk', [
            'action' => 'archive',
            'ids' => [$first->id, $second->id],
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true, 'action' => 'archive', 'affected' => 2]);

        $this->assertTrue($first->fresh()->is_archived);
        $this->assertTrue($second->fresh()->is_archived);
        $this->assertFalse($untouched->fresh()->is_archived);
    }

    public function test_the_bulk_endpoint_activates_the_selected_products(): void
    {
        $product = $this->makeProduct(['is_archived' => true]);

        $this->actingAs($this->admin, 'admin')->postJson('/admin/products/bulk', [
            'action' => 'activate',
            'ids' => [$product->id],
        ])->assertOk();

        $this->assertFalse($product->fresh()->is_archived);
    }

    public function test_the_bulk_endpoint_moves_the_selected_products_to_another_category(): void
    {
        $product = $this->makeProduct();
        $target = Category::create(['name' => 'Jackets', 'slug' => 'jackets']);

        $this->actingAs($this->admin, 'admin')->postJson('/admin/products/bulk', [
            'action' => 'category',
            'ids' => [$product->id],
            'category_id' => $target->id,
        ])->assertOk();

        $this->assertSame($target->id, $product->fresh()->category_id);
    }

    public function test_the_bulk_endpoint_deletes_the_selected_products(): void
    {
        $product = $this->makeProduct();

        $this->actingAs($this->admin, 'admin')->postJson('/admin/products/bulk', [
            'action' => 'delete',
            'ids' => [$product->id],
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertNull(Product::find($product->id));
    }

    public function test_the_bulk_endpoint_rejects_an_unknown_action(): void
    {
        $product = $this->makeProduct();

        $this->actingAs($this->admin, 'admin')->postJson('/admin/products/bulk', [
            'action' => 'obliterate',
            'ids' => [$product->id],
        ])->assertStatus(422);

        $this->assertNotNull(Product::find($product->id));
    }

    public function test_the_bulk_endpoint_rejects_an_empty_selection(): void
    {
        $this->actingAs($this->admin, 'admin')->postJson('/admin/products/bulk', [
            'action' => 'archive',
            'ids' => [],
        ])->assertStatus(422);
    }

    public function test_the_bulk_endpoint_needs_a_category_for_the_move(): void
    {
        $product = $this->makeProduct();

        $this->actingAs($this->admin, 'admin')->postJson('/admin/products/bulk', [
            'action' => 'category',
            'ids' => [$product->id],
        ])->assertStatus(422);
    }

    // ─── The form is the database, not a sample ─────────────────

    public function test_the_edit_form_shows_the_product_it_edits(): void
    {
        $product = $this->makeProduct();
        $this->attachVariant($product, 1890.0, 12);

        $response = $this->actingAs($this->admin, 'admin')->get("/admin/products/{$product->id}/edit");

        $response->assertOk();
        $response->assertSee('value="ABO Oversized Heavy Hoodie"', false);
        $response->assertSee('value="abo-oversized-heavy-hoodie"', false);
        $response->assertSee('name="variants[0][color_id]"', false);
        $response->assertSee('name="variants[0][size_id]"', false);
        $response->assertSee('name="variants[0][quantity]"', false);
    }

    public function test_the_edit_form_offers_the_database_colours_and_sizes(): void
    {
        $product = $this->makeProduct();
        $this->attachVariant($product);

        $response = $this->actingAs($this->admin, 'admin')->get("/admin/products/{$product->id}/edit");

        $response->assertOk();
        $response->assertSee('name="colors[]"', false);
        $response->assertSee('name="sizes[]"', false);
        // The colour of the product comes from the colours table, not
        // from a hard-coded swatch list.
        $response->assertSee('value="'.$this->color->id.'"', false);
        $response->assertSee('Onyx', false);
    }

    public function test_the_edit_form_never_prints_the_sample_colours(): void
    {
        $product = $this->makeProduct();

        $response = $this->actingAs($this->admin, 'admin')->get("/admin/products/{$product->id}/edit");

        $response->assertOk();
        $response->assertDontSee('data-abo-admin-products-colour="black"', false);
        $response->assertDontSee('data-abo-admin-products-variant-size="S"', false);
    }

    public function test_saving_a_product_keeps_the_variants_it_already_had(): void
    {
        $product = $this->makeProduct();
        $variant = $this->attachVariant($product, 1890.0, 12);

        $response = $this->actingAs($this->admin, 'admin')->put("/admin/products/{$product->id}", [
            'name' => 'ABO Oversized Heavy Hoodie',
            'slug' => 'abo-oversized-heavy-hoodie',
            'category_id' => $this->category->id,
            'is_archived' => 0,
            'colors' => [$this->color->id],
            'sizes' => [$this->size->id],
            'variants' => [[
                'color_id' => $this->color->id,
                'size_id' => $this->size->id,
                'price' => 1890,
                'cost' => 760,
                'quantity' => 12,
            ]],
        ]);

        $response->assertRedirect(route('admin.products.index'));

        $product->refresh();

        $this->assertCount(1, $product->variants);
        $this->assertSame(12, (int) $product->variants->first()->quantity);
        $this->assertSame($this->color->id, $product->variants->first()->color_id);
        $this->assertNotNull($variant->fresh());
    }

    public function test_saving_a_product_without_variants_fails_validation(): void
    {
        $product = $this->makeProduct();
        $this->attachVariant($product, 1890.0, 12);

        // Every product must have at least one variant with color and size.
        $response = $this->actingAs($this->admin, 'admin')->put("/admin/products/{$product->id}", [
            'name' => 'ABO Oversized Heavy Hoodie',
            'slug' => 'abo-oversized-heavy-hoodie',
            'category_id' => $this->category->id,
            'is_archived' => 0,
            'colors' => [$this->color->id],
            'variants' => [],
        ]);

        $response->assertSessionHasErrors('variants');
        $this->assertCount(1, $product->fresh()->variants);
    }

    // ─── Regression Tests ────────────────────────────────────────

    public function test_updating_product_variants_never_deletes_variants_of_other_products(): void
    {
        // Product A with color1 and size1
        $productA = $this->makeProduct(['name' => 'Product A', 'slug' => 'product-a']);
        $this->attachVariant($productA, 500.0, 10);

        // Product B with color2 and size2 (completely different)
        $colorB = Color::create(['name' => 'Navy', 'hex_code' => '#000080', 'is_active' => true]);
        $sizeB = Size::create(['name' => 'XL', 'group' => 'tops', 'is_active' => true]);
        $productB = $this->makeProduct(['name' => 'Product B', 'slug' => 'product-b']);
        $variantB = Variant::create([
            'product_id' => $productB->id,
            'color_id' => $colorB->id,
            'size_id' => $sizeB->id,
            'sku' => 'PRODUCT-B-VARIANT',
            'price' => 800.0,
            'cost' => 300.0,
            'quantity' => 20,
        ]);

        // Update Product A with a new variant
        $newSize = Size::create(['name' => 'S', 'group' => 'tops', 'is_active' => true]);
        $this->actingAs($this->admin, 'admin')->put("/admin/products/{$productA->id}", [
            'name' => 'Product A',
            'slug' => 'product-a',
            'category_id' => $this->category->id,
            'is_archived' => 0,
            'colors' => [$this->color->id],
            'variants' => [[
                'color_id' => $this->color->id,
                'size_id' => $newSize->id,
                'price' => 550,
                'cost' => 200,
                'quantity' => 5,
            ]],
        ])->assertRedirect(route('admin.products.index'));

        // Crucial assert: Product B's variant MUST still exist!
        $this->assertNotNull($variantB->fresh(), 'Product B variant was erroneously deleted by updating Product A!');
        $this->assertSame(1, $productB->fresh()->variants()->count());
    }

    public function test_updating_product_variants_removes_only_unselected_pairs_accurately(): void
    {
        $product = $this->makeProduct();

        $color1 = $this->color;
        $color2 = Color::create(['name' => 'Beige', 'hex_code' => '#F5F5DC', 'is_active' => true]);
        $size1 = $this->size;
        $size2 = Size::create(['name' => 'L', 'group' => 'tops', 'is_active' => true]);

        // Create 3 pairs: (c1, s1), (c1, s2), (c2, s1)
        $v1 = Variant::create([
            'product_id' => $product->id, 'color_id' => $color1->id, 'size_id' => $size1->id,
            'sku' => 'SKU-1', 'price' => 100, 'cost' => 50, 'quantity' => 5,
        ]);
        $v2 = Variant::create([
            'product_id' => $product->id, 'color_id' => $color1->id, 'size_id' => $size2->id,
            'sku' => 'SKU-2', 'price' => 100, 'cost' => 50, 'quantity' => 5,
        ]);
        $v3 = Variant::create([
            'product_id' => $product->id, 'color_id' => $color2->id, 'size_id' => $size1->id,
            'sku' => 'SKU-3', 'price' => 100, 'cost' => 50, 'quantity' => 5,
        ]);

        // Submit only (c1, s1) and (c2, s1) -> removing (c1, s2)
        // Notice that color1 and size2 are still present in other variants or request!
        $this->actingAs($this->admin, 'admin')->put("/admin/products/{$product->id}", [
            'name' => $product->name,
            'slug' => $product->slug,
            'category_id' => $this->category->id,
            'is_archived' => 0,
            'colors' => [$color1->id, $color2->id],
            'variants' => [
                ['color_id' => $color1->id, 'size_id' => $size1->id, 'price' => 100, 'cost' => 50, 'quantity' => 5],
                ['color_id' => $color2->id, 'size_id' => $size1->id, 'price' => 100, 'cost' => 50, 'quantity' => 5],
            ],
        ])->assertRedirect(route('admin.products.index'));

        $this->assertNotNull($v1->fresh(), 'Variant (c1, s1) should remain');
        $this->assertNull($v2->fresh(), 'Variant (c1, s2) should have been deleted');
        $this->assertNotNull($v3->fresh(), 'Variant (c2, s1) should remain');
    }

    public function test_variant_discount_price_must_be_less_than_regular_price(): void
    {
        $product = $this->makeProduct();

        $response = $this->actingAs($this->admin, 'admin')->put("/admin/products/{$product->id}", [
            'name' => 'ABO Oversized Heavy Hoodie',
            'slug' => 'abo-oversized-heavy-hoodie',
            'category_id' => $this->category->id,
            'is_archived' => 0,
            'colors' => [$this->color->id],
            'variants' => [[
                'color_id' => $this->color->id,
                'size_id' => $this->size->id,
                'price' => 500,
                'cost' => 200,
                'discount_price' => 600, // Invalid: discount_price >= price
                'quantity' => 10,
            ]],
        ]);

        $response->assertSessionHasErrors('variants.0.discount_price');
    }

    public function test_saving_a_product_rewrites_the_sku_from_the_slug(): void
    {
        $product = $this->makeProduct();
        $this->attachVariant($product);

        $this->actingAs($this->admin, 'admin')->put("/admin/products/{$product->id}", [
            'name' => 'ABO Oversized Heavy Hoodie',
            'slug' => 'abo-oversized-heavy-hoodie',
            'category_id' => $this->category->id,
            'is_archived' => 0,
            'colors' => [$this->color->id],
            'sizes' => [$this->size->id],
            'variants' => [[
                'color_id' => $this->color->id,
                'size_id' => $this->size->id,
                'price' => 1890,
                'cost' => 760,
                'quantity' => 12,
            ]],
        ])->assertRedirect(route('admin.products.index'));

        // The rule the form mirrors when it derives a SKU: the slug,
        // upper-cased and without a separator, then the colour and
        // the size of the row.
        $this->assertSame(
            'ABOOVERSIZEDHEAVYHOODIE-C'.$this->color->id.'-S'.$this->size->id,
            $product->fresh()->variants->first()->sku
        );
    }

    // ─── The export is the same catalog ─────────────────────────

    public function test_the_export_serves_the_catalog_as_csv(): void
    {
        $product = $this->makeProduct();
        $this->attachVariant($product, 1890.0, 12);

        $response = $this->actingAs($this->admin, 'admin')->get('/admin/products/export');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Discount Price', $csv);
        $this->assertStringContainsString('ABO Oversized Heavy Hoodie', $csv);
        $this->assertStringContainsString('Winter Hoodies', $csv);
        $this->assertStringContainsString('1890', $csv);
    }

    public function test_a_guest_cannot_export_the_catalog(): void
    {
        $this->get('/admin/products/export')->assertRedirect(route('admin.login'));
    }
}
