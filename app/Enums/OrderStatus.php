<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending / قيد الانتظار',
            self::Confirmed => 'Confirmed / تم التأكيد',
            self::Processing => 'Processing / جاري التجهيز',
            self::Shipped => 'Shipped / جاري التوصيل',
            self::Delivered => 'Delivered / تم الاستلام',
            self::Cancelled => 'Cancelled / ملغي',
            self::Returned => 'Returned / مسترجع',
        };
    }
}
