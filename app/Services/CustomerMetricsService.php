<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Collection;

/**
 * The one place that says what a customer's numbers mean.
 *
 * Three figures used to be computed in three different ways, and none
 * of them agreed:
 *
 *  - the dashboard counted every order that was not cancelled as a
 *    sale, so a returned order still counted as money taken;
 *  - the campaign segment counted only delivered orders, and only
 *    those, for the VIP rule;
 *  - the customers screen had a rule of its own in JavaScript, which
 *    no other part of the application could see.
 *
 * This service is the single definition every one of them now reads:
 *
 *  - "spend" is the sum of the totals of the DELIVERED orders. An
 *    order that was cancelled or returned never happened, and an order
 *    that is still pending has not been paid for yet.
 *  - the "orders" figure counts every order the customer placed,
 *    whatever became of it — that is the number that says how often
 *    somebody comes back.
 *  - VIP is at least one delivered order, and either more than
 *    VIP_MINIMUM_TOTAL_SPEND delivered, or at least
 *    VIP_MINIMUM_ORDER_COUNT delivered orders. This is the rule the
 *    campaigns have always used, kept exactly as it was so the two
 *    can never disagree.
 *
 * The dashboard keeps its own, wider definition of a sale — that is
 * what a day of trading looks like — and says so on the screen. The
 * two figures answer different questions and are meant to differ.
 */
class CustomerMetricsService
{
    /**
     * Delivered spend, in EGP, that qualifies a customer as VIP on its
     * own.
     */
    public const float VIP_MINIMUM_TOTAL_SPEND = 2000.00;

    /**
     * Delivered-order count that qualifies a customer as VIP on its
     * own.
     */
    public const int VIP_MINIMUM_ORDER_COUNT = 3;

    /**
     * How long an account may be empty before it counts as new.
     */
    public const int NEW_CUSTOMER_DAYS = 30;

    public const string SEGMENT_VIP = 'vip';

    public const string SEGMENT_NEW = 'new';

    public const string SEGMENT_RETURNING = 'returning';

    public const string SEGMENT_NO_ORDERS = 'noorders';

    public const string SEGMENT_BLOCKED = 'blocked';

    /**
     * The segments, in the order the screen shows their tabs.
     *
     * @return array<int, string>
     */
    public static function segments(): array
    {
        return [
            self::SEGMENT_NEW,
            self::SEGMENT_RETURNING,
            self::SEGMENT_VIP,
            self::SEGMENT_NO_ORDERS,
            self::SEGMENT_BLOCKED,
        ];
    }

    /**
     * The label of a segment.
     */
    public static function segmentLabel(string $segment): string
    {
        return match ($segment) {
            self::SEGMENT_VIP => 'VIP',
            self::SEGMENT_NEW => 'New',
            self::SEGMENT_RETURNING => 'Returning',
            self::SEGMENT_NO_ORDERS => 'No orders',
            self::SEGMENT_BLOCKED => 'Blocked',
            default => ucfirst($segment),
        };
    }

    /**
     * The aggregates of every customer who has ordered, in one query.
     *
     * @return Collection<int, array<string, mixed>> keyed by customer id
     */
    public function aggregates(): Collection
    {
        $rows = Order::query()
            ->select('customer_id')
            ->selectRaw('COUNT(*) AS orders_count')
            ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) AS delivered_count', [
                OrderStatus::Delivered->value,
            ])
            ->selectRaw('SUM(CASE WHEN status = ? THEN total ELSE 0 END) AS delivered_spent', [
                OrderStatus::Delivered->value,
            ])
            ->selectRaw('MAX(created_at) AS last_order_at')
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->get();

        return $rows->mapWithKeys(fn ($row): array => [
            (int) $row->customer_id => $this->shapeAggregate($row),
        ]);
    }

    /**
     * The aggregates of one customer.
     *
     * A customer who has never ordered still has a row here — with
     * zeroes — so the callers do not have to special-case them.
     *
     * @return array<string, mixed>
     */
    public function aggregateFor(int $customerId): array
    {
        $row = Order::query()
            ->where('customer_id', $customerId)
            ->selectRaw('COUNT(*) AS orders_count')
            ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) AS delivered_count', [
                OrderStatus::Delivered->value,
            ])
            ->selectRaw('SUM(CASE WHEN status = ? THEN total ELSE 0 END) AS delivered_spent', [
                OrderStatus::Delivered->value,
            ])
            ->selectRaw('MAX(created_at) AS last_order_at')
            ->first();

        return $row === null
            ? $this->emptyAggregate()
            : $this->shapeAggregate($row);
    }

    /**
     * Whether the numbers make this customer a VIP.
     *
     * @param  array<string, mixed>  $aggregates
     */
    public function isVip(array $aggregates): bool
    {
        if ((int) ($aggregates['delivered_count'] ?? 0) < 1) {
            return false;
        }

        return (float) ($aggregates['delivered_spent'] ?? 0) > self::VIP_MINIMUM_TOTAL_SPEND
            || (int) ($aggregates['delivered_count'] ?? 0) >= self::VIP_MINIMUM_ORDER_COUNT;
    }

    /**
     * The segment a customer belongs to.
     *
     * A blocked account is always shown as blocked: whatever else it
     * is, that is what the operator needs to see first.
     *
     * @param  array<string, mixed>  $aggregates
     */
    public function segmentFor(Customer $customer, array $aggregates): string
    {
        if ($customer->is_blocked) {
            return self::SEGMENT_BLOCKED;
        }

        if ($this->isVip($aggregates)) {
            return self::SEGMENT_VIP;
        }

        $orders = (int) ($aggregates['orders_count'] ?? 0);

        if ($orders === 0) {
            return self::SEGMENT_NO_ORDERS;
        }

        if ($orders === 1) {
            return self::SEGMENT_NEW;
        }

        return self::SEGMENT_RETURNING;
    }

    /**
     * How many customers sit in each segment.
     *
     * @param  Collection<int, array<string, mixed>>  $aggregates
     * @param  Collection<int, Customer>  $customers
     * @return array<string, int>
     */
    public function segmentCounts(Collection $customers, Collection $aggregates): array
    {
        $counts = array_fill_keys(self::segments(), 0);

        foreach ($customers as $customer) {
            $segment = $this->segmentFor($customer, $aggregates->get($customer->id, $this->emptyAggregate()));
            $counts[$segment] = ($counts[$segment] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * The figures the customers screen shows above the list.
     *
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<int, array<string, mixed>>  $aggregates
     * @return array<string, int|float>
     */
    public function stats(Collection $customers, Collection $aggregates): array
    {
        $total = $customers->count();
        $new = $customers
            ->filter(fn (Customer $customer): bool => $customer->created_at?->gte(now()->subDays(self::NEW_CUSTOMER_DAYS)))
            ->count();

        $counts = $this->segmentCounts($customers, $aggregates);

        $deliveredOrders = 0;
        $deliveredSpend = 0.0;

        foreach ($aggregates as $aggregate) {
            $deliveredOrders += (int) $aggregate['delivered_count'];
            $deliveredSpend += (float) $aggregate['delivered_spent'];
        }

        return [
            'total' => $total,
            'new' => $new,
            'returning' => $counts[self::SEGMENT_RETURNING] ?? 0,
            'returning_percentage' => $total > 0
                ? (int) round((($counts[self::SEGMENT_RETURNING] ?? 0) / $total) * 100)
                : 0,
            'average_order_value' => $deliveredOrders > 0
                ? (int) round($deliveredSpend / $deliveredOrders)
                : 0,
            'delivered_orders' => $deliveredOrders,
            'delivered_spend' => $deliveredSpend,
            'segments' => $counts,
        ];
    }

    /**
     * The zeroes a customer who has never ordered gets.
     *
     * @return array<string, mixed>
     */
    protected function emptyAggregate(): array
    {
        return [
            'orders_count' => 0,
            'delivered_count' => 0,
            'delivered_spent' => 0.0,
            'last_order_at' => null,
        ];
    }

    /**
     * Turn one grouped row into the array the callers work with.
     *
     * @return array<string, mixed>
     */
    protected function shapeAggregate(object $row): array
    {
        return [
            'orders_count' => (int) $row->orders_count,
            'delivered_count' => (int) $row->delivered_count,
            'delivered_spent' => (float) $row->delivered_spent,
            'last_order_at' => $row->last_order_at,
        ];
    }
}
