<?php

namespace Tests\Feature\Store;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\Variant;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The checkout page renders the order summary twice — a mobile copy
 * inside the collapsible panel and the desktop rail.
 *
 * Both copies have to carry the same numbers, so the ids they expose
 * must be unique on the page and every copy must expose the data
 * attributes checkout.js paints into.
 */
class CheckoutSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_page_renders_no_duplicate_ids(): void
    {
        $response = $this->get(route('checkout.show'));

        $response->assertOk();

        $xpath = $this->xpath($response->getContent());

        $ids = [];
        foreach ($xpath->query('//*[@id]') as $node) {
            $ids[] = $node->getAttribute('id');
        }

        $duplicates = array_keys(array_filter(
            array_count_values($ids),
            fn (int $count): bool => $count > 1,
        ));

        $this->assertSame(
            [],
            $duplicates,
            'The checkout page must not render the same id twice: '.implode(', ', $duplicates),
        );
    }

    public function test_both_summary_copies_expose_the_hooks_the_javascript_paints_into(): void
    {
        $response = $this->get(route('checkout.show'));

        $response->assertOk();

        $xpath = $this->xpath($response->getContent());

        $this->assertSame(2, $xpath->query('//*[@data-checkout-summary]')->length);
        $this->assertSame(2, $xpath->query('//*[@data-checkout-summary-items]')->length);
        $this->assertSame(2, $xpath->query('//*[@data-checkout-summary-count]')->length);
        $this->assertSame(2, $xpath->query('//*[@data-checkout-summary-subtotal]')->length);
        $this->assertSame(2, $xpath->query('//*[@data-checkout-summary-total]')->length);
    }

    public function test_each_summary_copy_is_labelled_by_an_element_that_exists(): void
    {
        $response = $this->get(route('checkout.show'));

        $response->assertOk();

        $xpath = $this->xpath($response->getContent());

        $asides = $xpath->query('//aside[@data-checkout-summary]');
        $this->assertSame(2, $asides->length);

        $labelledBy = [];

        foreach ($asides as $aside) {
            $reference = $aside->getAttribute('aria-labelledby');
            $ariaLabel = $aside->getAttribute('aria-label');

            if ($reference !== '') {
                $this->assertSame(
                    1,
                    $xpath->query('//*[@id="'.$reference.'"]')->length,
                    'aria-labelledby must point at an element that exists: '.$reference,
                );
            } else {
                $this->assertNotSame(
                    '',
                    $ariaLabel,
                    'A summary without a visible heading still needs an accessible name.',
                );
            }

            $labelledBy[] = $reference;
        }

        // Neither copy may borrow the other copy's heading — that is
        // what left the mobile panel named by a heading it does not
        // contain.
        $this->assertSame(
            $labelledBy,
            array_unique($labelledBy),
            'Each summary copy must be labelled by its own element.',
        );
    }

    public function test_the_summary_is_rendered_again_once_the_cart_has_an_item(): void
    {
        $product = Product::factory()->create(['is_archived' => false]);
        $variant = Variant::factory()->create([
            'product_id' => $product->id,
            'quantity' => 5,
            'price' => 300.00,
        ]);

        CartItem::create([
            'session_id' => 'checkout-summary-test',
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
        ]);

        $response = $this->get(route('checkout.show'));

        $response->assertOk();

        $xpath = $this->xpath($response->getContent());

        // The item count and the total are painted in by the browser,
        // so the server only has to publish the hooks — twice.
        $this->assertSame(2, $xpath->query('//*[@data-checkout-summary-count]')->length);
        $this->assertSame(2, $xpath->query('//*[@data-checkout-summary-total]')->length);
    }

    /**
     * Parse the rendered page and hand back an XPath query handle.
     */
    protected function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;

        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }
}
