<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Magic-link tokens (only a SHA-256 hash is stored)
        Schema::create('support_access_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email')->index();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference')->unique();           // TKT-XXXXXXXX
            $table->uuid('user_id')->nullable()->index();    // null if email has no PayYigi account
            $table->string('email')->index();                // lowercase
            $table->string('subject')->nullable();
            $table->enum('status', ['open', 'escalated', 'closed'])->default('open');
            $table->text('escalation_reason')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->enum('role', ['user', 'ai', 'system']);
            $table->text('body');
            $table->timestamps();

            $table->index(['support_ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('support_access_tokens');
    }
};
