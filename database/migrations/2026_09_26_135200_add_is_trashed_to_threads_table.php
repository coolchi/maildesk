<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->boolean('is_trashed')->default(false)->after('is_archived');
            $table->index(['organization_id', 'is_trashed']);
        });
    }

    public function down(): void
    {
        Schema::table('threads', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'is_trashed']);
            $table->dropColumn('is_trashed');
        });
    }
};
