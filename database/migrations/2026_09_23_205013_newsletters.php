<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('subject');
            $table->longText('content'); // sanitized rich-text HTML
            $table->string('audience', 50); // all, no_kyc, no_transactions, etc.
            $table->string('status', 20)->default('draft'); // draft, queued, sending, sent, failed
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by')->references('id')->on('admins')->nullOnDelete();
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletters');
    }
};