<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill trashed_at from is_trashed for threads that were trashed
     * before trashed_at was added. Sets trashed_at to updated_at for
     * consistency with when the thread was last modified.
     */
    public function up(): void
    {
        DB::table('threads')
            ->where('is_trashed', true)
            ->whereNull('trashed_at')
            ->update(['trashed_at' => DB::raw('updated_at')]);
    }

    public function down(): void {}
};
