<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ncf_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('ncf_type', 3)->index();
            $table->unsignedInteger('range_from');
            $table->unsignedInteger('range_to');
            $table->unsignedInteger('next_number');
            $table->date('expires_on')->nullable();
            $table->string('authorization_number', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('exhausted_at')->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();
            $table->unique(['ncf_type', 'range_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ncf_sequences');
    }
};
