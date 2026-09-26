<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: per-request IP / user agent on audited impersonation actions and
 * a denial message for refused "log in as" attempts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('impersonation_actions', function (Blueprint $table) {
            $table->string('ip', 45)->nullable()->after('blocked');
            $table->text('user_agent')->nullable()->after('ip');
        });

        Schema::table('impersonation_logs', function (Blueprint $table) {
            $table->string('denied_reason', 500)->nullable()->after('end_reason');
        });
    }

    public function down(): void
    {
        Schema::table('impersonation_actions', function (Blueprint $table) {
            $table->dropColumn(['ip', 'user_agent']);
        });

        Schema::table('impersonation_logs', function (Blueprint $table) {
            $table->dropColumn('denied_reason');
        });
    }
};
