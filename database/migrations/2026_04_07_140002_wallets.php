<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('currency', 10)->default('NGN'); // NGN, USD etc.
            $table->decimal('balance', 20, 2)->default(0.00);
            $table->decimal('locked_balance', 20, 2)->default(0.00); // funds locked in pending transactions
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'currency']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};