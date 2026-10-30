<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->string('ncf', 13)->nullable()->change());

        foreach (['credit_notes', 'debit_notes'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('ncf', 13)->change();
                $table->string('modified_ncf', 13)->nullable()->change();
            });
        }

        Schema::table('ncf_sequences', function (Blueprint $table) {
            $table->unsignedBigInteger('range_from')->change();
            $table->unsignedBigInteger('range_to')->change();
            $table->unsignedBigInteger('next_number')->change();
            $table->string('environment', 5)->default('prod')->after('ncf_type');
            $table->dropUnique(['ncf_type', 'range_from']);
            $table->unique(['ncf_type', 'environment', 'range_from']);
        });
    }

    public function down(): void
    {
        Schema::table('ncf_sequences', function (Blueprint $table) {
            $table->dropUnique(['ncf_type', 'environment', 'range_from']);
            $table->unique(['ncf_type', 'range_from']);
            $table->dropColumn('environment');
            $table->unsignedInteger('range_from')->change();
            $table->unsignedInteger('range_to')->change();
            $table->unsignedInteger('next_number')->change();
        });

        foreach (['credit_notes', 'debit_notes'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('ncf', 11)->change();
                $table->string('modified_ncf', 11)->nullable()->change();
            });
        }

        Schema::table('invoices', fn (Blueprint $table) => $table->string('ncf', 11)->nullable()->change());
    }
};
