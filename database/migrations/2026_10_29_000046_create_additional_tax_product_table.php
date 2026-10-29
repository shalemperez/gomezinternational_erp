<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('additional_tax_product', function (Blueprint $table) {
            $table->foreignId('additional_tax_id')->constrained('additional_taxes')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->primary(['additional_tax_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('additional_tax_product');
    }
};
