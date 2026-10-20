<?php

namespace Database\Seeders;

use App\Models\Governorate;
use App\Models\ShippingSetting;
use App\Models\ShippingZone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ShippingSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            ShippingSetting::set('free_shipping_enabled', '0');
            ShippingSetting::set('free_shipping_threshold', '1000');
            ShippingSetting::set('cod_fee', '15');
            ShippingSetting::set('default_fee', '50');
            ShippingSetting::set('note_en', 'Orders placed before 2 PM are dispatched the same day.');
            ShippingSetting::set('note_ar', 'الطلبات المسجلة قبل الساعة 2 ظهراً يتم شحنها في نفس اليوم.');

            $this->seedZone('Greater Cairo', 'القاهرة الكبرى', 45.00, 1, 2, 1, [
                ['Cairo', 'القاهرة'],
                ['Giza', 'الجيزة'],
                ['Qalyubia', 'القليوبية'],
            ]);

            $this->seedZone('Alexandria & Coast', 'الإسكندرية والساحل', 55.00, 2, 3, 2, [
                ['Alexandria', 'الإسكندرية'],
                ['Beheira', 'البحيرة'],
                ['Matrouh', 'مطروح'],
            ]);

            $this->seedZone('Delta & Canal', 'الدلتا ومدن القناة', 60.00, 2, 4, 3, [
                ['Dakahlia', 'الدقهلية'],
                ['Gharbia', 'الغربية'],
                ['Monufia', 'المنوفية'],
                ['Sharqia', 'الشرقية'],
                ['Kafr El Sheikh', 'كفر الشيخ'],
                ['Damietta', 'دمياط'],
                ['Port Said', 'بورسعيد'],
                ['Ismailia', 'الإسماعيلية'],
                ['Suez', 'السويس'],
            ]);

            $this->seedZone('Upper Egypt', 'صعيد مصر', 75.00, 3, 5, 4, [
                ['Faiyum', 'الفيوم'],
                ['Beni Suef', 'بني سويف'],
                ['Minya', 'المنيا'],
                ['Asyut', 'أسيوط'],
                ['Sohag', 'سوهاج'],
                ['Qena', 'قنا'],
                ['Luxor', 'الأقصر'],
                ['Aswan', 'أسوان'],
            ]);

            // Border and frontier areas — no cash on delivery.
            $this->seedZone('Remote Areas', 'المناطق النائية والحدودية', 95.00, 4, 7, 5, [
                ['Red Sea', 'البحر الأحمر'],
                ['New Valley', 'الوادي الجديد'],
                ['North Sinai', 'شمال سيناء'],
                ['South Sinai', 'جنوب سيناء'],
            ], allowsCod: false);
        });
    }

    /**
     * Create a shipping zone and attach its governorates.
     *
     * @param  list<array{0: string, 1: string}>  $governorates  [english name, arabic name] pairs
     */
    protected function seedZone(
        string $name,
        string $nameAr,
        float $fee,
        int $timeFrom,
        int $timeTo,
        int $sortOrder,
        array $governorates,
        bool $allowsCod = true,
    ): void {
        $zone = ShippingZone::firstOrCreate(['name' => $name], [
            'name_ar' => $nameAr,
            'fee' => $fee,
            'time_from' => $timeFrom,
            'time_to' => $timeTo,
            'is_active' => true,
            'sort_order' => $sortOrder,
        ]);

        foreach ($governorates as $position => [$governorateName, $governorateNameAr]) {
            Governorate::firstOrCreate(['name' => $governorateName], [
                'name_ar' => $governorateNameAr,
                'shipping_zone_id' => $zone->id,
                'allows_cod' => $allowsCod,
                'is_active' => true,
                'sort_order' => $position,
            ]);
        }
    }
}
