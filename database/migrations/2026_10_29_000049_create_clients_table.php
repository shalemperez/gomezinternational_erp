<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('business_name', 200);
            $table->string('trade_name', 200)->nullable();
            $table->string('tax_id_type', 10);
            $table->string('tax_id', 11)->nullable()->unique();
            $table->string('taxpayer_type', 30);
            $table->string('address', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 255)->nullable();
            $table->foreignId('salesperson_id')->nullable()->constrained('salespeople')->restrictOnDelete();
            $table->unsignedSmallInteger('credit_days')->default(0);
            $table->decimal('credit_limit', 14, 2)->default(0);
            $table->foreignId('price_list_id')->nullable()->constrained('price_lists')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('tax_id_validation_overridden')->default(false);
            $table->string('tax_id_override_reason', 255)->nullable();
            $table->foreignId('tax_id_override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('tax_id_override_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
