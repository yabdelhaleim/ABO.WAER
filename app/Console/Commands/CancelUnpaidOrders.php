<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\StoreSetting;
use App\Services\Admin\OrderService as AdminOrderService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Cancel the orders nobody paid for.
 *
 * The settings screen carries an "Auto-cancel unpaid orders" field and
 * a promise — "Unpaid orders are cancelled automatically after this
 * many hours" — that nothing kept. The hours were stored and read by
 * nobody: an order that sat unpaid held its stock until an admin
 * noticed and cancelled it by hand.
 *
 * This command keeps that promise. It finds the orders that are still
 * pending, still unpaid and older than the window the store set, and
 * hands each one to the same cancel flow the admin's button uses — the
 * stock goes back, the status changes and the history records who did
 * it ("System").
 *
 * It is scheduled (routes/console.php) and can be run by hand; the
 * --dry-run flag shows what it would do without touching anything.
 */
#[Signature('orders:cancel-unpaid {--dry-run : List the orders that would be cancelled without cancelling them}')]
#[Description('Cancel the unpaid orders that have been waiting longer than the store\'s auto-cancel window')]
class CancelUnpaidOrders extends Command
{
    /**
     * The window a store gets when it has not set one — the same
     * default the settings screen starts with, so the field is never a
     * switch that does nothing.
     */
    public const DEFAULT_HOURS = 24;

    /**
     * Execute the console command.
     */
    public function handle(AdminOrderService $orders): int
    {
        $hours = (int) StoreSetting::get('orders.autoCancelHours', self::DEFAULT_HOURS);

        if ($hours < 1) {
            $this->warn('The auto-cancel window is not a number of hours — nothing was cancelled.');

            return self::SUCCESS;
        }

        $cutoff = now()->subHours($hours);

        // Only the orders nobody has touched: still pending, still
        // unpaid and older than the window. An order the store has
        // already confirmed or packed is a live order, not an unpaid
        // one, and is none of this command's business.
        $candidates = Order::query()
            ->where('status', OrderStatus::Pending->value)
            ->where('payment_status', PaymentStatus::Pending->value)
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No unpaid order is older than '.$hours.' hours.');

            return self::SUCCESS;
        }

        $cancelled = 0;

        foreach ($candidates as $order) {
            if ($this->option('dry-run')) {
                $this->line(sprintf(
                    'Would cancel %s — placed %s, %s EGP.',
                    $order->order_number,
                    $order->created_at->toDayDateTimeString(),
                    number_format((float) $order->total, 2),
                ));

                continue;
            }

            $orders->cancelOrder(
                order: $order,
                reasonId: null,
                customNote: 'Unpaid — cancelled automatically after '.$hours.' hours',
                changedById: null,
                changedByType: 'System',
            );

            $cancelled++;
        }

        if ($this->option('dry-run')) {
            $this->info(count($candidates).' order(s) would be cancelled. Nothing was changed.');

            return self::SUCCESS;
        }

        $this->info($cancelled.' unpaid order(s) cancelled.');

        return self::SUCCESS;
    }
}
