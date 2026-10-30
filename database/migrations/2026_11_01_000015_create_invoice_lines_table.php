<?php

use App\Support\Documents\DocumentSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            DocumentSchema::lineAmounts($table);
            $table->decimal('dispatched_quantity', 14, 4)->default(0);
            $table->decimal('returned_quantity', 14, 4)->default(0);
            $table->decimal('credited_quantity', 14, 4)->default(0);
            $table->decimal('dispatched_cost', 14, 2)->default(0);
            $table->timestamps();
            $table->unique(['invoice_id', 'line_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
