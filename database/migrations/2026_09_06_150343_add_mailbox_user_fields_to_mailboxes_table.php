<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->string('role')->default('staff')->after('type');
            $table->string('status')->default('active')->after('role');
            $table->boolean('inbox')->default(true)->after('status');
            $table->boolean('transactional')->default(false)->after('inbox');
            $table->boolean('marketing')->default(false)->after('transactional');
            $table->unsignedInteger('send_limit')->nullable()->after('marketing');
        });
    }

    public function down(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn([
                'role',
                'status',
                'inbox',
                'transactional',
                'marketing',
                'send_limit',
            ]);
        });
    }
};
