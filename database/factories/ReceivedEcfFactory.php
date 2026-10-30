<?php

namespace Database\Factories;

use App\Models\ReceivedEcf;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReceivedEcf>
 */
class ReceivedEcfFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $encf = 'E31'.str_pad((string) fake()->unique()->numberBetween(1, 9999999999), 10, '0', STR_PAD_LEFT);

        return [
            'rnc_emisor' => '101010101',
            'razon_social_emisor' => 'Proveedor Prueba SRL',
            'ncf_type' => 'E31',
            'encf' => $encf,
            'rnc_comprador' => '131880738',
            'fecha_emision' => now()->toDateString(),
            'monto_total' => '1180.00',
            'total_itbis' => '180.00',
            'xml_path' => 'ecf/received/'.now()->year.'/101010101'.$encf.'.xml',
            'arecf_path' => null,
            'arecf_status' => 0,
            'arecf_reason_code' => null,
            'received_at' => now(),
            'commercial_status' => 'pending',
        ];
    }
}
