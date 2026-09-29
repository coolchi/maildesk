<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('name')->nullable();
            $table->string('direct_key')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('last_message_preview')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'direct_key']);
            $table->index(['organization_id', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
