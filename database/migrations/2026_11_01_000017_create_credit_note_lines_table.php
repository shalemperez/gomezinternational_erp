<?php

use App\Support\Documents\DocumentSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_note_id')->constrained('credit_notes')->cascadeOnDelete();
            $table->foreignId('invoice_line_id')->nullable()->constrained('invoice_lines')->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('lots')->restrictOnDelete();
            DocumentSchema::lineAmounts($table);
            $table->timestamps();
            $table->unique(['credit_note_id', 'line_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_lines');
    }
};
