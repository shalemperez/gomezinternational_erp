<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('code', 50);
            $table->date('expires_on')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lots');
    }
};
