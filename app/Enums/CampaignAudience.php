<?php

namespace App\Enums;

enum CampaignAudience: string
{
    case All = 'all';
    case Vip = 'vip';
    case CartAbandoners = 'cart_abandoners';
    case Inactive30Days = 'inactive_30_days';

    public function label(): string
    {
        return match ($this) {
            self::All => 'All customers',
            self::Vip => 'VIP customers',
            self::CartAbandoners => 'Cart abandoners',
            self::Inactive30Days => 'Inactive 30+ days',
        };
    }

    /**
     * The line under each audience card in the admin slide-over. Spells out
     * what CustomerSegmentationService actually filters on, so the admin is
     * choosing against the real criteria rather than a marketing label.
     */
    public function description(): string
    {
        return match ($this) {
            self::All => 'Every customer we can reach by email',
            self::Vip => 'Spent over 2,000 EGP, or 3+ delivered orders',
            self::CartAbandoners => 'Left something in their cart 24h+ ago',
            self::Inactive30Days => 'Signed up 30+ days ago, no recent orders',
        };
    }
}
