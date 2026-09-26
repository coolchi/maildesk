<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('api_keys', 'revoked_at')) {
            return;
        }

        Schema::table('api_keys', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('api_keys', 'revoked_at')) {
            Schema::table('api_keys', function (Blueprint $table) {
                $table->dropColumn('revoked_at');
            });
        }
    }
};
