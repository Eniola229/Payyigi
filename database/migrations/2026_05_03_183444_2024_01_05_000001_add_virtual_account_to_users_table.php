<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('virtual_account_number')->nullable()->after('nin_phone');
            $table->string('virtual_account_bank')->nullable()->after('virtual_account_number');
            $table->string('virtual_account_bank_code')->nullable()->after('virtual_account_bank');
            $table->string('virtual_account_name')->nullable()->after('virtual_account_bank_code');
            $table->string('virtual_account_reference')->nullable()->after('virtual_account_name');
            $table->boolean('virtual_account_active')->default(false)->after('virtual_account_reference');
            $table->timestamp('virtual_account_created_at')->nullable()->after('virtual_account_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'virtual_account_number',
                'virtual_account_bank',
                'virtual_account_bank_code',
                'virtual_account_name',
                'virtual_account_reference',
                'virtual_account_active',
                'virtual_account_created_at',
            ]);
        });
    }
};