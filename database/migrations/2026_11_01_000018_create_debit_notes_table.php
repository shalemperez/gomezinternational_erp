<?php

use App\Support\Documents\DocumentSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('number', 12)->unique();
            $table->string('status', 20)->default('issued')->index();
            $table->string('fiscal_mode', 10);
            $table->string('ncf', 11)->unique();
            $table->string('ncf_type', 3);
            $table->date('ncf_expires_on')->nullable();
            $table->foreignId('ncf_sequence_id')->nullable()->constrained('ncf_sequences')->nullOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->string('modified_ncf', 11)->nullable();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('salesperson_id')->constrained('salespeople')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 12, 6);
            $table->date('issue_date')->index();
            $table->string('reason', 20);
            DocumentSchema::totals($table);
            $table->text('notes')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debit_notes');
    }
};
