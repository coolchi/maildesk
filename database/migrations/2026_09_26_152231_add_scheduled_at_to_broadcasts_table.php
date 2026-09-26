<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            if (! Schema::hasColumn('broadcasts', 'scheduled_at')) {
                $table->timestamp('scheduled_at')->nullable()->after('queued_at');
                $table->index(['status', 'scheduled_at']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            if (Schema::hasColumn('broadcasts', 'scheduled_at')) {
                $table->dropIndex(['status', 'scheduled_at']);
                $table->dropColumn('scheduled_at');
            }
        });
    }
};
