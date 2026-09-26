<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->timestamp('trashed_at')->nullable()->after('is_trashed');
            $table->index(['is_trashed', 'trashed_at']);
        });

        DB::table('threads')
            ->where('is_trashed', true)
            ->whereNull('trashed_at')
            ->update(['trashed_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->dropIndex(['is_trashed', 'trashed_at']);
            $table->dropColumn('trashed_at');
        });
    }
};
