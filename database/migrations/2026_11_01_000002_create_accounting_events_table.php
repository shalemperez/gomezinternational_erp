<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40)->index();
            $table->string('document_type', 120);
            $table->unsignedBigInteger('document_id');
            $table->index(['document_type', 'document_id']);
            $table->date('occurred_on')->index();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 12, 6);
            $table->jsonb('payload');
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_events');
    }
};
