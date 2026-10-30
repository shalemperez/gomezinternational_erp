<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX price_lists_single_default ON price_lists (is_default) WHERE is_default');
        DB::statement('CREATE UNIQUE INDEX warehouses_single_default ON warehouses (is_default) WHERE is_default');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS price_lists_single_default');
        DB::statement('DROP INDEX IF EXISTS warehouses_single_default');
    }
};
