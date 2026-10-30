<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('gmail_refresh_token')->nullable();
            $table->string('gmail_email', 255)->nullable();
            $table->timestamp('gmail_connected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['gmail_refresh_token', 'gmail_email', 'gmail_connected_at']);
        });
    }
};
