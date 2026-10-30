<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->decimal('prior_paid_amount', 14, 2)->default(0)->after('paid_amount'));

        DB::statement("UPDATE invoices SET prior_paid_amount = paid_amount WHERE kind = 'historical'");
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('prior_paid_amount'));
    }
};
