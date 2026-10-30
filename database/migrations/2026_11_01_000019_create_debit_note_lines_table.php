<?php

use App\Support\Documents\DocumentSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debit_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debit_note_id')->constrained('debit_notes')->cascadeOnDelete();
            $table->foreignId('invoice_line_id')->nullable()->constrained('invoice_lines')->restrictOnDelete();
            DocumentSchema::lineAmounts($table);
            $table->timestamps();
            $table->unique(['debit_note_id', 'line_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debit_note_lines');
    }
};
