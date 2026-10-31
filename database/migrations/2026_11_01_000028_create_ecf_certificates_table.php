<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecf_certificates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('subject', 255);
            $table->string('issuer', 255);
            $table->string('serial_number', 64);
            $table->string('rnc', 11)->nullable();
            $table->string('fingerprint_sha256', 64)->unique();
            $table->timestamp('valid_from');
            $table->timestamp('valid_to');
            $table->text('p12_encrypted');
            $table->text('password_encrypted');
            $table->boolean('is_active')->default(false);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecf_certificates');
    }
};
