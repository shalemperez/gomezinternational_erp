<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batch_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->jsonb('data');
            $table->jsonb('errors')->nullable();
            $table->boolean('is_valid');
            $table->string('action', 10)->nullable();
            $table->index(['import_batch_id', 'is_valid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batch_rows');
    }
};
