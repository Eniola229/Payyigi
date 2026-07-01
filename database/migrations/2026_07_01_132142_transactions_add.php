<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('provider_payout_id')->nullable()->after('provider_reference');
            $table->string('provider_payout_status')->nullable()->after('provider_payout_id');
            $table->timestamp('payout_completed_at')->nullable()->after('provider_payout_status');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['provider_payout_id', 'provider_payout_status', 'payout_completed_at']);
        });
    }
};