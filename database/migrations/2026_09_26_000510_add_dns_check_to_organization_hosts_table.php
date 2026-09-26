<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_hosts', function (Blueprint $table) {
            if (! Schema::hasColumn('organization_hosts', 'dns_check')) {
                $table->json('dns_check')->nullable()->after('is_custom');
            }
            if (! Schema::hasColumn('organization_hosts', 'dns_checked_at')) {
                $table->timestamp('dns_checked_at')->nullable()->after('dns_check');
            }
        });
    }

    public function down(): void
    {
        Schema::table('organization_hosts', function (Blueprint $table) {
            foreach (['dns_checked_at', 'dns_check'] as $column) {
                if (Schema::hasColumn('organization_hosts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
