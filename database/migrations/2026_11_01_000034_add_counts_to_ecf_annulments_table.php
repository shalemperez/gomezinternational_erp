<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ecf_annulments', function (Blueprint $table) {
            $table->unsignedInteger('ranges_count')->default(0);
            $table->unsignedBigInteger('quantity')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('ecf_annulments', function (Blueprint $table) {
            $table->dropColumn(['ranges_count', 'quantity']);
        });
    }
};
