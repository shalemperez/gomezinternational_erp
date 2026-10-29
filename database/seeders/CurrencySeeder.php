<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        Currency::query()->updateOrCreate(['code' => 'DOP'], ['name' => 'Peso dominicano', 'symbol' => 'RD$', 'decimal_places' => 2, 'is_base' => true, 'is_active' => true]);
        Currency::query()->updateOrCreate(['code' => 'USD'], ['name' => 'Dólar estadounidense', 'symbol' => 'US$', 'decimal_places' => 2, 'is_base' => false, 'is_active' => true]);
    }
}
