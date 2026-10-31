<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecf_annulment_ranges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ecf_annulment_id')->nullable()->constrained('ecf_annulments')->nullOnDelete();
            $table->string('ncf_type', 3);
            $table->string('environment', 5);
            $table->unsignedBigInteger('range_from');
            $table->unsignedBigInteger('range_to');
            $table->unsignedInteger('quantity');
            $table->string('reason', 100);
            $table->foreignId('ecf_document_id')->nullable()->constrained('ecf_documents')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecf_annulment_ranges');
    }
};
