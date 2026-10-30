<?php

use App\Support\Documents\DocumentSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proformas', function (Blueprint $table) {
            $table->id();
            $table->string('number', 12)->unique();
            $table->string('status', 20)->index();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('salesperson_id')->constrained('salespeople')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 12, 6);
            $table->date('issue_date')->index();
            $table->date('valid_until');
            $table->foreignId('price_list_id')->nullable()->constrained('price_lists')->nullOnDelete();
            DocumentSchema::totals($table);
            $table->text('notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approval_note', 255)->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_note', 255)->nullable();
            $table->timestamp('invoiced_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proformas');
    }
};
