<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecf_annulment_ranges', function (Blueprint $table) {
            $table->foreignId('ncf_sequence_id')->nullable()->constrained('ncf_sequences')->nullOnDelete();
            $table->unsignedBigInteger('original_range_to')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ecf_annulment_ranges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ncf_sequence_id');
            $table->dropColumn('original_range_to');
        });
    }
};
