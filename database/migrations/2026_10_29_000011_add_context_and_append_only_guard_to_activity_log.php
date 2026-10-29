<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->foreignId('impersonator_id')->nullable()->after('causer_id')->constrained('users')->nullOnDelete();
            $table->string('ip', 45)->nullable()->after('properties');
            $table->string('user_agent', 500)->nullable()->after('ip');
            $table->string('request_id', 36)->nullable()->after('user_agent')->index();
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_activity_log_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'activity_log is append-only';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER activity_log_append_only
                BEFORE UPDATE OR DELETE ON activity_log
                FOR EACH ROW EXECUTE FUNCTION prevent_activity_log_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS activity_log_append_only ON activity_log; DROP FUNCTION IF EXISTS prevent_activity_log_mutation();');
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropConstrainedForeignId('impersonator_id');
            $table->dropColumn(['ip', 'user_agent', 'request_id']);
        });
    }
};
