<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 200);
            $table->char('country_code', 2)->default('DO');
            $table->string('tax_id', 11)->nullable()->unique();
            $table->string('foreign_tax_id', 50)->nullable();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->unsignedSmallInteger('credit_days')->default(0);
            $table->string('address', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 255)->nullable();
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
        Schema::dropIfExists('suppliers');
    }
};
