<?php

namespace App\Notifications;

use App\Models\Variant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Database-channel notification for "a variant is running out".
 *
 * The bell's "Low stock" switch said the store would be told when a
 * product drops to or below the low-stock threshold, and nothing told
 * it. This notification is that promise, kept: the order that took the
 * last units off the shelf is what triggers it, and the threshold it
 * is measured against is the store's own inventory setting.
 *
 * The payload mirrors the shape public/admin/js/admin.js expects, so the
 * JS can render the row without asking the server for anything.
 */
class LowStockAdminNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Variant $variant,
        public int $threshold,
    ) {}

    /**
     * The bell reads the database channel.
     *
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        $product = $this->variant->product;
        $label = $product?->name ?? 'A product';
        $variant = trim(implode(' / ', array_filter([
            $this->variant->color?->name,
            $this->variant->size?->name,
        ])));

        $title = 'Low stock: '.$label.($variant !== '' ? ' ('.$variant.')' : '');
        $title .= ' — '.$this->variant->quantity.' left';

        return [
            'id' => 'stock-'.$this->variant->id,
            'type' => 'stock',
            'tone' => 'coral',
            'title' => $title,
            'time' => now()->diffForHumans(),
            'href' => route('admin.products.index', ['search' => $this->variant->sku]),
            'order_id' => null,
        ];
    }

    /**
     * The database-channel payload — identical to toArray() so both
     * reads (database row + array cast) return the same shape.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(mixed $notifiable): array
    {
        return $this->toArray($notifiable);
    }
}
