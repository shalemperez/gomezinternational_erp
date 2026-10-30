<?php

namespace Database\Seeders;

use App\Models\UnitOfMeasure;
use Illuminate\Database\Seeder;

class UnitOfMeasureSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['UND' => 'Unidad', 'CAJA' => 'Caja', 'KG' => 'Kilogramo', 'L' => 'Litro', 'PAQ' => 'Paquete'] as $code => $name) {
            UnitOfMeasure::query()->updateOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]);
        }
    }
}
