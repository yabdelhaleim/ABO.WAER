<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AdminSeeder::class,
            CatalogSeeder::class,
            CouponSeeder::class,
            CampaignSeeder::class,
            // The storefront reads the shipping zones and the 27
            // governorates straight from the database, so they have to
            // be in place before the checkout is usable.
            ShippingSeeder::class,
        ]);
    }
}
