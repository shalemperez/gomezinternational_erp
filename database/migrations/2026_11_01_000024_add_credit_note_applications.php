<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->decimal('applied_amount', 14, 2)->default(0)->after('total_base');
            $table->decimal('unapplied_amount', 14, 2)->default(0)->after('applied_amount');
        });
        DB::statement('UPDATE credit_notes SET applied_amount = total');
        Schema::table('receipt_applications', function (Blueprint $table) {
            $table->string('source', 20)->change();
            $table->unsignedBigInteger('receipt_id')->nullable()->change();
            $table->foreignId('credit_note_id')->nullable()->after('receipt_id')->constrained('credit_notes')->restrictOnDelete();
            $table->date('applied_on')->nullable()->after('source');
        });
        DB::statement('UPDATE receipt_applications ra SET applied_on = r.receipt_date FROM receipts r WHERE r.id = ra.receipt_id');
        Schema::table('receipt_applications', function (Blueprint $table) {
            $table->date('applied_on')->nullable(false)->change();
            $table->index(['credit_note_id']);
        });
    }

    public function down(): void
    {
        Schema::table('receipt_applications', function (Blueprint $table) {
            $table->dropIndex(['credit_note_id']);
            $table->dropConstrainedForeignId('credit_note_id');
            $table->dropColumn('applied_on');
            $table->unsignedBigInteger('receipt_id')->nullable(false)->change();
            $table->string('source', 10)->change();
        });
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropColumn(['applied_amount', 'unapplied_amount']);
        });
    }
};
