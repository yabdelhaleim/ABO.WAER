<?php

namespace App\Models;

use Database\Factories\NewsletterSubscriberFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One address on the store's newsletter list.
 *
 * The form on the home page used to show "YOU'RE IN ✓" and keep
 * nothing — the shopper was told they had subscribed and the store
 * never learned their address. This row is the subscription.
 *
 * The address is normalised on the way in (trimmed and lower-cased), so
 * "Same@Example.com" and "same@example.com" are one subscriber and not
 * two — the email column is unique, and a uniqueness that is decided by
 * the case of the address is not a uniqueness at all.
 */
class NewsletterSubscriber extends Model
{
    /** @use HasFactory<NewsletterSubscriberFactory> */
    use HasFactory;

    /**
     * The state a subscriber is in.
     */
    public const STATUS_SUBSCRIBED = 'subscribed';

    public const STATUS_UNSUBSCRIBED = 'unsubscribed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'subscribed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    /**
     * Normalise the address before it is stored.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => mb_strtolower(trim($value)),
        );
    }

    /**
     * Whether this subscriber is currently on the list.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_SUBSCRIBED;
    }

    /**
     * Scope: the subscribers the store can actually write to.
     *
     * @param  Builder<NewsletterSubscriber>  $query
     * @return Builder<NewsletterSubscriber>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SUBSCRIBED);
    }
}
