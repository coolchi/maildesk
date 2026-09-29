<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->timestamp('pinned_at')->nullable()->after('last_read_at');
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('kind')->default('text')->after('body');
        });

        Schema::create('chat_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_message_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->string('content_type');
            $table->unsignedInteger('size')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('disk')->default('local');
            $table->string('path');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_attachments');
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->dropColumn('pinned_at');
        });
    }
};
