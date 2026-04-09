<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unified transactions table.
     *
     * type field covers: sell | buy | swap | withdraw | convert | deposit | transfer | referral_bonus
     * entry_type: credit | debit
     *
     * For SELL: user sends crypto → receives NGN
     *   crypto_asset, crypto_amount, crypto_network, deposit_address, breet_order_id, rate, spread_amount
     *
     * For WITHDRAW: user withdraws NGN → bank account
     *   bank_account_id, bank_name, account_number, account_name
     *
     * For SWAP: user swaps crypto A → crypto B
     *   crypto_asset (from), swap_to_asset, crypto_amount, to_amount
     *
     * For CONVERT: NGN → another currency (future)
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('wallet_id')->constrained()->cascadeOnDelete();

            // Transaction identity
            $table->string('reference')->unique(); // internal ref e.g. TXN-2024-XXXXXXXX
            $table->string('session_id')->nullable(); // user's session ID at time of transaction
            $table->ipAddress('ip_address')->nullable(); // IP at time of transaction
            $table->string('user_agent')->nullable();

            // Type fields
            $table->enum('type', [
                'sell',       // sell crypto for NGN
                'buy',        // buy crypto with NGN (future)
                'swap',       // crypto to crypto
                'withdraw',   // NGN to bank account
                'convert',    // NGN to other fiat (future)
                'deposit',    // add NGN to wallet
                'transfer',   // user to user
                'referral_bonus',
                'fee',
            ]);
            $table->enum('entry_type', ['credit', 'debit']);

            // Amounts
            $table->string('currency', 10)->default('NGN'); // fiat currency
            $table->decimal('amount', 20, 2); // NGN amount
            $table->decimal('fee', 20, 2)->default(0.00); // our fee
            $table->decimal('breet_fee', 20, 2)->default(0.00); // breet's 0.5% fee
            $table->decimal('net_amount', 20, 2); // amount after fees
            $table->decimal('spread_amount', 20, 2)->default(0.00); // our spread profit
            $table->decimal('balance_before', 20, 2)->default(0.00);
            $table->decimal('balance_after', 20, 2)->default(0.00);

            // Crypto fields (for sell/buy/swap)
            $table->string('crypto_asset')->nullable(); // BTC, USDT, SOL etc.
            $table->string('crypto_network')->nullable(); // TRC20, ERC20, BEP20 etc.
            $table->decimal('crypto_amount', 30, 10)->nullable();
            $table->string('swap_to_asset')->nullable(); // for swap type
            $table->decimal('swap_to_amount', 30, 10)->nullable();
            $table->decimal('rate', 20, 2)->nullable(); // NGN rate used
            $table->string('deposit_address')->nullable(); // crypto deposit address
            $table->string('crypto_tx_hash')->nullable(); // blockchain tx hash

            // Breet API fields
            $table->string('breet_order_id')->nullable();
            $table->string('breet_reference')->nullable();
            $table->json('breet_response')->nullable(); // raw breet API response

            // Withdrawal/bank fields
            $table->foreignUuid('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('bank_name')->nullable();
            $table->string('bank_code')->nullable();
            $table->string('account_number')->nullable();
            $table->string('account_name')->nullable();
            $table->string('bank_transfer_reference')->nullable(); // payout ref

            // Transfer fields (user to user)
            $table->uuid('transfer_to_user_id')->nullable();

            // Status
            $table->enum('status', [
                'pending',
                'processing',
                'awaiting_crypto',  // waiting for user to send crypto
                'confirming',       // on-chain confirmations
                'converting',       // breet converting
                'completed',
                'failed',
                'cancelled',
                'refunded',
                'expired',
            ])->default('pending');

            $table->string('failure_reason')->nullable();
            $table->text('notes')->nullable(); // admin notes

            // Rate lock
            $table->timestamp('rate_locked_at')->nullable();
            $table->timestamp('rate_expires_at')->nullable(); // 60s lock
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            // Metadata
            $table->json('metadata')->nullable(); // flexible extra data
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['user_id', 'type']);
            $table->index(['user_id', 'status']);
            $table->index(['type', 'status']);
            $table->index('breet_order_id');
            $table->index('reference');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};