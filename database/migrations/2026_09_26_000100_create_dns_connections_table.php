<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider'); // cloudflare
            $table->text('credentials'); // encrypted
            $table->string('zone_id');
            $table->string('zone_name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_connections');
    }
};
