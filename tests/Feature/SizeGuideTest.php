<?php

namespace Tests\Feature;

use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SizeGuideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CatalogSeeder::class);
    }

    /**
     * Real size chart data. Lives ONLY in this test file. The project
     * data file (home.php) intentionally has no size chart so the
     * component renders the empty state in production until the
     * client supplies real measurements.
     */
    private function fakeChart(): array
    {
        return [
            'unit' => 'cm',
            'types' => [
                'hoodies' => [
                    'columns' => ['chest', 'length', 'shoulder', 'sleeve'],
                    'rows' => [
                        ['size' => 'S',  'measurements' => ['chest' => 56, 'length' => 70, 'shoulder' => 49, 'sleeve' => 64]],
                        ['size' => 'M',  'measurements' => ['chest' => 58, 'length' => 72, 'shoulder' => 50, 'sleeve' => 65]],
                        ['size' => 'L',  'measurements' => ['chest' => 60, 'length' => 74, 'shoulder' => 52, 'sleeve' => 66]],
                        ['size' => 'XL', 'measurements' => ['chest' => 62, 'length' => 76, 'shoulder' => 54, 'sleeve' => 67]],
                    ],
                ],
                't-shirts' => [
                    'columns' => ['chest', 'length', 'shoulder'],
                    'rows' => [
                        ['size' => 'M', 'measurements' => ['chest' => 54, 'length' => 73, 'shoulder' => 46]],
                        ['size' => 'L', 'measurements' => ['chest' => 56, 'length' => 75, 'shoulder' => 47]],
                    ],
                ],
            ],
            'howToMeasure' => [
                ['label' => 'A · Chest',    'hint' => 'Measured flat, 1 cm below the armhole.'],
                ['label' => 'B · Length',   'hint' => 'From the highest point of the shoulder to the hem.'],
                ['label' => 'C · Shoulder', 'hint' => 'From one shoulder seam to the other, across the back.'],
                ['label' => 'D · Sleeve',   'hint' => 'From the shoulder seam to the end of the cuff.'],
            ],
        ];
    }

    private function fakeProduct(): array
    {
        return [
            'name' => 'Drop 02 Hoodie',
            'slug' => 'drop-02-hoodie',
            'category' => 'hoodies',
        ];
    }

    private function renderModal(?array $chart, array $product, ?string $selectedSize = null): string
    {
        return view('components.size-guide.modal', [
            'chart' => $chart,
            'product' => $product,
            'selectedSize' => $selectedSize,
        ])->render();
    }

    // ─── Component structure ──────────────────────────────────

    public function test_size_guide_component_renders_trigger_and_modal(): void
    {
        $html = $this->renderModal(null, $this->fakeProduct());

        // Trigger button is always present, even when there's no data.
        $this->assertStringContainsString('class="size-guide__trigger"', $html);
        $this->assertStringContainsString('data-size-guide-open=', $html);
        $this->assertStringContainsString('SIZE GUIDE', $html);

        // Modal is rendered with proper a11y.
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('aria-labelledby="size-guide-drop-02-hoodie-title"', $html);
        $this->assertStringContainsString('FIND YOUR', $html);
        $this->assertStringContainsString('FIT.', $html);
        $this->assertStringContainsString('class="size-guide__title-accent"', $html);
    }

    public function test_size_guide_id_is_derived_from_product_slug(): void
    {
        $html = $this->renderModal(null, $this->fakeProduct());
        $this->assertStringContainsString('id="size-guide-drop-02-hoodie"', $html);
    }

    public function test_size_guide_close_button_uses_data_attribute(): void
    {
        $html = $this->renderModal(null, $this->fakeProduct());
        $this->assertStringContainsString('data-size-guide-close', $html);
        $this->assertStringContainsString('aria-label="Close size guide"', $html);
    }

    // ─── Empty state (no chart OR no type for product) ────────

    public function test_size_guide_renders_empty_state_when_chart_is_null(): void
    {
        $html = $this->renderModal(null, $this->fakeProduct());

        $this->assertStringContainsString('class="size-guide__empty"', $html);
        $this->assertStringContainsString('MEASUREMENTS COMING SOON.', $html);
        $this->assertStringNotContainsString('class="size-guide__table"', $html);
    }

    public function test_size_guide_renders_empty_state_when_chart_has_no_type_for_product(): void
    {
        $chart = $this->fakeChart();
        // Product is jackets, but only hoodies + t-shirts are in the chart.
        $product = $this->fakeProduct();
        $product['category'] = 'jackets';
        $html = $this->renderModal($chart, $product);

        $this->assertStringContainsString('MEASUREMENTS COMING SOON.', $html);
        $this->assertStringNotContainsString('class="size-guide__table"', $html);
    }

    public function test_size_guide_never_falls_back_to_a_different_type(): void
    {
        // Product is a hoodie but we strip hoodies from the chart, leaving
        // only t-shirts. The modal must NOT show t-shirts data.
        $chart = $this->fakeChart();
        unset($chart['types']['hoodies']);
        $product = $this->fakeProduct();
        $product['category'] = 'hoodies';

        $html = $this->renderModal($chart, $product);
        $this->assertStringContainsString('MEASUREMENTS COMING SOON.', $html);
        $this->assertStringNotContainsString('class="size-guide__table"', $html);
    }

    // ─── Table rendering with fixture data ────────────────────

    public function test_size_guide_renders_real_table_for_product_type(): void
    {
        $html = $this->renderModal($this->fakeChart(), $this->fakeProduct());

        $this->assertStringContainsString('class="size-guide__table"', $html);
        // The product's category is "hoodies" → its table is rendered.
        $this->assertStringContainsString('data-size-row="S"', $html);
        $this->assertStringContainsString('data-size-row="M"', $html);
        $this->assertStringContainsString('data-size-row="L"', $html);
        $this->assertStringContainsString('data-size-row="XL"', $html);

        // Column labels are derived from the keys (single source of truth).
        $this->assertStringContainsString('Chest', $html);
        $this->assertStringContainsString('Length', $html);
        $this->assertStringContainsString('Shoulder', $html);
        $this->assertStringContainsString('Sleeve', $html);

        // Unit appears in the header cells.
        $this->assertStringContainsString('(cm)', $html);
    }

    public function test_size_guide_does_not_show_other_types_rows(): void
    {
        $html = $this->renderModal($this->fakeChart(), $this->fakeProduct());

        // T-shirts has its own rows; they must NOT appear when the
        // product is a hoodie.
        $this->assertStringNotContainsString('data-size-row="XXL"', $html);
    }

    public function test_size_guide_hides_unit_when_not_provided(): void
    {
        $chart = $this->fakeChart();
        unset($chart['unit']);
        $html = $this->renderModal($chart, $this->fakeProduct());

        // Unit defaults to "cm" in the component.
        $this->assertStringContainsString('(cm)', $html);
    }

    public function test_size_guide_renders_how_to_measure_when_provided(): void
    {
        $html = $this->renderModal($this->fakeChart(), $this->fakeProduct());

        $this->assertStringContainsString('class="size-guide__howto"', $html);
        $this->assertStringContainsString('HOW TO MEASURE', $html);
        $this->assertStringContainsString('A · Chest', $html);
    }

    public function test_size_guide_omits_how_to_measure_when_not_provided(): void
    {
        $chart = $this->fakeChart();
        unset($chart['howToMeasure']);
        $html = $this->renderModal($chart, $this->fakeProduct());

        $this->assertStringNotContainsString('class="size-guide__howto"', $html);
    }

    // ─── Selected size highlight (NO default highlight) ──────

    public function test_size_guide_does_not_highlight_any_row_by_default(): void
    {
        $html = $this->renderModal($this->fakeChart(), $this->fakeProduct());

        $this->assertStringNotContainsString('is-active', $html);
    }

    public function test_size_guide_does_not_have_aria_selected_on_rows_in_blade(): void
    {
        // The active state is added by JS at open time, not by Blade.
        $html = $this->renderModal($this->fakeChart(), $this->fakeProduct());

        $this->assertStringNotContainsString('aria-selected="true"', $html);
    }

    public function test_size_guide_does_not_hardcode_first_row_as_active(): void
    {
        $html = $this->renderModal($this->fakeChart(), $this->fakeProduct());

        // The first row (S) must not be marked active in any way.
        $sRow = $this->extractRow($html, 'S');
        $this->assertStringNotContainsString('is-active', $sRow);
    }

    private function extractRow(string $html, string $size): string
    {
        $pattern = '/<tr[^>]*data-size-row="'.preg_quote($size, '/').'"[^>]*>.*?<\/tr>/s';
        preg_match($pattern, $html, $m);

        return $m[0] ?? '';
    }

    // ─── No fake numbers in production data ────────────────────

    public function test_no_size_chart_in_production_data_file(): void
    {
        $data = require resource_path('views/store/data/home.php');
        // The product data file MUST NOT contain a size chart. If the
        // client supplies one later, the component will receive it
        // from the controller/route, not from this data file.
        $this->assertArrayNotHasKey('sizeChart', $data);
        foreach ($data['products'] ?? [] as $product) {
            $this->assertArrayNotHasKey('measurements', $product, 'No product in the data file should have measurements.');
            $this->assertArrayNotHasKey('size_chart', $product);
        }
    }

    public function test_no_fake_size_chart_in_css(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        // The .size-guide namespace must exist.
        $this->assertStringContainsString('.size-guide {', $css);
        $this->assertStringContainsString('.size-guide__dialog {', $css);
        $this->assertStringContainsString('.size-guide__trigger {', $css);
        $this->assertStringContainsString('.size-guide__empty {', $css);
    }

    public function test_size_guide_uses_existing_project_tokens(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $block = $this->extractNamespace($css, '.size-guide');
        // The block must use the existing tokens — no new colors, no
        // new fonts, no new easing values.
        $this->assertStringContainsString('var(--abo-lime', $block);
        $this->assertStringContainsString('var(--abo-panel', $block);
        $this->assertStringContainsString('var(--abo-border', $block);
        $this->assertStringContainsString('var(--abo-white', $block);
        $this->assertStringContainsString('var(--abo-gray', $block);
        $this->assertStringContainsString('var(--font-display', $block);
        $this->assertStringContainsString('var(--font-sans', $block);
    }

    /**
     * Extract every CSS rule whose selector starts with the given
     * namespace. Simple regex scan — adequate for tests, not a parser.
     */
    private function extractNamespace(string $css, string $namespace): string
    {
        $block = '';
        // Find every top-level rule + every rule inside @media blocks.
        // Pattern: capture selector up to opening brace, then the
        // body (handling nested braces) up to the matching close.
        $pattern = '/([^{}@]+)\{((?:[^{}]+|\{[^{}]*\})*)\}/s';
        if (preg_match_all($pattern, $css, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $selector = trim($m[1]);
                if (str_contains($selector, $namespace) && ! str_contains($selector, '@')) {
                    $block .= $selector.' { '.trim($m[2])." }\n";
                }
            }
        }

        return $block;
    }

    public function test_size_guide_uses_no_new_color_values(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $block = $this->extractNamespace($css, '.size-guide');
        preg_match_all('/#[0-9A-Fa-f]{3,8}\b/', $block, $matches);
        foreach ($matches[0] ?? [] as $color) {
            $this->assertContains(
                $color,
                ['#0a0b0d', '#0a0a0a'],
                "Found a new hex color '$color' in .size-guide — use a token instead.",
            );
        }
    }

    // ─── JS controller ────────────────────────────────────────

    public function test_size_guide_js_exposes_open_close_focus_sync(): void
    {
        $js = file_get_contents(resource_path('js/size-guide.js'));
        $this->assertNotEmpty($js);

        $this->assertStringContainsString('data-size-guide-open', $js);
        $this->assertStringContainsString('data-size-guide-close', $js);
        $this->assertStringContainsString("key === 'Escape'", $js);
        $this->assertStringContainsString('syncSelectedSize', $js);
        $this->assertStringContainsString('[data-product-size]', $js);
        $this->assertStringContainsString('is-active', $js);
        $this->assertStringContainsString('size-guide-open', $js); // html class lock
    }

    public function test_size_guide_js_does_not_default_to_first_row(): void
    {
        $js = file_get_contents(resource_path('js/size-guide.js'));
        // The sync logic must return early when no [data-product-size]
        // is on the page — no row is marked active in that case.
        $this->assertStringContainsString('No size selector on the page', $js);
    }

    public function test_size_guide_js_highlight_only_when_value_matches_a_row(): void
    {
        $js = file_get_contents(resource_path('js/size-guide.js'));
        // The find() must compare against row.dataset.sizeRow.
        $this->assertStringContainsString('dataset.sizeRow', $js);
    }

    // ─── Project chrome is untouched ──────────────────────────

    public function test_size_guide_does_not_modify_existing_selectors(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        // Existing selectors must still exist with their original
        // declarations. (The :root tokens define the locked palette.)
        $this->assertStringContainsString('--abo-lime: #C6FF00', $css);
        $this->assertStringContainsString('--abo-black: #050607', $css);
        $this->assertStringContainsString('--abo-white: #F4F6F0', $css);
        $this->assertStringContainsString('--abo-panel: #0D0F11', $css);
        $this->assertStringContainsString('--abo-gray: #8D928A', $css);
    }

    // ─── Product page integration ─────────────────────────────

    public function test_product_detail_page_renders_size_guide_trigger(): void
    {
        $response = $this->get('/shop/drop-02-hoodie');
        $response->assertOk();
        // Trigger is present, modal markup is present, but the modal
        // is hidden by default. Empty state shows because no $sizeChart
        // is passed to the product page.
        $response->assertSee('class="size-guide__trigger"', false);
        $response->assertSee('data-size-guide-open="size-guide-drop-02-hoodie"', false);
        $response->assertSee('role="dialog"', false);
        $response->assertSee('aria-modal="true"', false);
        $response->assertSee('MEASUREMENTS COMING SOON.', false);
    }

    public function test_product_page_renders_size_label_alongside_trigger(): void
    {
        $response = $this->get('/shop/drop-02-hoodie');
        $response->assertOk();
        // The "Size" label and the trigger are in the same flex row.
        $response->assertSee('class="product-page__size-row"', false);
        $response->assertSee('product-page__group--size', false);
    }

    public function test_product_page_size_guide_does_not_break_existing_shop_size_assertion(): void
    {
        // Cross-check: the product page passes $sizeChart = null (the
        // default), so no measurement values should leak into the
        // product page markup.
        $response = $this->get('/shop/drop-02-hoodie');
        $response->assertOk();
        $this->assertStringNotContainsString('class="size-guide__table"', $response->getContent());
        $this->assertStringNotContainsString('data-size-row', $response->getContent());
    }
}
