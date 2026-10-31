<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecf_transmissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ecf_document_id')->constrained('ecf_documents')->cascadeOnDelete();
            $table->string('kind', 15);
            $table->string('environment', 5);
            $table->smallInteger('http_status')->nullable();
            $table->string('request_path', 255)->nullable();
            $table->jsonb('response')->nullable();
            $table->string('response_path', 255)->nullable();
            $table->boolean('succeeded')->default(false);
            $table->string('error', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['ecf_document_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecf_transmissions');
    }
};
