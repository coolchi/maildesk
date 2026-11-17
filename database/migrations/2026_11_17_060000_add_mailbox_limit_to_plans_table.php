<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How many email addresses (mailboxes) a workspace on this plan may create.
     * Null means unlimited, so existing plans keep working until an admin sets a number.
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('mailbox_limit')->nullable()->after('seats');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('mailbox_limit');
        });
    }
};
