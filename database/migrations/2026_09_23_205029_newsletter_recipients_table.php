<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_recipients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('newsletter_id');
            $table->uuid('user_id');
            $table->string('status', 20)->default('pending'); // pending, sent, failed
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->foreign('newsletter_id')->references('id')->on('newsletters')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['newsletter_id', 'user_id']);
            $table->index(['newsletter_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_recipients');
    }
};