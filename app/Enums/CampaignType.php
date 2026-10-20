<?php

namespace App\Enums;

enum CampaignType: string
{
    case Email = 'email';
    case Push = 'push';
    case Sms = 'sms'; // معمارياً جاهز، غير مفعّل عبر القنوات افتراضياً

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Push => 'Push Notification',
            self::Sms => 'SMS',
        };
    }
}
