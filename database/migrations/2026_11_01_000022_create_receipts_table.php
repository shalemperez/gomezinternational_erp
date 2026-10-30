<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 12)->unique();
            $table->string('status', 20)->index();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('salesperson_id')->nullable()->constrained('salespeople')->nullOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 12, 6);
            $table->date('receipt_date')->index();
            $table->string('payment_method', 12);
            $table->foreignId('financial_account_id')->nullable()->constrained('financial_accounts')->restrictOnDelete();
            $table->string('reference', 100)->nullable();
            $table->decimal('amount_received', 14, 2);
            $table->decimal('retention_itbis', 14, 2)->default(0);
            $table->decimal('retention_isr', 14, 2)->default(0);
            $table->decimal('applied_total', 14, 2)->default(0);
            $table->decimal('unapplied_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
