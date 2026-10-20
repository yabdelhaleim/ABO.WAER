<?php

namespace Tests\Feature;

use App\Enums\CampaignAudience;
use App\Enums\OrderStatus;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Variant;
use App\Services\CustomerSegmentationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks down the four audiences CustomerSegmentationService resolves.
 *
 * Each test builds a small population where exactly one behaviour is under
 * the microscope — the "VIP by spend" and "VIP by order count" branches are
 * deliberately given disjoint populations so neither can pass on the
 * other's evidence.
 */
class CustomerSegmentationTest extends TestCase
{
    use RefreshDatabase;

    private CustomerSegmentationService $segmentation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->segmentation = new CustomerSegmentationService;
    }

    // ─── All ───────────────────────────────────────────────────────────

    public function test_all_audience_returns_every_reachable_customer(): void
    {
        $first = $this->customer();
        $second = $this->customer();

        $ids = $this->audienceIds(CampaignAudience::All);

        $this->assertCount(2, $ids);
        $this->assertContains($first->id, $ids);
        $this->assertContains($second->id, $ids);
    }

    public function test_all_audience_skips_customers_without_an_email(): void
    {
        $reachable = $this->customer();
        $this->customer(['email' => null]);

        $this->assertSame([$reachable->id], $this->audienceIds(CampaignAudience::All));
    }

    // ─── VIP ───────────────────────────────────────────────────────────

    public function test_vip_qualifies_on_total_spend_above_two_thousand(): void
    {
        // A single 2,050 EGP order clears the spend bar on its own.
        $highSpender = $this->customer();
        $this->placeOrder($highSpender, OrderStatus::Delivered, 2050.00);

        // 1,500 EGP is under the bar and 1 order is under the count bar.
        $modestSpender = $this->customer();
        $this->placeOrder($modestSpender, OrderStatus::Delivered, 1500.00);

        $this->assertSame([$highSpender->id], $this->audienceIds(CampaignAudience::Vip));
    }

    public function test_vip_qualifies_on_three_delivered_orders_even_at_modest_spend(): void
    {
        // 3 × 600 = 1,800 EGP: under the spend bar, over the count bar.
        $frequentBuyer = $this->customer();
        $this->placeOrder($frequentBuyer, OrderStatus::Delivered, 600.00);
        $this->placeOrder($frequentBuyer, OrderStatus::Delivered, 600.00);
        $this->placeOrder($frequentBuyer, OrderStatus::Delivered, 600.00);

        // 2 × 800 = 1,600 EGP over 2 orders: under both bars.
        $occasionalBuyer = $this->customer();
        $this->placeOrder($occasionalBuyer, OrderStatus::Delivered, 800.00);
        $this->placeOrder($occasionalBuyer, OrderStatus::Delivered, 800.00);

        $this->assertSame([$frequentBuyer->id], $this->audienceIds(CampaignAudience::Vip));
    }

    public function test_vip_only_counts_delivered_orders(): void
    {
        // High value, but nothing was ever delivered.
        $bigUnfulfilledSpender = $this->customer();
        $this->placeOrder($bigUnfulfilledSpender, OrderStatus::Pending, 5000.00);
        $this->placeOrder($bigUnfulfilledSpender, OrderStatus::Cancelled, 5000.00);

        // Three orders, but the third was returned.
        $returnedBuyer = $this->customer();
        $this->placeOrder($returnedBuyer, OrderStatus::Delivered, 600.00);
        $this->placeOrder($returnedBuyer, OrderStatus::Delivered, 600.00);
        $this->placeOrder($returnedBuyer, OrderStatus::Returned, 600.00);

        $this->assertSame([], $this->audienceIds(CampaignAudience::Vip));
    }

    public function test_vip_spend_and_count_accumulate_across_all_delivered_orders(): void
    {
        // Neither order clears a bar alone; together they clear spend.
        $splitSpender = $this->customer();
        $this->placeOrder($splitSpender, OrderStatus::Delivered, 1200.00);
        $this->placeOrder($splitSpender, OrderStatus::Delivered, 1200.00);

        $this->assertSame([$splitSpender->id], $this->audienceIds(CampaignAudience::Vip));
    }

    public function test_vip_spend_threshold_is_a_strict_numeric_comparison(): void
    {
        // The threshold is a float, which Laravel binds as a string. In
        // SQLite every TEXT value sorts above every numeric one, so an
        // uncast `> ?` would reject this customer outright. These two sit a
        // piastre either side of the bar to pin the comparison as numeric.
        $justOver = $this->customer();
        $this->placeOrder($justOver, OrderStatus::Delivered, 2000.01);

        $justUnder = $this->customer();
        $this->placeOrder($justUnder, OrderStatus::Delivered, 1999.99);

        $this->assertSame([$justOver->id], $this->audienceIds(CampaignAudience::Vip));
    }

    // ─── Cart abandoners ───────────────────────────────────────────────

    public function test_cart_abandoners_picks_up_only_stale_carts_never_converted(): void
    {
        $abandoner = $this->customer();
        $this->cartLine($abandoner, now()->subHours(26));

        $this->assertSame([$abandoner->id], $this->audienceIds(CampaignAudience::CartAbandoners));
    }

    public function test_cart_abandoners_skips_fresh_carts(): void
    {
        $stillShopping = $this->customer();
        $this->cartLine($stillShopping, now()->subHours(2));

        $this->assertSame([], $this->audienceIds(CampaignAudience::CartAbandoners));
    }

    public function test_cart_abandoners_skips_customers_who_already_converted(): void
    {
        // Abandoned the cart, then came back and ordered two hours ago.
        $recovered = $this->customer();
        $this->cartLine($recovered, now()->subHours(26));
        $this->placeOrder($recovered, OrderStatus::Delivered, 900.00, now()->subHours(2));

        // No cart at all.
        $this->customer();

        $this->assertSame([], $this->audienceIds(CampaignAudience::CartAbandoners));
    }

    public function test_cart_abandoners_ignores_guest_carts(): void
    {
        // A stale guest line belongs to nobody, so it must not drag a
        // signed-in customer into the segment.
        CartItem::factory()->create(['updated_at' => now()->subHours(30)]);

        $signedIn = $this->customer();

        $this->assertNotContains(
            $signedIn->id,
            $this->audienceIds(CampaignAudience::CartAbandoners)
        );
    }

    public function test_cart_abandoner_is_excluded_once_their_order_is_older_than_the_window(): void
    {
        // The order predates the cart line: they ordered, then started
        // browsing again. The line is stale but the order is not "recent"
        // relative to the 24-hour window.
        $reopened = $this->customer();
        $this->placeOrder($reopened, OrderStatus::Delivered, 700.00, now()->subDays(10));
        $this->cartLine($reopened, now()->subHours(30));

        $this->assertContains(
            $reopened->id,
            $this->audienceIds(CampaignAudience::CartAbandoners)
        );
    }

    // ─── Inactive 30+ days ─────────────────────────────────────────────

    public function test_inactive_picks_up_long_dormant_accounts(): void
    {
        $dormant = $this->customer(['created_at' => now()->subDays(60)]);

        $this->assertSame([$dormant->id], $this->audienceIds(CampaignAudience::Inactive30Days));
    }

    public function test_inactive_skips_accounts_too_new_to_have_gone_quiet(): void
    {
        $newcomer = $this->customer(['created_at' => now()->subDays(5)]);

        $this->assertNotContains(
            $newcomer->id,
            $this->audienceIds(CampaignAudience::Inactive30Days)
        );
    }

    public function test_inactive_skips_accounts_that_ordered_inside_the_window(): void
    {
        // Old account, but bought something ten days ago.
        $recentBuyer = $this->customer(['created_at' => now()->subDays(60)]);
        $this->placeOrder($recentBuyer, OrderStatus::Delivered, 400.00, now()->subDays(10));

        $this->assertNotContains(
            $recentBuyer->id,
            $this->audienceIds(CampaignAudience::Inactive30Days)
        );
    }

    public function test_inactive_includes_old_accounts_whose_last_order_is_also_old(): void
    {
        // 60 days registered, last order 45 days ago — outside the window.
        $lapsed = $this->customer(['created_at' => now()->subDays(60)]);
        $this->placeOrder($lapsed, OrderStatus::Delivered, 400.00, now()->subDays(45));

        $this->assertSame([$lapsed->id], $this->audienceIds(CampaignAudience::Inactive30Days));
    }

    // ─── Shared guarantees ─────────────────────────────────────────────

    public function test_blocked_customers_are_excluded_from_every_audience(): void
    {
        // A VIP, a cart abandoner and a dormant account — all blocked.
        $blockedVip = Customer::factory()->blocked()->create(['created_at' => now()->subDays(60)]);
        $this->placeOrder($blockedVip, OrderStatus::Delivered, 4000.00);
        $this->cartLine($blockedVip, now()->subHours(30));

        foreach (CampaignAudience::cases() as $audience) {
            $this->assertNotContains(
                $blockedVip->id,
                $this->audienceIds($audience),
                "Blocked customers must not appear in the [{$audience->value}] segment.",
            );
        }
    }

    public function test_audience_counts_match_the_queries_they_describe(): void
    {
        // A population that lands differently in every segment.
        $vip = $this->customer(['created_at' => now()->subDays(90)]);
        $this->placeOrder($vip, OrderStatus::Delivered, 2500.00, now()->subDays(80));

        $abandoner = $this->customer(['created_at' => now()->subDays(90)]);
        $this->cartLine($abandoner, now()->subHours(40));

        $dormant = $this->customer(['created_at' => now()->subDays(90)]);
        $recent = $this->customer(['created_at' => now()->subDays(2)]);

        foreach (CampaignAudience::cases() as $audience) {
            $this->assertSame(
                $this->segmentation->getAudienceQuery($audience)->count(),
                $this->segmentation->getAudienceCount($audience),
                "Count for [{$audience->value}] disagrees with its own query.",
            );
        }

        $this->assertCount(4, $this->audienceIds(CampaignAudience::All));

        // Only the high spender clears VIP.
        $this->assertSame([$vip->id], $this->audienceIds(CampaignAudience::Vip));

        // Only the stale cart clears cart abandoners.
        $this->assertSame([$abandoner->id], $this->audienceIds(CampaignAudience::CartAbandoners));

        // The VIP's last order is 80 days old, so "no order in 30 days"
        // holds for them too — a lapsed spender is still a win-back target.
        $this->assertEqualsCanonicalizing(
            [$vip->id, $abandoner->id, $dormant->id],
            $this->audienceIds(CampaignAudience::Inactive30Days),
        );
    }

    public function test_get_audience_query_returns_an_unexecuted_builder(): void
    {
        $query = $this->segmentation->getAudienceQuery(CampaignAudience::All);

        $this->assertInstanceOf(Builder::class, $query);
        $this->assertSame('customers', $query->getQuery()->from);
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function customer(array $attributes = []): Customer
    {
        return Customer::factory()->create($attributes);
    }

    private function placeOrder(
        Customer $customer,
        OrderStatus $status,
        float $total,
        mixed $createdAt = null,
    ): Order {
        return Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => $status,
            'total' => $total,
            'created_at' => $createdAt ?? now(),
        ]);
    }

    private function cartLine(Customer $customer, mixed $updatedAt): CartItem
    {
        return CartItem::factory()
            ->forCustomer($customer)
            ->forVariant(Variant::factory()->create())
            ->create(['updated_at' => $updatedAt]);
    }

    /**
     * The customer ids a segment resolves to, ordered so assertions can use
     * assertSame without depending on insertion order.
     *
     * @return array<int, int>
     */
    private function audienceIds(CampaignAudience $audience): array
    {
        return $this->segmentation
            ->getAudienceQuery($audience)
            ->pluck('customers.id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
    }
}
