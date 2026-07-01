<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add Breet wallet tracking columns to the transactions table.
 *
 * WHY TWO COLUMNS?
 * ─────────────────────────────────────────────────────────────────────────────
 * Breet generates two separate IDs per wallet:
 *
 *   breet_wallet_id  = MongoDB ObjectId (e.g. "6932dc98c2ceccdea367388a")
 *                      Returned as data.id from POST /trades/sell/assets/{id}/generate-address
 *                      Used for: GET /trades/wallets/{id}
 *                                PUT /trades/wallets/{id}/bank
 *                                PUT /trades/wallets/{id}/auto-settlement
 *
 *   breet_vault_id   = Numeric string (e.g. "139628")
 *                      Returned as data.vaultId from the same endpoint
 *                      Also present as `vaultId` in ALL trade webhook payloads
 *                      Used for: matching incoming webhooks to transactions
 *
 * The existing `provider_order_id` was intended to hold both — which is
 * impossible. These two dedicated columns fix that cleanly.
 *
 * EXISTING COLUMNS (unchanged, kept for reference):
 *   provider_order_id    — was incorrectly used; now DEPRECATED for sell orders.
 *                          Kept for backwards compat / other transaction types.
 *   provider_reference   — Breet transaction ID (`id` in webhook payload).
 *                          Set on first webhook received. Used for polling.
 *   deposit_address      — The crypto address shown to the user. Unchanged.
 * ─────────────────────────────────────────────────────────────────────────────
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // MongoDB wallet ObjectId — for API calls (GET/PUT /trades/wallets/{id})
            $table->string('breet_wallet_id')->nullable()->after('deposit_address')
                  ->comment('Breet MongoDB wallet ObjectId. Used for GET/PUT /trades/wallets/{id}');

            // Numeric vault ID — for webhook matching (vaultId field in all webhooks)
            $table->string('breet_vault_id')->nullable()->after('breet_wallet_id')
                  ->comment('Breet numeric vaultId. Matched against webhook payload vaultId field.');

            // Index breet_vault_id — this is the hot path on every incoming webhook
            $table->index(['breet_vault_id', 'type', 'status'], 'idx_breet_vault_sell_status');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('idx_breet_vault_sell_status');
            $table->dropColumn(['breet_wallet_id', 'breet_vault_id']);
        });
    }
};