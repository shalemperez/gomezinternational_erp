<?php

use App\Support\Documents\DocumentSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 12)->nullable()->unique();
            $table->string('kind', 12)->default('sale')->index();
            $table->string('status', 20)->index();
            $table->string('fiscal_mode', 10)->nullable();
            $table->string('ncf', 11)->nullable()->unique();
            $table->string('ncf_type', 3)->nullable();
            $table->date('ncf_expires_on')->nullable();
            $table->foreignId('ncf_sequence_id')->nullable()->constrained('ncf_sequences')->nullOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('client_name', 200)->nullable();
            $table->string('client_tax_id', 11)->nullable();
            $table->string('client_tax_id_type', 10)->nullable();
            $table->string('client_address', 255)->nullable();
            $table->foreignId('salesperson_id')->constrained('salespeople')->restrictOnDelete();
            $table->foreignId('proforma_id')->nullable()->constrained('proformas')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 12, 6);
            $table->date('issue_date')->index();
            $table->date('due_date')->index();
            $table->string('payment_terms', 10)->default('credit');
            $table->unsignedSmallInteger('credit_days')->default(0);
            DocumentSchema::totals($table);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->decimal('credited_amount', 14, 2)->default(0);
            $table->decimal('debited_amount', 14, 2)->default(0);
            $table->decimal('balance', 14, 2)->default(0);
            $table->string('dispatch_status', 10)->default('none');
            $table->text('notes')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
