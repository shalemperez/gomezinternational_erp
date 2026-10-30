<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('initial_stock_loads', function (Blueprint $table) {
            $table->id();
            $table->string('note', 255);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('lines_count');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('initial_stock_loads');
    }
};
