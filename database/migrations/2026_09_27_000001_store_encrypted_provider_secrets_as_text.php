<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * encrypted:array casts write a ciphertext string. MySQL JSON columns
     * reject that payload, so provider saves 500 on insert.
     */
    public function up(): void
    {
        Schema::table('mail_providers', function (Blueprint $table) {
            $table->longText('config')->nullable()->change();
        });

        Schema::table('provider_configs', function (Blueprint $table) {
            $table->longText('credentials')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('mail_providers', function (Blueprint $table) {
            $table->json('config')->nullable()->change();
        });

        Schema::table('provider_configs', function (Blueprint $table) {
            $table->json('credentials')->nullable()->change();
        });
    }
};
