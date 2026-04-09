<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // OTP codes for NIN verification, 2FA, transaction confirmation etc.
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('code', 6);
            $table->string('phone')->nullable(); // phone code was sent to
            $table->enum('purpose', [
                'nin_verification',
                'two_factor_auth',
                'transaction_confirmation',
                'phone_verification',
                'withdrawal_confirmation',
            ]);
            $table->boolean('used')->default(false);
            $table->timestamp('used_at')->nullable();
            $table->integer('attempts')->default(0); // wrong attempt counter
            $table->ipAddress('ip_address')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'purpose', 'used']);
        });

        // Security audit log
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable(); // nullable for unauthenticated attempts
            $table->string('event'); // login, logout, password_change, withdrawal, etc.
            $table->string('auditable_type')->nullable(); // model class
            $table->uuid('auditable_id')->nullable(); // model ID
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('session_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'event']);
            $table->index('created_at');
        });

        // Webhook logs from Breet
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source')->default('breet');
            $table->string('event_type'); // breet event type
            $table->string('breet_order_id')->nullable();
            $table->json('payload'); // raw webhook payload
            $table->enum('status', ['pending', 'processed', 'failed'])->default('pending');
            $table->string('failure_reason')->nullable();
            $table->integer('retry_count')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['breet_order_id', 'status']);
        });

        // Notifications
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('webhook_logs');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('otp_codes');
    }
};