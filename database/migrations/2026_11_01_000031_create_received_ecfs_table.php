<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('received_ecfs', function (Blueprint $table) {
            $table->id();
            $table->string('rnc_emisor', 11);
            $table->string('razon_social_emisor', 150)->nullable();
            $table->string('ncf_type', 3);
            $table->string('encf', 13);
            $table->string('rnc_comprador', 11)->nullable();
            $table->date('fecha_emision')->nullable();
            $table->decimal('monto_total', 14, 2)->default(0);
            $table->decimal('total_itbis', 14, 2)->default(0);
            $table->string('xml_path', 255);
            $table->string('arecf_path', 255)->nullable();
            $table->smallInteger('arecf_status');
            $table->smallInteger('arecf_reason_code')->nullable();
            $table->timestamp('received_at');
            $table->string('sender_ip', 45)->nullable();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('commercial_status', 10)->default('pending');
            $table->string('commercial_reason', 250)->nullable();
            $table->string('acecf_path', 255)->nullable();
            $table->timestamp('commercial_sent_at')->nullable();
            $table->foreignId('commercial_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['rnc_emisor', 'encf']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('received_ecfs');
    }
};
