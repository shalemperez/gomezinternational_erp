<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecf_annulments', function (Blueprint $table) {
            $table->id();
            $table->string('environment', 5);
            $table->string('status', 10)->default('pending');
            $table->string('xml_path', 255)->nullable();
            $table->jsonb('response')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecf_annulments');
    }
};
