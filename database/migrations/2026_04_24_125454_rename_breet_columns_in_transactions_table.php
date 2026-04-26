<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->renameColumn('breet_order_id',  'provider_order_id');
            $table->renameColumn('breet_reference', 'provider_reference');
            $table->renameColumn('breet_fee',       'provider_fee');
            $table->renameColumn('breet_response',  'provider_response');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->renameColumn('provider_order_id',  'breet_order_id');
            $table->renameColumn('provider_reference', 'breet_reference');
            $table->renameColumn('provider_fee',       'breet_fee');
            $table->renameColumn('provider_response',  'breet_response');
        });
    }
};