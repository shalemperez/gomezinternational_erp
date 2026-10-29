<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('additional_taxes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->string('calculation_type', 32);
            $table->decimal('rate', 7, 4)->nullable();
            $table->decimal('amount', 14, 4)->nullable();
            $table->boolean('included_in_itbis_base')->default(true);
            $table->string('dgii_code', 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('additional_taxes');
    }
};
