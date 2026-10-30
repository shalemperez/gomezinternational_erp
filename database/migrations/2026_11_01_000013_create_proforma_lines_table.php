<?php

use App\Support\Documents\DocumentSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proforma_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proforma_id')->constrained('proformas')->cascadeOnDelete();
            DocumentSchema::lineAmounts($table);
            $table->decimal('invoiced_quantity', 14, 4)->default(0);
            $table->timestamps();
            $table->unique(['proforma_id', 'line_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proforma_lines');
    }
};
