<?php

namespace Database\Factories;

use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportBatch>
 */
class ImportBatchFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'clients', 'file_path' => 'imports/test.xlsx', 'original_name' => 'test.xlsx', 'status' => 'previewed',
            'total_rows' => 0, 'valid_rows' => 0, 'invalid_rows' => 0, 'meta' => null, 'user_id' => User::factory(),
        ];
    }
}
