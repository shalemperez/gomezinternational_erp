<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            CurrencySeeder::class,
            UnitOfMeasureSeeder::class,
            SuperAdminSeeder::class,
            WarehouseSeeder::class,
            DocumentSequenceSeeder::class,
        ]);
    }
}
