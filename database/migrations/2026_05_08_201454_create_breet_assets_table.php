<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('breet_assets', function (Blueprint $table) {
            $table->string('id')->primary();          // Breet's MongoDB ID — used in API calls
            $table->string('symbol');                 // BTC, USDT, SOL etc.
            $table->string('name');                   // "Solana", "Bitcoin" etc.
            $table->string('identifier');             // SOL_TEST, BTC, USDT_TRC20 etc.
            $table->string('network');                // Tron, Ethereum, Solana, Bitcoin etc.
            $table->string('type');                   // BASE_ASSET | ERC20 | TRC20 etc.
            $table->string('icon')->nullable();
            $table->string('tx_link')->nullable();
            $table->decimal('minimum', 18, 8)->default(0);
            $table->decimal('flag_fee_usd', 10, 2)->default(0);
            $table->boolean('is_account_based')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['symbol', 'network']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('breet_assets');
    }
};