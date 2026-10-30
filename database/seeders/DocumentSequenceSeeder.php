<?php

namespace Database\Seeders;

use App\Enums\DocumentKind;
use App\Models\DocumentSequence;
use Illuminate\Database\Seeder;

class DocumentSequenceSeeder extends Seeder
{
    public function run(): void
    {
        foreach (DocumentKind::cases() as $kind) {
            DocumentSequence::query()->firstOrCreate(['kind' => $kind->value], ['prefix' => $kind->prefix(), 'next_number' => 1]);
        }
    }
}
