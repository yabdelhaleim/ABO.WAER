<?php

namespace App\Services;

use App\Enums\CampaignAudience;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * Turns a campaign's audience choice into a real Eloquent query.
 *
 * Admin\CampaignController previews a segment's size before the campaign is
 * sent (admin.campaigns.*audienceCount), and App\Jobs\SendCampaignJob walks
 * the same query in chunks to deliver the message. Both get their result from
 * here so the number the admin is shown and the set of customers that are
 * actually messaged can never drift apart.
 *
 * Every segment starts from baseQuery(): blocked accounts are skipped, and
 * only customers carrying a deliverable email address are eligible — a
 * campaign with nowhere to send is not worth counting.
 *
 * Every method returns an unexecuted Builder. Callers decide whether to
 * count() it, chunkById() it or page it; nothing is read from the database
 * until they do.
 */
class CustomerSegmentationService
{
    /**
     * Lifetime spend, in EGP, that qualifies a customer as VIP on its own.
     */
    public const float VIP_MINIMUM_TOTAL_SPEND = 2000.00;

    /**
     * Delivered-order count that qualifies a customer as VIP on its own.
     */
    public const int VIP_MINIMUM_ORDER_COUNT = 3;

    /**
     * How long a cart line may sit untouched before the customer counts as
     * having abandoned it.
     */
    public const int CART_ABANDONMENT_HOURS = 24;

    /**
     * Days without an order (and without a recent signup) that make a
     * customer a win-back target.
     */
    public const int INACTIVE_DAYS = 30;

    /**
     * The unaudienceed customer pool every segment narrows down from.
     *
     * @return Builder<Customer>
     */
    public function baseQuery(): Builder
    {
        return Customer::query()
            ->where('is_blocked', false)
            ->whereNotNull('email')
            ->where('email', '<>', '');
    }

    /**
     * The customers a campaign's audience resolves to, as a lazy query.
     *
     * @return Builder<Customer>
     */
    public function getAudienceQuery(CampaignAudience $audience): Builder
    {
        return match ($audience) {
            CampaignAudience::All => $this->baseQuery(),
            CampaignAudience::Vip => $this->vipQuery(),
            CampaignAudience::CartAbandoners => $this->cartAbandonersQuery(),
            CampaignAudience::Inactive30Days => $this->inactiveQuery(),
        };
    }

    /**
     * How many customers a campaign's audience resolves to — the "≈ N
     * customers" figure the admin sees next to the Send button.
     */
    public function getAudienceCount(CampaignAudience $audience): int
    {
        return (int) $this->getAudienceQuery($audience)->count();
    }

    /**
     * VIP: at least one delivered order, and either they have spent more
     * than VIP_MINIMUM_TOTAL_SPEND in total or they have placed at least
     * VIP_MINIMUM_ORDER_COUNT delivered orders.
     *
     * The per-customer aggregate is its own query rather than a whereHas
     * closure: whereHas seeds the subquery with `select *` before the
     * closure is applied, so a selectRaw() inside it would append to that
     * star instead of replacing it and SQLite would aggregate over the
     * wrong column set.
     *
     * The spend and order-count figures live in the HAVING clause rather
     * than the select list: the subquery is used with whereIn, and SQLite
     * (unlike MySQL) rejects an IN sub-select that returns more than one
     * column. GROUP BY only emits a row for a customer who has delivered
     * orders at all, so the OR cannot admit somebody with none.
     *
     * The spend threshold is CAST in SQL rather than bound directly. Laravel
     * binds PHP floats as strings, and in SQLite every TEXT value sorts above
     * every numeric one — so `SUM(total) > ?` with a string-bound 2000.00
     * would be false even for a customer who spent 2,050. Casting the
     * placeholder pins the comparison to decimal on every driver and keeps
     * the threshold safe to express in piastres.
     *
     * @return Builder<Customer>
     */
    protected function vipQuery(): Builder
    {
        $vipCustomerIds = Order::query()
            ->select('customer_id')
            ->where('status', OrderStatus::Delivered->value)
            ->groupBy('customer_id')
            ->havingRaw(
                '(SUM(total) > CAST(? AS DECIMAL(10, 2)) OR COUNT(*) >= ?)',
                [self::VIP_MINIMUM_TOTAL_SPEND, self::VIP_MINIMUM_ORDER_COUNT]
            );

        return $this->baseQuery()->whereIn('customers.id', $vipCustomerIds);
    }

    /**
     * Cart abandoners: a cart line left untouched for CART_ABANDONMENT_HOURS
     * with no order placed since that window opened — i.e. they walked away
     * from the basket instead of checking out.
     *
     * The "did they come back?" test uses the same 24-hour cutoff rather than
     * correlating each line's own updated_at against each order's created_at.
     * That correlation is expressible as a correlated subquery, but the
     * fixed window is the shape checkout recovery is normally built on and
     * it keeps this a pair of index-friendly EXISTS/NOT EXISTS predicates.
     *
     * @return Builder<Customer>
     */
    protected function cartAbandonersQuery(): Builder
    {
        $abandonedBefore = now()->subHours(self::CART_ABANDONMENT_HOURS);

        return $this->baseQuery()
            ->whereHas('cartItems', fn (Builder $items): Builder => $items
                ->where('updated_at', '<=', $abandonedBefore))
            ->whereDoesntHave('orders', fn (Builder $orders): Builder => $orders
                ->where('created_at', '>', $abandonedBefore));
    }

    /**
     * Inactive: signed up more than INACTIVE_DAYS ago and has not ordered
     * inside that same window. The age floor keeps brand-new accounts out of
     * a win-back campaign they never had the chance to leave.
     *
     * @return Builder<Customer>
     */
    protected function inactiveQuery(): Builder
    {
        return $this->baseQuery()
            ->where('created_at', '<=', now()->subDays(self::INACTIVE_DAYS))
            ->whereDoesntHave('orders', fn (Builder $orders): Builder => $orders
                ->where('created_at', '>', now()->subDays(self::INACTIVE_DAYS)));
    }
}
