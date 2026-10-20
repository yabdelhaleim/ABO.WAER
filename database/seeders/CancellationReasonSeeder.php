<?php

namespace Database\Seeders;

use App\Models\CancellationReason;
use Illuminate\Database\Seeder;

class CancellationReasonSeeder extends Seeder
{
    public function run(): void
    {
        $reasons = [
            ['reason' => 'Changed my mind', 'reason_ar' => 'غيّرت رأيي'],
            ['reason' => 'Ordered by mistake', 'reason_ar' => 'تم الطلب عن طريق الخطأ'],
            ['reason' => 'Delivery time is too long', 'reason_ar' => 'مدة التوصيل طويلة جداً'],
            ['reason' => 'Found a better price elsewhere', 'reason_ar' => 'وجدت سعراً أفضل في مكان آخر'],
            ['reason' => 'Wanted to change the size or color', 'reason_ar' => 'أريد تغيير المقاس أو اللون'],
            ['reason' => 'Duplicate order', 'reason_ar' => 'طلب مكرر'],
        ];

        foreach ($reasons as $index => $item) {
            CancellationReason::firstOrCreate(
                ['reason' => $item['reason']],
                [
                    'reason_ar' => $item['reason_ar'],
                    'is_active' => true,
                    'sort_order' => $index + 1,
                ],
            );
        }
    }
}
