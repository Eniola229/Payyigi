<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the existing morphs columns
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('tokenable_id');
            $table->dropColumn('tokenable_type');
        });

        // Re-add them with proper UUID support
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->uuid('tokenable_id');
            $table->string('tokenable_type');
            $table->index(['tokenable_id', 'tokenable_type']);
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('tokenable_id');
            $table->dropColumn('tokenable_type');
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->morphs('tokenable');
        });
    }
};