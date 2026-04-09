<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('bank_name');
            $table->string('bank_code', 10); // Nigerian bank codes (CBN codes)
            $table->string('account_number', 10);
            $table->string('account_name');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('user_id');
            $table->unique(['user_id', 'account_number', 'bank_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};