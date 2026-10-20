<?php

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

class PageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $pages = [
            ['slug' => 'about', 'title' => 'About Us', 'title_ar' => 'من نحن'],
            ['slug' => 'contact', 'title' => 'Contact Us', 'title_ar' => 'تواصل معنا'],
            ['slug' => 'shipping', 'title' => 'Shipping Policy', 'title_ar' => 'سياسة الشحن'],
            ['slug' => 'returns', 'title' => 'Returns Policy', 'title_ar' => 'سياسة الإرجاع'],
            ['slug' => 'exchange', 'title' => 'Exchange Policy', 'title_ar' => 'سياسة الاستبدال'],
        ];

        foreach ($pages as $page) {
            Page::firstOrCreate(['slug' => $page['slug']], $page + [
                'content' => null,
                'content_ar' => null,
                'is_active' => true,
            ]);
        }
    }
}
