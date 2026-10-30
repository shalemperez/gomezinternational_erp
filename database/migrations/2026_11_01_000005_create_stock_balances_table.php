<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('lots')->restrictOnDelete();
            $table->decimal('quantity', 14, 4)->default(0);
            $table->decimal('reserved_quantity', 14, 4)->default(0);
            $table->decimal('average_cost', 14, 4)->default(0);
            $table->timestamps();
            $table->index(['product_id', 'warehouse_id']);
        });

        DB::statement('CREATE UNIQUE INDEX stock_balances_product_warehouse_lot_unique ON stock_balances (product_id, warehouse_id, COALESCE(lot_id, 0))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS stock_balances_product_warehouse_lot_unique');
        Schema::dropIfExists('stock_balances');
    }
};
