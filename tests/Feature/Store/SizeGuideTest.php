<?php

namespace Tests\Feature\Store;

use App\Models\Category;
use App\Models\Color;
use App\Models\Product;
use App\Models\Size;
use App\Models\SizeGuide;
use App\Models\SizeGuideRow;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The published size guide on the product page.
 *
 * A guide belongs to a category and a product belongs to a category, so
 * the guide a shopper sees is the published one of the product's own
 * category — never another category's measurements, and never an
 * unpublished draft.
 */
class SizeGuideTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::factory()->create([
            'name' => 'Hoodies',
            'slug' => 'hoodies',
            'is_active' => true,
            'is_visible' => true,
        ]);

        $this->product = Product::factory()->create([
            'name' => 'Drop 01 Hoodie',
            'slug' => 'drop-01-hoodie',
            'category_id' => $this->category->id,
            'is_archived' => false,
        ]);

        $color = Color::factory()->create(['name' => 'Onyx', 'hex_code' => '#0D0F11']);
        $size = Size::factory()->create(['name' => 'L', 'sort_order' => 3, 'is_active' => true]);

        Variant::factory()->create([
            'product_id' => $this->product->id,
            'color_id' => $color->id,
            'size_id' => $size->id,
            'quantity' => 5,
            'price' => 1490.00,
        ]);
    }

    /**
     * Create a guide for the product's category.
     */
    private function guideFor(?Category $category = null, bool $isActive = true, bool $withRows = true): SizeGuide
    {
        $guide = SizeGuide::create([
            'category_id' => ($category ?? $this->category)->id,
            'name' => 'Hoodie Size Chart',
            'name_ar' => 'جدول مقاسات الهودي',
            'description' => 'Chest and length dimensions for an oversized fit.',
            'is_active' => $isActive,
        ]);

        if ($withRows) {
            SizeGuideRow::create([
                'size_guide_id' => $guide->id,
                'size_name' => 'XXL',
                'chest' => 62.5,
                'length' => 74.5,
                'shoulder' => 49.5,
                'sleeve' => 66.5,
                'sort_order' => 0,
            ]);
        }

        return $guide;
    }

    private function productPage(): string
    {
        $response = $this->get(route('shop.show', ['slug' => $this->product->slug]));

        $response->assertOk();

        return $response->getContent() ?: '';
    }

    public function test_product_page_shows_the_published_guide_of_its_category(): void
    {
        $this->guideFor();

        $content = $this->productPage();

        // The table, with one row per measurement row.
        $this->assertStringContainsString('XXL', $content);
        $this->assertStringContainsString('62.5', $content);
        $this->assertStringContainsString('74.5', $content);
        $this->assertStringContainsString('49.5', $content);
        $this->assertStringContainsString('66.5', $content);

        // The column headers come from the measurement keys.
        $this->assertStringContainsString('Chest', $content);
        $this->assertStringContainsString('Length', $content);
        $this->assertStringContainsString('Shoulder', $content);
        $this->assertStringContainsString('Sleeve', $content);

        // A published guide replaces the empty state.
        $this->assertStringNotContainsString('MEASUREMENTS COMING SOON', $content);
    }

    public function test_product_page_without_a_guide_shows_the_empty_state(): void
    {
        $content = $this->productPage();

        $this->assertStringContainsString('MEASUREMENTS COMING SOON', $content);
    }

    public function test_unpublished_guide_is_not_shown(): void
    {
        $this->guideFor(isActive: false);

        $content = $this->productPage();

        $this->assertStringContainsString('MEASUREMENTS COMING SOON', $content);
        $this->assertStringNotContainsString('62.5', $content);
    }

    public function test_guide_of_another_category_is_not_shown(): void
    {
        $other = Category::factory()->create([
            'name' => 'Tees',
            'slug' => 'tees',
            'is_active' => true,
            'is_visible' => true,
        ]);

        $this->guideFor($other);

        $content = $this->productPage();

        // No cross-category fallback: another category's numbers would
        // send the shopper to the wrong size.
        $this->assertStringContainsString('MEASUREMENTS COMING SOON', $content);
        $this->assertStringNotContainsString('62.5', $content);
    }

    public function test_guide_without_measurement_rows_shows_the_empty_state(): void
    {
        $this->guideFor(withRows: false);

        $content = $this->productPage();

        $this->assertStringContainsString('MEASUREMENTS COMING SOON', $content);
    }
}
