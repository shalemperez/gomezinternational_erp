<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sent_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('mailable_type', 120);
            $table->unsignedBigInteger('mailable_id');
            $table->index(['mailable_type', 'mailable_id']);
            $table->string('template_key', 40)->nullable();
            $table->jsonb('to');
            $table->jsonb('cc')->nullable();
            $table->string('subject', 200);
            $table->text('body_html');
            $table->jsonb('attachments')->nullable();
            $table->string('gmail_message_id', 120)->nullable();
            $table->string('gmail_thread_id', 120)->nullable();
            $table->string('status', 10);
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_emails');
    }
};
