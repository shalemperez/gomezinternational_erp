<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 50)->unique();
            $table->string('barcode', 50)->nullable()->index();
            $table->string('name', 200);
            $table->foreignId('brand_id')->nullable()->constrained('brands')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->restrictOnDelete();
            $table->foreignId('unit_of_measure_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->unsignedInteger('units_per_box')->default(1);
            $table->decimal('weight_kg', 10, 4)->nullable();
            $table->decimal('volume_liters', 10, 4)->nullable();
            $table->decimal('alcohol_degree', 5, 2)->nullable();
            $table->decimal('itbis_rate', 7, 2)->default(18);
            $table->boolean('tracks_lots')->default(false);
            $table->string('tariff_code', 20)->nullable();
            $table->decimal('minimum_stock', 14, 4)->default(0);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
