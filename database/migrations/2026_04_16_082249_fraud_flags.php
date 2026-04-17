<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fraud_flags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('flagged_by'); // admin UUID
            $table->uuid('user_id')->nullable();
            $table->uuid('transaction_id')->nullable();
            $table->enum('type', [
                'suspicious_transaction',
                'unusual_volume',
                'multiple_failed_pins',
                'account_takeover_suspected',
                'duplicate_nin',
                'blacklisted_address',
                'manual_flag',
            ]);
            $table->enum('severity', ['low', 'medium', 'high', 'critical']);
            $table->text('reason');
            $table->text('notes')->nullable();
            $table->enum('status', ['open', 'investigating', 'resolved', 'false_positive'])->default('open');
            $table->uuid('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['transaction_id', 'status']);
            $table->index(['type', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fraud_flags');
    }
};