<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Governorate;
use App\Models\Order;
use App\Services\CustomerMetricsService;
use Illuminate\Support\Collection;

/**
 * The customers screen, read from the database.
 *
 * The screen used to be fed by a generated list of forty invented
 * customers: made-up names, invented order counts, a five-entry list of
 * governorates and a segment rule that lived in the browser's
 * JavaScript and nowhere else. Everything here replaces that with the
 * rows that are really in the tables.
 *
 * The figures come from CustomerMetricsService, which is the one place
 * that says what "spend" and "VIP" mean — the campaign segment and
 * this screen therefore always agree.
 */
class CustomerService
{
    public function __construct(protected CustomerMetricsService $metrics) {}

    /**
     * Every customer, with the address book and the recent orders
     * loaded alongside.
     *
     * @return Collection<int, Customer>
     */
    public function listForAdmin(): Collection
    {
        return Customer::query()
            ->with(['addresses.governorate', 'orders' => fn ($query) => $query->latest('id')->limit(5)])
            ->orderBy('name')
            ->get();
    }

    /**
     * The governorates the filter offers.
     *
     * They come from the shipping catalogue, so a governorate that is
     * not being shipped to is not offered either.
     *
     * @return Collection<int, string>
     */
    public function governorates(): Collection
    {
        return Governorate::query()
            ->orderBy('name')
            ->pluck('name');
    }

    /**
     * The whole payload the screen hydrates from.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $customers = $this->listForAdmin();
        $aggregates = $this->metrics->aggregates();

        $rows = $customers->map(fn (Customer $customer): array => $this->presentCustomer(
            $customer,
            $aggregates->get($customer->id, $this->metrics->aggregateFor($customer->id)),
        ))->values();

        return [
            'customers' => $rows->all(),
            'stats' => $this->metrics->stats($customers, $aggregates),
            'governorates' => $this->governorates()->all(),
        ];
    }

    /**
     * The figures above the list.
     *
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<int, array<string, mixed>>  $aggregates
     * @return array<string, mixed>
     */
    public function stats(Collection $customers, Collection $aggregates): array
    {
        return $this->metrics->stats($customers, $aggregates);
    }

    /**
     * The segment one customer belongs to right now.
     *
     * Blocking a customer moves them into the blocked segment, so
     * whoever blocks one asks the service what it thinks of them
     * afterwards rather than working it out in the browser.
     */
    public function segmentFor(Customer $customer): string
    {
        return $this->metrics->segmentFor(
            $customer,
            $this->metrics->aggregateFor((int) $customer->id),
        );
    }

    /**
     * Shape one customer for the screen.
     *
     * @param  array<string, mixed>  $aggregates
     * @return array<string, mixed>
     */
    public function presentCustomer(Customer $customer, array $aggregates): array
    {
        $addresses = $customer->addresses;

        return [
            'id' => (string) $customer->id,
            'name' => (string) $customer->name,
            'initials' => $this->initials((string) $customer->name),
            'phone' => (string) ($customer->phone ?? ''),
            'email' => (string) ($customer->email ?? ''),
            'promotions' => (bool) $customer->accepts_promotions,
            'governorate' => (string) ($addresses->first()?->governorate?->name ?? ''),
            'ordersCount' => (int) ($aggregates['orders_count'] ?? 0),
            'deliveredCount' => (int) ($aggregates['delivered_count'] ?? 0),
            // The spend is the delivered one — the same figure the VIP
            // rule and the campaign segment use.
            'totalSpent' => (float) ($aggregates['delivered_spent'] ?? 0),
            'signupDate' => $customer->created_at?->format('Y-m-d') ?? '',
            'lastOrder' => $aggregates['last_order_at'] !== null
                ? substr((string) $aggregates['last_order_at'], 0, 10)
                : null,
            'blocked' => (bool) $customer->is_blocked,
            'blockReason' => $customer->block_reason,
            // The segment is decided on the server, by the one service
            // that owns the rule. The browser reads it; it never
            // derives it.
            'segment' => $this->metrics->segmentFor($customer, $aggregates),
            'addresses' => $addresses->map(fn (CustomerAddress $address): array => [
                'label' => $address->is_default ? 'Default' : 'Address',
                'name' => (string) $address->name,
                'phone' => (string) $address->phone,
                'area' => (string) $address->area,
                'address' => (string) $address->address,
                'governorate' => (string) ($address->governorate?->name ?? ''),
                'isDefault' => (bool) $address->is_default,
            ])->values()->all(),
            'recentOrders' => $customer->orders->map(fn (Order $order): array => [
                'number' => (string) $order->order_number,
                'date' => $order->created_at?->format('Y-m-d') ?? '',
                'total' => (float) $order->total,
                'status' => $this->statusKey($order),
            ])->values()->all(),
            'tags' => $customer->tags ?? [],
            'note' => (string) ($customer->note ?? ''),
        ];
    }

    /**
     * The bucket the front end draws a status badge from.
     *
     * The front end ships six buckets while the enum has seven cases:
     * `pending` is what the screen calls "new", and `confirmed` has no
     * bucket of its own so it folds into processing.
     */
    public function statusKey(Order $order): string
    {
        $status = $order->status instanceof OrderStatus ? $order->status : OrderStatus::Pending;

        return match ($status) {
            OrderStatus::Pending => 'new',
            OrderStatus::Confirmed, OrderStatus::Processing => 'processing',
            OrderStatus::Shipped => 'shipped',
            OrderStatus::Delivered => 'delivered',
            OrderStatus::Cancelled => 'cancelled',
            OrderStatus::Returned => 'returned',
        };
    }

    /**
     * The initials the avatar shows.
     */
    protected function initials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return strtoupper(implode('', array_map(
            fn (string $word): string => mb_substr($word, 0, 1),
            array_slice($words, 0, 2),
        )));
    }
}
