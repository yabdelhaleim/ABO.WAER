<?php

namespace App\Enums;

enum CouponType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';
    case Shipping = 'shipping';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage Discount (%)',
            self::Fixed => 'Fixed Amount (EGP)',
            self::Shipping => 'Free Shipping',
        };
    }
}
