<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            if (! Schema::hasColumn('templates', 'design_key')) {
                $table->string('design_key', 40)->nullable()->after('html');
            }
        });

        Schema::table('broadcasts', function (Blueprint $table) {
            if (! Schema::hasColumn('broadcasts', 'design_key')) {
                $table->string('design_key', 40)->nullable()->after('html');
            }
        });
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            if (Schema::hasColumn('templates', 'design_key')) {
                $table->dropColumn('design_key');
            }
        });

        Schema::table('broadcasts', function (Blueprint $table) {
            if (Schema::hasColumn('broadcasts', 'design_key')) {
                $table->dropColumn('design_key');
            }
        });
    }
};
