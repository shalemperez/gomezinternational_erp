<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecf_documents', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 100);
            $table->unsignedBigInteger('document_id');
            $table->string('ncf_type', 3);
            $table->string('encf', 13);
            $table->string('environment', 5);
            $table->string('provider', 10);
            $table->string('flow', 5);
            $table->string('status', 25)->index();
            $table->string('track_id', 100)->nullable();
            $table->string('security_code', 6)->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('xml_path', 255)->nullable();
            $table->string('rfce_xml_path', 255)->nullable();
            $table->smallInteger('dgii_code')->nullable();
            $table->jsonb('dgii_messages')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->string('commercial_status', 10)->nullable();
            $table->string('commercial_reason', 250)->nullable();
            $table->timestamp('commercial_at')->nullable();
            $table->text('qr_url')->nullable();
            $table->foreignId('ncf_sequence_id')->nullable()->constrained('ncf_sequences')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['encf', 'environment']);
            $table->unique(['document_type', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecf_documents');
    }
};
